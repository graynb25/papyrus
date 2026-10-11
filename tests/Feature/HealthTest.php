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
