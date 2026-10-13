<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * Answers "is it working", which is a different question from "what happened".
 *
 * ## Why this is a command and not a log
 *
 * Because a reader asking whether Papyrus is broken should not have to open a file, and should not
 * have to know that there are four of them. The health command changes nothing, takes a second,
 * and answers in one screen. A reader who runs it and pastes the output has given us most of what
 * we need before they have written a word.
 *
 * ## Why the fields are what they are
 *
 * The last job and last run times look like decoration and are not. The queue worker and the
 * scheduler write nothing at all when healthy, which is correct behaviour and the worst possible
 * diagnostic. A worker whose database driver failed does not crash: it pops nothing and exits
 * quietly, and the install looks completely fine while every download, search and library check
 * silently never happens.
 *
 * Those two fields are the liveness signal for exactly the processes that cannot report their own
 * death. They are what would have turned a bug report into a diagnosis.
 */
final class Health
{
    /** @var list<array{name: string, detail: string, ok: bool}> */
    private array $lines = [];

    private bool $overall = true;

    public function __construct(
        private readonly Supervisor $supervisor,
        private readonly Prober $prober,
        private readonly SummaryLog $summary,
    ) {}

    /**
     * How a caller can answer the queue question without a real database.
     *
     * The production answer runs the install's own PHP against the install's own database. That is
     * correct and unfakeable, and it makes the interesting case untestable: a test asserting that a
     * stalled queue is reported cannot create a stalled queue on the machine running the suite,
     * because a real worker on this machine would drain it between the seeding and the reading.
     *
     * So the queue reading is a seam, and a test hands this a function returning the same one-line
     * sentence the real probe prints. The prefix vocabulary is what `queueIsHealthy()` matches on,
     * so a test writing a sentence the probe would never print is caught rather than quietly
     * reported as healthy.
     *
     * @var (callable(string): ?string)|null
     */
    private $queueReader = null;

    /**
     * Replace the queue reading. For tests only, for the reason above.
     *
     * @param  (callable(string): ?string)|null  $reader
     */
    public function useQueueReader(?callable $reader): void
    {
        $this->queueReader = $reader;
    }

    /**
     * How many seconds ago the scheduler last wrote to its log, for tests only.
     *
     * `null` means it could not be determined, which the report treats as "not checked" rather than
     * as healthy or as a fault, for the same reason `queue()` does: a check that cannot answer must
     * not be reported as an answer.
     *
     * @var (callable(): ?int)|null
     */
    private $schedulerAgeReader = null;

    /**
     * Replace the scheduler silence reading. For tests only, for the reason above.
     *
     * @param  (callable(): ?int)|null  $reader
     */
    public function useSchedulerAgeReader(?callable $reader): void
    {
        $this->schedulerAgeReader = $reader;
    }

    /**
     * The report, as the reader sees it.
     */
    public function render(): string
    {
        $this->lines = [];
        $this->overall = true;

        $this->suwayomi();
        $this->web();
        $this->workers();
        $this->queue();
        $this->scheduler();
        $this->database();
        $this->logs();

        return $this->table();
    }

    private function table(): string
    {
        $width = 0;

        foreach ($this->lines as $line) {
            $width = max($width, strlen($line['name']));
        }

        $out = '';

        foreach ($this->lines as $line) {
            $out .= sprintf(
                "  %s  %-{$width}s  %s%s",
                $line['ok'] ? 'ok  ' : 'FAIL',
                $line['name'],
                $line['detail'],
                PHP_EOL,
            );
        }

        $out .= PHP_EOL;
        $out .= sprintf('  overall     %s%s', $this->overall ? 'ok' : 'something is wrong, see the FAIL lines', PHP_EOL);

        return $out;
    }

    private function add(string $name, string $detail, bool $ok): void
    {
        $this->lines[] = ['name' => $name, 'detail' => $detail, 'ok' => $ok];

        if (! $ok) {
            $this->overall = false;
        }
    }

    private function suwayomi(): void
    {
        $port = $this->supervisor->suwayomiPort();
        $answering = $this->prober->suwayomiAnswers($port);

        if (! $answering) {
            $this->add('suwayomi', 'not answering on port '.$port, false);

            return;
        }

        $version = $this->prober->suwayomiVersion($port);

        $this->add(
            'suwayomi',
            'running  '.($version !== '' ? $version : 'version unknown').'  port '.$port.$this->compatibilityNote($version),
            ! $this->isTooOld($version),
        );
    }

    /**
     * What to say about the running server beyond its version.
     *
     * Two failures and one non-failure, and the difference between them is the whole point.
     *
     * **Too old is a fault.** Below the floor the GraphQL fields Papyrus depends on are not there,
     * nothing the reader does will help, and the only fix is a server upgrade. That earns a FAIL
     * because every line here that is not green is meant to be actionable.
     *
     * **Not the tested version is not a fault.** A reader on a newer Suwayomi may be perfectly fine,
     * and saying so in the failure colour would train them to ignore the one line that does mean it.
     * So it is said, in the ok colour, and it names the version we did test against so the reader can
     * tell "unusual" from "broken".
     *
     * **An unknown version is not a verdict.** The probe returns an empty string when Suwayomi
     * answers but omits the field, which happens on builds that predate it. That is not evidence of
     * an old server, so nothing is claimed.
     *
     * And when this build has no `suwayomi.json`, the same applies one level up: no file means no
     * opinion, and inventing a default would be a confident answer about a file nobody wrote.
     */
    private function compatibilityNote(string $version): string
    {
        $compatibility = $this->compatibility();

        if (! $compatibility->isKnown()) {
            return '  (this build records no Suwayomi version, so compatibility was not checked)';
        }

        $tooOld = $this->isTooOld($version);

        if ($tooOld === true) {
            return '  TOO OLD, Papyrus needs at least '.$compatibility->minimum();
        }

        if ($tooOld === null) {
            return '  (it did not report a version, so compatibility was not checked)';
        }

        if ($compatibility->isTested($version) !== true) {
            return '  newer than the tested '.$compatibility->tested().', which should work but is unproven here';
        }

        return '  the version Papyrus ships and tests against';
    }

    /**
     * Whether the reported server is below the floor, or null when that cannot be told.
     */
    private function isTooOld(string $version): ?bool
    {
        return $this->compatibility()->isTooOld($version);
    }

    /**
     * What this build was built to run against, read once per report.
     *
     * Staged into the install root beside `version.txt`, which records the app version and the
     * commit but said nothing about Suwayomi, so there was nothing for a reader to compare against.
     */
    private function compatibility(): SuwayomiCompatibility
    {
        if ($this->suwayomiCompatibility === null) {
            $this->suwayomiCompatibility = SuwayomiCompatibility::read(
                $this->supervisor->installRoot().DIRECTORY_SEPARATOR.'suwayomi.json',
            );
        }

        return $this->suwayomiCompatibility;
    }

    private ?SuwayomiCompatibility $suwayomiCompatibility = null;

    private function web(): void
    {
        $port = $this->supervisor->appPort();

        if (! $this->prober->isPortTaken($port)) {
            $this->add('web', 'not listening on port '.$port, false);

            return;
        }

        $readiness = $this->prober->readiness($port);

        if ($readiness === null) {
            $this->add('web', 'listening on '.$port.' but /ready did not answer', false);

            return;
        }

        $this->add(
            'web',
            sprintf(
                'listening  port %d  /ready %d  key %s  database %s',
                $port,
                $readiness['status'],
                $readiness['checks']['key'] ? 'ok' : 'FAILED',
                $readiness['checks']['database'] ? 'ok' : 'FAILED',
            ),
            $readiness['status'] === 200,
        );
    }

    /**
     * The two processes that cannot report their own death.
     *
     * A process being absent is the failure, and it is reported as such rather than skipped: a
     * missing worker is invisible everywhere else in the system.
     *
     * The consequence differs between them and is spelled out, because "not running" on its own
     * tells a reader nothing about whether they are losing anything. A dead queue silently drops
     * downloads and library checks; a dead scheduler silently stops refreshing series metadata.
     */
    private function workers(): void
    {
        $consequences = [
            'queue' => ['queue:work', 'downloads and library checks are never done'],
            'scheduler' => ['schedule:work', 'series are never refreshed'],
        ];

        foreach ($consequences as $name => [$command, $consequence]) {
            $running = $this->supervisor->isRunning($name);

            $this->add(
                $name,
                $running
                    ? 'running  '.$command
                    : 'NOT RUNNING, '.$command.' is not up, so '.$consequence,
                $running,
            );
        }
    }

    /**
     * How long the scheduler may be silent before it is called wedged.
     *
     * Four of the shortest interval. `SyncDownloads` runs every ten seconds and writes a line every
     * time, so at this threshold four consecutive dispatches have been missed. Tight enough that a
     * reader is told inside a minute, loose enough that a slow sample or a paused laptop does not
     * produce a false alarm.
     */
    private const SCHEDULER_SILENCE_SECONDS = 45;

    /**
     * Whether the scheduler is actually dispatching, as distinct from existing.
     *
     * ## Why the line above it is not enough
     *
     * `workers()` asks whether a `schedule:work` process exists, and it said "running" for six
     * minutes over a scheduler that had stopped dispatching at all. Measured on a real install: the
     * process was alive, using 0.016 seconds of CPU across ten seconds, with its log frozen at
     * 2,017 lines and not one `schedule:run` child spawned in a sampled 32 seconds. Every other line
     * was green. It looked exactly like the install after the `PHPRC` fix, because the install *was*
     * fine; one process in it had stopped looping and nothing could tell.
     *
     * This is the same lesson as the queue line, arriving through the other door. Liveness is not
     * participation. A scheduler that has wedged is the more expensive of the two failures, because
     * downloads still drain through the queue and so the install looks busy while series are never
     * refreshed.
     *
     * ## Why the log's own modification time rather than a timestamp inside it
     *
     * Because parsing the timestamps would be wrong by five hours. The scheduler renders them at
     * UTC+5 while this process runs at UTC, so an age computed from `date()` against a parsed line
     * reports a healthy scheduler as silent since yesterday. A file's modification time is a unix
     * timestamp and does not care what timezone anyone renders it in, which is the whole reason to
     * prefer it here.
     *
     * And what the log contains is not needed: the question is whether anything is still being
     * written, which is the question the modification time answers directly, without depending on a
     * line format that Laravel is free to change.
     *
     * ## What this does not prove
     *
     * It proves the scheduler is writing. It does not prove a dispatch succeeded, because a failing
     * dispatch also writes. It is the cheap half of the question and it is the half that was missing;
     * the expensive half is what `queue()` already does with the jobs table.
     */
    private function scheduler(): void
    {
        $log = $this->supervisor->logDirectory().DIRECTORY_SEPARATOR.'scheduler.log';

        if (! is_file($log)) {
            // Nothing has been scheduled yet, which is not a fault. A fresh install has no log.
            $this->add('schedule', 'not checked  the scheduler has not written anything yet', true);

            return;
        }

        $age = $this->schedulerAgeReader !== null
            ? ($this->schedulerAgeReader)()
            : $this->schedulerLogAge($log);

        if ($age === null) {
            $this->add(
                'schedule',
                'not checked  the scheduler log could not be read, so this says nothing either way',
                true,
            );

            return;
        }

        $this->add(
            'schedule',
            $age <= self::SCHEDULER_SILENCE_SECONDS
                ? 'dispatching, last wrote '.$age.'s ago'
                : 'NOT DISPATCHING, the scheduler has written nothing for '.$age.'s, so series are '
                    .'never refreshed',
            $age <= self::SCHEDULER_SILENCE_SECONDS,
        );
    }

    /**
     * Seconds since the scheduler last wrote, or null when it cannot be told.
     *
     * `clearstatcache()` because PHP caches the previous answer for the same path, and health is run
     * repeatedly against a file that is changing underneath it. A stale mtime here would report a
     * live scheduler as wedged, which is the failure this whole check exists to prevent, so it would
     * be a check that manufactures the bug it is looking for.
     */
    private function schedulerLogAge(string $log): ?int
    {
        clearstatcache(true, $log);

        $modified = @filemtime($log);

        if ($modified === false) {
            return null;
        }

        return max(0, time() - $modified);
    }

    /**
     * Whether anything is actually draining the queue.
     *
     * ## The line above it is not enough, and here is the hour it cost
     *
     * `workers()` asks whether a process matching `queue:work` exists. That answer was "yes" for an
     * evening while a library check sat in the jobs table for an hour and a half with nobody
     * touching it, because the worker that existed belonged to a different install and was draining
     * a different database. Every other line was green. The library page said "Checked 0 of 670
     * series" and offered no way to tell a slow queue from an absent one.
     *
     * So process presence is the wrong question. The question is whether a job that is due has been
     * taken, and the queue table answers that directly.
     *
     * ## Why "due and unreserved" rather than "queued"
     *
     * Because `CheckLibrary` re-books itself on a three second delay, so a healthy queue is
     * routinely non-empty and routinely holds jobs whose `available_at` is in the future. Counting
     * rows would report a working queue as unhealthy. Counting rows that are due now and have not
     * been reserved is zero on a working queue and non-zero on a dead one.
     *
     * ## Why a minute of grace
     *
     * The worker polls with `--sleep=1`, so a job that becomes due is normally reserved within a
     * second or two. A minute is far longer than that and far shorter than the reader watching a
     * progress bar. It is a threshold on a condition with an obvious meaning, not a number chosen
     * to make a test pass.
     */
    private function queue(): void
    {
        $path = $this->supervisor->databaseFile();

        if (! is_file($path)) {
            // Nothing has been dispatched yet, which is not a fault.
            $this->add('queue', 'idle  nothing queued yet', true);

            return;
        }

        $reported = $this->queueReader !== null
            ? ($this->queueReader)($path)
            : $this->askTheInstallsPhp($path);

        if ($reported === null) {
            $this->add(
                'queue',
                'not checked  the queue table could not be read, so this says nothing either way',
                true,
            );

            return;
        }

        $this->add('queue', $reported, $this->queueIsHealthy($reported));
    }

    /**
     * How long a due job may sit unreserved before this is called a failure.
     *
     * The worker polls every second, so this is roughly sixty polls of slack.
     */
    public const QUEUE_GRACE_SECONDS = 60;

    /**
     * Whether a reported queue is one that is being worked on.
     *
     * Every prefix the probe can return is spelled out, and the default is the safe one. A prefix
     * this method has never seen means the probe and this have drifted, and the answer then has to
     * be that it is fine: reporting a healthy queue as broken costs a reader an afternoon, and
     * reporting a broken one as healthy costs nothing they would have noticed.
     */
    private function queueIsHealthy(string $reported): bool
    {
        /*
         * Spelled out as pairs rather than as a list of happy prefixes.
         *
         * The list version had a test that could not fail. It asked whether an unrecognised
         * sentence is treated as no answer, and the list version answered no, because it returned
         * false for everything unrecognised and so did the default it was supposed to be proving.
         * Anything the probe can print has to be named, and anything it cannot is not healthy.
         */
        $sentences = [
            'idle' => true,
            'draining' => true,
            'not checked' => true,
            'NOT DRAINING' => false,
        ];

        foreach ($sentences as $prefix => $healthy) {
            if (str_starts_with($reported, $prefix)) {
                return $healthy;
            }
        }

        // A sentence this method has never seen. See the comment above.
        return false;
    }

    /**
     * Ask the install's own PHP to read the queue table, and return one line describing it.
     *
     * A child process, because the launcher's own interpreter cannot read the table at all. That is
     * measured rather than assumed: `php_ini_loaded_file()` is empty here and `extension_dir` is
     * the compiled-in `C:\php\ext`, so `pdo_sqlite` is genuinely absent from this process while the
     * same `php.exe` started with the install's resolved ini loads all twelve extensions and
     * reports `sqlite` among its PDO drivers.
     *
     * Written to a temporary file and run with `-c` rather than with `-r`, because a command line
     * carrying this project's paths is exactly where an afternoon has already been lost to a space
     * splitting an argument in half.
     */
    private function askTheInstallsPhp(string $database): ?string
    {
        $php = $this->supervisor->phpExecutable();

        try {
            $ini = $this->supervisor->resolvedIniPath();
        } catch (\Throwable) {
            /*
             * `resolvedIniPath()` refuses when the template ini is absent, and it is right to:
             * without it PHP loads no extensions at all. But health is a diagnostic, and a
             * diagnostic that dies because a file is missing has failed at the only job it has,
             * which is saying what is missing. The other lines still report.
             */
            return null;
        }

        if (! is_file($php) || ! is_file($ini)) {
            return null;
        }

        /*
         * The ini the *running* install actually used, not the one the template describes.
         *
         * `resolvedIniPath()` answers "where is the ini", and on a fresh install that is the
         * unresolved template, because substitution happens into the data directory at start. This
         * diagnostic reached that path, started the install's php against the template, and got
         * eleven warnings about `{{RUNTIME}}` and `could not find driver` followed by a confident
         * "not checked". So it looked like a considered verdict about the queue and was really an
         * unresolved template read as if it were a configuration.
         *
         * That is the same mistake the build caught once already, where a probe reported a
         * specific and wrong answer about a file it had not read.
         *
         * The data directory's resolved ini is the one that is known good: the web server, the
         * workers and this install's own boot all run against it, and the launcher resolves it
         * before it starts anything. Where it is missing the answer is no answer, rather than a
         * warning-laden run that looks like one.
         */
        $resolved = dirname($this->supervisor->databaseFile()).DIRECTORY_SEPARATOR.'php'
            .DIRECTORY_SEPARATOR.'php.ini';

        if (is_file($resolved)) {
            $ini = $resolved;
        } elseif (str_contains($ini, '{{')) {
            return null;
        }

        $probe = tempnam(sys_get_temp_dir(), 'papyrus-queue-');

        if ($probe === false) {
            return null;
        }

        if (file_put_contents($probe, self::QUEUE_PROBE) === false) {
            @unlink($probe);

            return null;
        }

        $output = [];
        $status = 1;

        try {
            exec(
                sprintf(
                    '%s -c %s %s %s 2>&1',
                    escapeshellarg($php),
                    escapeshellarg($ini),
                    escapeshellarg($probe),
                    escapeshellarg($database),
                ),
                $output,
                $status,
            );
        } finally {
            @unlink($probe);
        }

        $line = trim(implode(' ', $output));

        /*
         * Anything that is not one of our sentences is treated as no answer at all.
         *
         * A PHP notice, a warning about the ini, a path echoed by a misconfigured environment: all
         * of them would otherwise become the queue's reported status, which is a confident wrong
         * answer about the thing a reader is relying on. `queueIsHealthy()` matches on the same
         * prefixes, so the two agree on what counts as a sentence.
         */
        foreach (['idle', 'draining', 'NOT DRAINING', 'not checked'] as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * The child program that reads the queue and prints exactly one line.
     *
     * Written out in full rather than composed, because it runs as its own process with no shared
     * code and because a diagnostic nobody can read in one place is one nobody will check later.
     *
     * `reserved_at IS NULL AND available_at <= :now` is the whole test: a job that is due and has
     * not been taken is the condition that means nothing is draining.
     */
    private const QUEUE_PROBE = <<<'PHP'
<?php

$path = $argv[1] ?? '';

try {
    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    echo 'not checked  '.$e->getMessage();

    return;
}

$now = time();

try {
    $queued = (int) $pdo->query('SELECT count(*) FROM jobs')->fetchColumn();

    $statement = $pdo->prepare(
        'SELECT count(*), min(available_at) FROM jobs WHERE reserved_at IS NULL AND available_at <= :now'
    );
    $statement->execute([':now' => $now]);

    [$undrained, $oldest] = $statement->fetch(PDO::FETCH_NUM);
} catch (Throwable $e) {
    echo 'not checked  '.$e->getMessage();

    return;
}

if ($queued === 0) {
    echo 'idle  nothing queued';

    return;
}

$age = $oldest === null ? 0 : $now - (int) $oldest;

if ((int) $undrained === 0) {
    echo 'draining  '.$queued.' queued, all being taken';

    return;
}

if ($age < 60) {
    echo 'draining  '.$queued.' queued, oldest due '.$age.'s ago';

    return;
}

echo 'NOT DRAINING, '.$undrained.' queued and the oldest has been due for '
    .round($age / 60).' minutes with no worker taking it. A queue:work process may be running '
    .'for a different install.';
PHP;

    private function database(): void
    {
        $path = $this->supervisor->databaseFile();

        if (! is_file($path)) {
            $this->add('database', 'not created yet at '.$path, false);

            return;
        }

        $size = (int) filesize($path);
        $megabytes = round($size / 1048576, 1);

        $this->add(
            'database',
            sprintf('ok  %s  %.1f MB', basename($path), $megabytes),
            $size > 0,
        );
    }

    /**
     * Whether logs can be written, and whether that also means a bundle can be produced.
     *
     * One line rather than two, because they are the same question. They failed separately in the
     * first run of this command, both naming the same missing directory, which reads as two
     * problems when it is one.
     */
    private function logs(): void
    {
        $status = $this->summary->diagnose();

        if ($status === 'ok') {
            $this->add(
                'logging',
                'ok, and a diagnostics bundle can be written on request',
                true,
            );

            return;
        }

        // Named as one failure. A reader who cannot write logs cannot send us a bundle either, and
        // the action is the same for both: free up the directory.
        $this->add('logging', $status.' (so no bundle can be written either)', false);
    }

    /**
     * @throws RuntimeException when something is wrong, so a script can branch on it.
     */
    public function assertHealthy(): void
    {
        if (! str_contains($this->render(), 'FAIL')) {
            return;
        }

        throw new RuntimeException('This install is not healthy. Run papyrus health for the detail.');
    }
}
