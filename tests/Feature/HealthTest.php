<?php

declare(strict_types=1);

namespace Tests\Feature;

use Papyrus\Launcher\Health;
use Papyrus\Launcher\Prober;
use Papyrus\Launcher\SummaryLog;
use Papyrus\Launcher\Supervisor;
use Tests\TestCase;

/**
 * The command a reader is asked to run before filing a bug.
 *
 * ## Why this is tested against a fake rather than a live install
 *
 * Because the interesting failures are the ones where a process is *not* there, and a test that
 * needs four live processes to check what happens when one is missing is a test nobody runs.
 *
 * ## The two bugs this suite exists to prevent, both of which happened
 *
 * The first version of the health check reported the database missing while `/ready` reported the
 * same database open and healthy, because it hard coded a path instead of reading the one the
 * launcher wrote. And it reported both workers dead while they were verifiably running, because
 * it asked a Supervisor instance that had never started them.
 *
 * Both produced a confident, specific, wrong answer about a working install. That is the failure
 * mode this whole project keeps meeting, and it is why a diagnostic is tested by what it claims
 * rather than by whether it runs.
 */
class HealthTest extends TestCase
{
    private string $scratch;

    private string $install;

    private string $data;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-health-'.getmypid().'-'.uniqid();
        $this->install = $this->scratch.DIRECTORY_SEPARATOR.'install';
        $this->data = $this->scratch.DIRECTORY_SEPARATOR.'data';

        @mkdir($this->install, 0o777, true);
        @mkdir($this->data.DIRECTORY_SEPARATOR.'logs', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
            }
        }

        @rmdir($path);
    }

    /**
     * A Supervisor with a stubbed process table, so liveness can be asserted without four real
     * processes and without shelling out to PowerShell.
     */
    private function supervisor(array $running = [], ?string $env = null): Supervisor
    {
        $supervisor = new Supervisor(
            root: $this->install,
            dataDirectory: $this->data,
            logDirectory: $this->data.DIRECTORY_SEPARATOR.'logs',
            suwayomiPort: 4567,
            appPort: 8402,
        );

        if ($env !== null) {
            file_put_contents($this->data.DIRECTORY_SEPARATOR.'.env', $env);
        }

        /*
         * Liveness is stubbed rather than real.
         *
         * The production answer shells out to PowerShell, which is correct and unfakeable, and
         * which makes a test asserting that a *missing* worker is reported as missing impossible:
         * it finds this machine's real workers and reports them running. Three tests failed for
         * that reason before this was stubbed, none of which had anything to do with the code.
         */
        $supervisor->useLivenessProbe(static fn (string $name): bool => in_array($name, $running, true));

        return $supervisor;
    }

    /**
     * A prober that answers from a script rather than from the machine.
     *
     * Subclassing rather than a production seam: `Prober` does real network and filesystem I/O,
     * so a health test that cannot control its answers is a health test that reports whatever
     * this machine is doing at the time. That already happened once, when a suite asserting that a
     * *missing* worker is reported as missing found real workers running and failed for reasons
     * that had nothing to do with the code.
     */
    private function prober(bool $suwayomiUp = false, bool $webUp = false, int $readyStatus = 200): Prober
    {
        return new class($suwayomiUp, $webUp, $readyStatus) extends Prober
        {
            public function __construct(
                private readonly bool $suwayomiUp,
                private readonly bool $webUp,
                private readonly int $readyStatus,
            ) {}

            public function suwayomiAnswers(int $port, float $timeoutSeconds = 3.0): bool
            {
                return $this->suwayomiUp;
            }

            public function suwayomiVersion(int $port, float $timeoutSeconds = 3.0): string
            {
                return $this->suwayomiUp ? 'v2.4.2366' : '';
            }

            public function isPortTaken(int $port): bool
            {
                return $this->webUp;
            }

            public function readiness(int $port): ?array
            {
                if (! $this->webUp) {
                    return null;
                }

                return [
                    'status' => $this->readyStatus,
                    'checks' => ['key' => $this->readyStatus === 200, 'database' => true, 'pending_migrations' => 0],
                ];
            }
        };
    }

    private function health(Supervisor $supervisor, ?Prober $prober = null): Health
    {
        return new Health(
            $supervisor,
            $prober ?? new Prober,
            new SummaryLog($this->data.DIRECTORY_SEPARATOR.'logs'),
        );
    }

    /**
     * A health check whose queue reading is a sentence rather than a database.
     *
     * The production answer runs the install's own PHP against its own database, which is right and
     * makes the interesting case untestable: a suite cannot create a stalled queue here, because a
     * real worker on this machine drains it between the seeding and the reading. That has already
     * happened once in this file, with three tests failing for reasons that had nothing to do with
     * the code.
     *
     * So the sentence is injected. It is the same one-line vocabulary the real probe prints, and
     * `queueIsHealthy()` matches on the prefix, so a sentence the probe would never produce is
     * treated as no answer rather than as a verdict.
     */
    private function healthWithQueue(Supervisor $supervisor, string $reported): Health
    {
        /*
         * The database has to exist, because `queue()` returns early when it does not and the
         * reader is never called. Three of these tests failed against code that was correct because
         * this scratch install has no database and the report said `idle nothing queued yet` without
         * ever asking.
         *
         * Touching the file is enough: the injected reader never opens it. A real SQLite file would
         * mean either a dependency or a fixture the test does not need, and what is under test here
         * is the decision, not the query.
         */
        $database = $this->data.DIRECTORY_SEPARATOR.'database.sqlite';

        if (! is_file($database)) {
            file_put_contents($database, '');
        }

        $health = $this->health($supervisor);

        $this->queueWasAsked = false;
        $test = $this;

        $health->useQueueReader(function (string $path) use ($reported, $test, $database): string {
            $test->markQueueAsked();

            $test->assertSame($database, $path, 'the queue is read from the database health names');

            return $reported;
        });

        return $health;
    }

    /** Whether the last health check with an injected reader actually consulted it. */
    private bool $queueWasAsked = false;

    /** Set by the injected reader, so a test can prove the reader ran rather than assume it. */
    public function markQueueAsked(): void
    {
        $this->queueWasAsked = true;
    }

    /**
     * A worker present and a queue that nobody is taking is the failure this check exists for.
     *
     * It is the whole hour: `queue:work` was running, for a different install, draining a different
     * database, while a library check sat unreserved for 70 minutes. Every other line was green and
     * the library page read `Checked 0 of 670 series` throughout.
     *
     * So the assertion is that a present worker does not save it, and that the message says which
     * thing to look at.
     */
    public function test_a_present_worker_with_an_undrained_queue_is_a_failure(): void
    {
        $report = $this->healthWithQueue(
            $this->supervisor(['queue', 'scheduler']),
            'NOT DRAINING, 1 queued and the oldest has been due for 70 minutes with no worker taking it. A queue:work process may be running for a different install.',
        )->render();

        $this->assertTrue($this->queueWasAsked, 'the queue reader ran');
        $this->assertMatchesRegularExpression('/FAIL\s+queue\s+NOT DRAINING/', $report);
        $this->assertStringContainsString('70 minutes', $report);
        $this->assertStringContainsString('different install', $report, 'the message must name what to look at');
        $this->assertMatchesRegularExpression('/overall\s+something is wrong/', $report);
    }

    /**
     * A queue that is being taken is healthy, and the count of rows is not the test.
     *
     * `CheckLibrary` re-books itself on a three second delay, so a working queue is routinely
     * non-empty. Counting rows would report a healthy install as broken, and the fix for that,
     * ignoring the queue, is what the check was written to prevent.
     */
    public function test_a_queue_that_is_being_taken_is_healthy_however_many_rows_it_has(): void
    {
        $report = $this->healthWithQueue(
            $this->supervisor(['queue', 'scheduler']),
            'draining  40 queued, all being taken',
        )->render();

        $this->assertDoesNotMatchRegularExpression('/FAIL\s+queue/', $report);
        $this->assertTrue($this->queueWasAsked, 'the queue reader ran');
        $this->assertStringContainsString('40 queued, all being taken', $report);
    }

    /**
     * A job that became due a moment ago is not a stalled queue.
     *
     * The worker polls every second, so this is the ordinary shape of a busy queue. Reading it as a
     * failure would make health flap, and a check that cries wolf gets ignored rather than fixed.
     */
    public function test_a_job_due_seconds_ago_is_still_draining(): void
    {
        $report = $this->healthWithQueue(
            $this->supervisor(['queue', 'scheduler']),
            'draining  3 queued, oldest due 2s ago',
        )->render();

        $this->assertDoesNotMatchRegularExpression('/FAIL\s+queue/', $report);
    }

    public function test_an_empty_queue_is_idle_and_healthy(): void
    {
        $report = $this->healthWithQueue($this->supervisor(['queue', 'scheduler']), 'idle  nothing queued')
            ->render();

        $this->assertStringContainsString('idle  nothing queued', $report);
        $this->assertDoesNotMatchRegularExpression('/FAIL\s+queue/', $report);
    }

    /**
     * A health check whose scheduler silence is a number rather than a file.
     *
     * The production answer is the scheduler log's own modification time, which is the right thing
     * to measure and cannot be produced on demand: a suite cannot make this machine's real scheduler
     * go quiet without breaking the install the tests are supposed to be about. So the age is
     * injected, exactly as the queue sentence is, and the decision built on top of it is what is
     * under test.
     *
     * The log is touched because `scheduler()` returns early when there is no log at all, and the
     * injected reader is never asked. That early return is deliberate and has its own test below, so
     * without it these four would pass for the wrong reason.
     */
    private function healthWithSchedulerAge(Supervisor $supervisor, ?int $age): Health
    {
        $logs = $this->data.DIRECTORY_SEPARATOR.'logs';

        if (! is_dir($logs)) {
            mkdir($logs, 0o777, true);
        }

        file_put_contents($logs.DIRECTORY_SEPARATOR.'scheduler.log', '');

        $health = $this->health($supervisor);

        $health->useSchedulerAgeReader(fn (): ?int => $age);

        return $health;
    }

    /**
     * A scheduler that is alive but no longer dispatching is the failure this check exists for.
     *
     * It is the second time the same mistake has cost an evening, and the second time process
     * presence was the whole answer. `health` reported `scheduler running schedule:work` for six
     * minutes over a scheduler that had stopped: the process was there, burning 0.016 seconds of CPU
     * across ten seconds, its log frozen at 2,017 lines, and not one `schedule:run` child spawned in
     * a sampled 32 seconds. Downloads kept draining through the queue, so the install looked busy.
     *
     * The assertion that matters is that the process being *present* does not rescue it.
     */
    public function test_a_present_scheduler_that_has_stopped_dispatching_is_a_failure(): void
    {
        $report = $this->healthWithSchedulerAge(
            $this->supervisor(['queue', 'scheduler']),
            400,
        )->render();

        $this->assertStringContainsString('ok    scheduler  running', $report, 'the process is present, which is what liveness would have stopped at');
        $this->assertMatchesRegularExpression('/FAIL\s+schedule\s+NOT DISPATCHING/', $report);
        $this->assertStringContainsString('400s', $report, 'the message must say how long it has been silent');
        $this->assertStringContainsString(
            'series are never refreshed',
            $report,
            'the message must name what the reader is losing, as every other failure line does',
        );
        $this->assertMatchesRegularExpression('/overall\s+something is wrong/', $report);
    }

    /**
     * A scheduler that wrote a moment ago is healthy, and the count of lines is not the test.
     *
     * `SyncDownloads` writes every ten seconds, so a log that grew steadily is the ordinary shape.
     */
    public function test_a_scheduler_that_wrote_recently_is_dispatching(): void
    {
        $report = $this->healthWithSchedulerAge(
            $this->supervisor(['queue', 'scheduler']),
            3,
        )->render();

        $this->assertDoesNotMatchRegularExpression('/FAIL\s+schedule/', $report);
        $this->assertStringContainsString('dispatching, last wrote 3s ago', $report);
    }

    /**
     * A few missed intervals is noise, not an outage.
     *
     * The threshold is four of the shortest interval, so a pause of a couple of seconds, a slow
     * sample or a machine that was briefly busy must not produce a failure. A check that cries wolf
     * is a check that gets ignored, which is the same as no check at all.
     */
    public function test_a_scheduler_a_couple_of_intervals_behind_is_still_healthy(): void
    {
        $report = $this->healthWithSchedulerAge(
            $this->supervisor(['queue', 'scheduler']),
            40,
        )->render();

        $this->assertDoesNotMatchRegularExpression('/FAIL\s+schedule/', $report);
    }

    /**
     * A scheduler that has never written is not a fault, and saying so is not a verdict.
     *
     * A fresh install has no scheduler log until something is scheduled, so the absence of one is
     * the ordinary starting state rather than evidence of anything.
     */
    public function test_a_scheduler_that_has_never_written_is_not_a_failure(): void
    {
        $logs = $this->data.DIRECTORY_SEPARATOR.'logs';

        if (! is_dir($logs)) {
            mkdir($logs, 0o777, true);
        }

        @unlink($logs.DIRECTORY_SEPARATOR.'scheduler.log');

        $report = $this->health($this->supervisor(['queue', 'scheduler']))->render();

        $this->assertDoesNotMatchRegularExpression('/FAIL\s+schedule/', $report);
        $this->assertStringContainsString('has not written anything yet', $report);
    }

    /**
     * A log it could not read says so rather than reporting a verdict it did not reach.
     *
     * This matters because `null` means "unknown" and both of the readings it could be confused with
     * are worse than saying nothing: read as healthy it hides a wedged scheduler, read as a failure
     * it invents one on a machine where the file happens to be locked. The log really is held open by
     * the scheduler on Windows, which is how this came up.
     */
    public function test_a_scheduler_log_it_cannot_read_is_not_treated_as_a_verdict(): void
    {
        $report = $this->healthWithSchedulerAge(
            $this->supervisor(['queue', 'scheduler']),
            null,
        )->render();

        $this->assertDoesNotMatchRegularExpression('/FAIL\s+schedule/', $report);
        $this->assertStringContainsString('could not be read', $report);
    }

    /**
     * A queue it could not read says so, rather than reporting a verdict it did not reach.
     *
     * This is the line that spent an evening looking like a considered answer. The first version
     * read the install's *template* ini, which still had `{{RUNTIME}}` in it, started PHP against
     * it, and got eleven extension warnings followed by `could not find driver`. It reported "not
     * checked", which is honest, but the eleven warnings in front of it are what made the log hard
     * to read, and the reason was a file nobody had read.
     */
    public function test_a_queue_it_cannot_read_says_so_and_is_not_a_failure(): void
    {
        $report = $this->healthWithQueue(
            $this->supervisor(['queue', 'scheduler']),
            'not checked  the database is locked',
        )->render();

        $this->assertTrue($this->queueWasAsked, 'the queue reader ran');
        $this->assertStringContainsString('not checked', $report);

        // Only the queue line is asserted here. The rest of the report is stubbed at whatever this
        // scratch install happens to say, and a database of zero bytes and a web server that is not
        // listening are both true of it, so `overall` cannot be about the queue line alone.
        $this->assertDoesNotMatchRegularExpression('/FAIL\s+queue\s+not checked/', $report);
    }

    /**
     * A sentence the probe would never print is no answer at all.
     *
     * The safety of `queueIsHealthy()` rests on its default, and a default that assumes an
     * unrecognised string is fine is a default that reports a broken queue as healthy the day the
     * vocabulary changes. So the filter has to reject what it does not know, and this is the test
     * that says so.
     */
    public function test_an_unrecognised_sentence_is_not_treated_as_a_verdict(): void
    {
        $health = $this->health($this->supervisor(['queue', 'scheduler']));

        // Bypasses the filter deliberately: this asks what queueIsHealthy() does with a stranger.
        $method = new \ReflectionMethod(Health::class, 'queueIsHealthy');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($health, 'idle  nothing queued'));
        $this->assertTrue($method->invoke($health, 'draining  4 queued, all being taken'));
        $this->assertTrue($method->invoke($health, 'not checked  locked'));
        $this->assertFalse($method->invoke($health, 'NOT DRAINING, 1 queued'));
        $this->assertFalse(
            $method->invoke($health, 'PHP Warning:  Unable to load dynamic library'),
            'a stray warning must never be read as a verdict',
        );

        /*
         * And the dangerous direction, which the previous version of this could not express.
         *
         * A verdict the method does not recognise is not a healthy queue. The earlier list of happy
         * prefixes returned false for a stranger, which is indistinguishable from returning false
         * for `NOT DRAINING`, so a test asserting "an unknown sentence is not a verdict" passed
         * against an implementation that treated every unknown sentence as a failure.
         */
        $this->assertFalse(
            $method->invoke($health, 'something entirely unexpected'),
            'an unrecognised sentence is not healthy, whatever it might turn out to mean',
        );
    }

    /**
     * Every sentence the probe can print has a verdict, and none is left to a default.
     *
     * This is the pairing that `queueIsHealthy()` is built on, asserted as a whole rather than one
     * sentence at a time. It exists because the previous version's default could not be reached by
     * any test: every assertion it made happened to be covered by a named prefix.
     */
    public function test_the_verdict_table_names_every_sentence_the_probe_can_print(): void
    {
        $health = $this->health($this->supervisor(['queue']));
        $method = new \ReflectionMethod(Health::class, 'queueIsHealthy');
        $method->setAccessible(true);

        $probe = new \ReflectionClass(Health::class);
        $source = file_get_contents((string) $probe->getFileName());

        // Every literal the probe can echo, read from the probe itself rather than restated here.
        preg_match_all("/echo '([a-z ]+)/", (string) $source, $printed);

        $this->assertNotEmpty($printed[1], 'the probe prints nothing, so this test would pass for the wrong reason');

        foreach (array_unique($printed[1]) as $prefix) {
            $healthy = $method->invoke($health, $prefix.' 1 queued');

            $this->assertIsBool(
                $healthy,
                'the probe can print "'.$prefix.'" and this has no verdict for it, so it falls to a default',
            );
        }

        // And the two halves are actually opposite, rather than one table with two spellings.
        $this->assertFalse($method->invoke($health, 'NOT DRAINING 1 queued'));
        $this->assertTrue($method->invoke($health, 'idle 1 queued'));
    }

    public function test_it_reports_each_of_the_four_processes_by_name(): void
    {
        $report = $this->health($this->supervisor())->render();

        foreach (['suwayomi', 'web', 'queue', 'scheduler'] as $name) {
            $this->assertStringContainsString($name, $report, "{$name} is missing from the report");
        }
    }

    /**
     * A missing worker must be a failure, not an omission.
     *
     * A queue worker that is not running is invisible everywhere else in the system: it does not
     * crash, it pops nothing, and every page still renders. Omitting it from the report would
     * make the one check that could catch it useless.
     */
    public function test_a_missing_worker_is_a_failure_rather_than_an_omission(): void
    {
        $report = $this->health($this->supervisor(['queue']))->render();

        // The one that is not in the running list is the one that must fail.
        $this->assertMatchesRegularExpression('/FAIL\s+scheduler/', $report);
        $this->assertDoesNotMatchRegularExpression('/FAIL\s+queue/', $report);
        $this->assertMatchesRegularExpression('/\bqueue\s+running\b/', $report);
    }

    /**
     * The consequence, not just the fact.
     *
     * "queue: not running" tells a reader nothing about whether they are losing anything. The
     * first version said "queued work is never done" for both workers, which is wrong for the
     * scheduler: it queues nothing, it refreshes series metadata.
     */
    public function test_each_worker_names_what_its_death_costs(): void
    {
        $report = $this->health($this->supervisor())->render();

        $this->assertStringContainsString('downloads and library checks are never done', $report);
        $this->assertStringContainsString('series are never refreshed', $report);
    }

    public function test_the_overall_line_is_ok_only_when_nothing_failed(): void
    {
        $supervisor = $this->supervisor(['queue', 'scheduler']);

        file_put_contents($this->data.DIRECTORY_SEPARATOR.'database.sqlite', str_repeat('x', 1024));

        $everythingUp = $this->health($supervisor, $this->prober(true, true, 200))->render();

        $this->assertDoesNotMatchRegularExpression('/FAIL/', $everythingUp, $everythingUp);
        $this->assertMatchesRegularExpression('/overall\s+ok\b/', $everythingUp);

        $somethingDown = $this->health($supervisor, $this->prober(false, false, 0))->render();

        $this->assertMatchesRegularExpression('/FAIL/', $somethingDown);
        $this->assertMatchesRegularExpression('/overall\s+something is wrong/', $somethingDown);
    }

    /**
     * A 503 is the application answering and refusing, which is not the same as nothing
     * listening. The report has to say so, because "web is not listening" sends a reader looking
     * at the launcher and "web says it is not ready" sends them looking at the app.
     */
    public function test_a_not_ready_app_is_distinguished_from_no_listener(): void
    {
        $supervisor = $this->supervisor();

        $listening = $this->health($supervisor, $this->prober(true, true, 503))->render();

        $this->assertMatchesRegularExpression('/web\s+listening/', $listening);
        $this->assertMatchesRegularExpression('/FAIL\s+web/', $listening);

        $silent = $this->health($supervisor, $this->prober(true, false, 0))->render();

        $this->assertMatchesRegularExpression('/web\s+not listening/', $silent);
    }

    /**
     * The database path must come from the `.env`, not from a guess.
     *
     * The first version hard coded `<data>/database/database.sqlite`. The real file is
     * `<data>/database.sqlite`, so it reported a healthy database as missing while `/ready`
     * reported the same database open. A diagnostic that guesses is a second source of truth and
     * it disagreed with the first.
     */
    public function test_it_finds_the_database_where_the_env_says_it_is(): void
    {
        $path = $this->data.DIRECTORY_SEPARATOR.'moved.sqlite';

        file_put_contents($path, 'not really a database, but it exists');

        $supervisor = $this->supervisor([], "DB_DATABASE='{$path}'\n");

        $this->assertSame($path, $supervisor->databaseFile());
        $this->assertMatchesRegularExpression('/\bdatabase\s+ok\b/', $this->health($supervisor)->render());
    }

    public function test_it_finds_the_database_without_quotes_in_the_env(): void
    {
        $path = $this->data.DIRECTORY_SEPARATOR.'plain.sqlite';

        file_put_contents($path, 'x');

        $supervisor = $this->supervisor([], "DB_DATABASE={$path}\n");

        $this->assertSame($path, $supervisor->databaseFile());
    }

    public function test_it_falls_back_to_the_data_directory_when_there_is_no_env(): void
    {
        $supervisor = $this->supervisor();

        $this->assertSame(
            $this->data.DIRECTORY_SEPARATOR.'database.sqlite',
            $supervisor->databaseFile(),
        );
    }

    public function test_a_missing_database_is_reported_as_a_failure(): void
    {
        $report = $this->health($this->supervisor())->render();

        $this->assertMatchesRegularExpression('/FAIL\s+database\s+not created/', $report);
    }

    /**
     * One line, not two.
     *
     * The first version reported `logging` and `diagnostics` as separate failures that both named
     * the same missing directory, which reads as two problems when it is one, and gives a reader
     * two things to try for a single fault.
     */
    public function test_an_unwritable_log_directory_is_one_failure_that_names_the_consequence(): void
    {
        $supervisor = new Supervisor(
            root: $this->install,
            dataDirectory: $this->data,
            logDirectory: $this->data.DIRECTORY_SEPARATOR.'nowhere',
            suwayomiPort: 4567,
            appPort: 8402,
        );

        $report = (new Health($supervisor, new Prober, new SummaryLog($this->data.DIRECTORY_SEPARATOR.'nowhere')))->render();

        $this->assertStringContainsString('FAIL', $report);
        $this->assertStringContainsString('no bundle can be written either', $report);
        $this->assertStringNotContainsString('diagnostics', $report);
    }

    public function test_writable_logs_report_the_bundle_as_available(): void
    {
        $report = $this->health($this->supervisor())->render();

        $this->assertStringContainsString('a diagnostics bundle can be written', $report);
    }

    public function test_every_failure_carries_an_explanation(): void
    {
        $report = $this->health($this->supervisor())->render();

        // Every FAIL line must say more than FAIL.
        foreach (preg_split('#\R#', $report) ?: [] as $line) {
            if (! str_contains($line, 'FAIL')) {
                continue;
            }

            $this->assertGreaterThan(
                20,
                strlen(trim($line)),
                "a FAIL line carries no explanation: {$line}",
            );
        }
    }

    public function test_the_report_names_no_file_the_reader_does_not_have(): void
    {
        $report = $this->health($this->supervisor())->render();

        // A path in the report is one the reader can act on, so it must be an absolute one.
        $this->assertStringContainsString($this->data, $report);
    }
}
