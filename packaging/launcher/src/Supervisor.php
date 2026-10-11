<?php

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * Starts and stops the four processes a packaged Papyrus is made of.
 *
 * ## Why four, and why the order is not arbitrary
 *
 * The four are Suwayomi, the web server, the queue worker and the scheduler. Two of those are
 * easy to forget and neither is optional:
 *
 * - Without the **queue worker**, every download, search fan-out, library check and backup
 *   import queues and never runs. The app looks alive and does nothing.
 * - Without the **scheduler**, the daily library check and the download sync never happen, and
 *   nothing anywhere reports that they are missing.
 *
 * So a launcher that starts the two visible ones has built something that passes a smoke test
 * and fails the first day. That is the failure this class exists to make impossible.
 *
 * Suwayomi goes first because it is the slowest to start and everything else depends on it.
 * The web server goes after the migrations, because a server that boots against a schema that
 * does not exist yet answers 500 and looks like the server is broken.
 *
 * ## Stop order is the reverse, and is not cosmetic
 *
 * The scheduler and queue worker are the ones writing to the database. Stopping the web server
 * first would leave them running against an app that is no longer accepting progress pings,
 * and stopping the database-adjacent processes last would let an import start in the window
 * between. So: scheduler, queue, web, Suwayomi.
 *
 * ## The lock is taken before anything starts
 *
 * Because two launchers starting four processes each is eight processes on two ports, and the
 * symptom is a confusing half-installed state rather than a clean refusal. Losing the race is
 * not a crash: it is a message naming the other launcher.
 *
 * ## This class deliberately does not use the application
 *
 * No Laravel, no database, no config. It has to work when the application is broken, because
 * "stop and tell me what happened" is exactly the call a reader makes when the application is
 * broken. Anything this class needs from the app it reads from files.
 */
final class Supervisor
{
    /** @var array<string, ManagedProcess> */
    private array $started = [];

    private ?InstanceLock $lock = null;

    /**
     * The web server's configuration with this install's paths and ports filled in.
     *
     * Set by {@see resolveWebConfig()} during start and read by {@see startWebServer()}. Null
     * before that, in which case the unsubstituted template is used and points at a root of
     * `{{APP_ROOT}}`, which fails loudly rather than quietly serving nothing.
     */
    private ?string $resolvedConfig = null;

    /**
     * The php.ini with this install's paths filled in.
     *
     * Same arrangement as {@see $resolvedConfig} and for the same reason, but this one was
     * missing for a while and the omission was invisible until the install was run the way a
     * reader runs it. The shipped ini is a template: it says `extension_dir = "{{RUNTIME}}/ext"`
     * and it means it. PHP accepts that as a directory name containing braces, fails to find
     * any of the twelve extensions in it, and carries on with none of them loaded. The symptom
     * arrives four layers away as a missing database driver, so the browser cannot connect and
     * nothing says the ini was the reason.
     */
    private ?string $resolvedIni = null;

    /** Set once a start, so a polling loop reports an unhappy application exactly once. */
    private bool $reportedUnready = false;

    /** Created on demand, since a `status` or `health` call never needs it. */
    private ?SummaryLog $summary = null;

    public function __construct(
        private readonly string $root,
        private readonly string $dataDirectory,
        private readonly string $logDirectory,
        private readonly int $suwayomiPort = 4567,
        private readonly int $appPort = 8000,
        private readonly ?string $suwayomiJar = null,
        private readonly ?string $javaExecutable = null,
        private readonly ?string $phpExecutable = null,
        private readonly ?string $frankenphpExecutable = null,
        /**
         * Where php.ini lives, when the executable has been pointed somewhere else.
         *
         * Separate from {@see $phpExecutable} rather than derived from it, because deriving
         * couples two things that are chosen independently and guessing wrong is silent: PHP
         * with no ini loads no extensions, so the process still runs, still reports a version,
         * and Laravel then fails with "could not find driver" rather than anything about the
         * ini. An override for the executable therefore has to come with an override for the
         * ini, and making the caller pass both is the honest version of that.
         */
        private readonly ?string $phpIni = null,
        /**
         * Where the Caddyfile lives, for the same reason as {@see $phpIni}.
         *
         * Pointing the web server at an executable somewhere other than its own runtime, and
         * then naming a config under the application root, is how the server starts, logs two
         * lines about Go runtime tuning, and exits without ever reading a config. No error,
         * because the failure is a missing file the process never gets far enough to report.
         */
        private readonly ?string $frankenphpConfig = null,
        private ?Prober $prober = null,
    ) {
        $this->prober = $prober ?? new Prober;
    }

    /**
     * The ini to hand PHP.
     *
     * Checked rather than assumed, because the failure of getting it wrong is a missing
     * database driver four layers away.
     */
    private function ini(): string
    {
        return $this->resolvedIni ?? $this->iniTemplate();
    }

    /**
     * The ini as it ships, before substitution.
     *
     * Checked rather than assumed, because the failure of getting it wrong is a missing
     * database driver four layers away. Kept separate from {@see ini()} so that the existence
     * check still runs on the file that actually has to be there, rather than on a resolved copy
     * this class wrote itself and would therefore always find.
     */
    private function iniTemplate(): string
    {
        return $this->existing(
            $this->phpIni ?? $this->path('runtime', 'php', 'php.ini'),
            'PHP configuration file',
            'Without it PHP loads no extensions at all, so the app cannot open its database.',
        );
    }

    /**
     * The Caddyfile to hand the web server.
     *
     * Checked for the same reason and with the same stakes: a path that does not exist is not
     * an error the server can report, because it exits before it reads anything.
     */
    private function caddyfile(): string
    {
        return $this->existing(
            $this->frankenphpConfig ?? $this->path('runtime', 'frankenphp', 'Caddyfile'),
            'web server configuration',
            'The web server exits immediately when it cannot read this, without logging why.',
        );
    }

    /**
     * Refuse a runtime file that is not there, naming it.
     *
     * The alternative is a subprocess that exits with no output, which is indistinguishable
     * from a hundred other failures and costs a reader a support thread to diagnose.
     */
    private function existing(string $path, string $what, string $consequence): string
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf(
                'The %s is not where this install expects it. Looking for %s. %s',
                $what,
                $path,
                $consequence,
            ));
        }

        return $path;
    }

    /**
     * Start everything, in the order it actually needs, gating on each.
     *
     * @param  callable(string): void  $say  Progress, so a reader watching a window sees why
     *                                       the browser has not opened yet.
     * @return list<string> One line per process, in start order.
     */
    public function start(callable $say): array
    {
        /*
         * The summary log is opened before anything else, including the lock.
         *
         * Because the first thing a reader needs is proof the launcher was reached at all. A
         * failure before this point leaves nothing anywhere, and "nothing happened" and "the app
         * never started" are indistinguishable from a log that was never created.
         */
        $summary = $this->summary();

        $summary->record('papyrus', sprintf(
            'starting, install %s, data %s',
            $this->root,
            $this->dataDirectory,
        ));

        $this->prepareLayout($say);

        $summary->record('papyrus', 'layout ready');

        $this->takeLock();

        try {
            $this->startSuwayomi($say);
            $summary->record('suwayomi', sprintf('ready on port %d', $this->suwayomiPort));

            $this->migrate($say);
            $summary->record('papyrus', 'database current');

            $this->startWebServer($say);
            $summary->record('web', sprintf('serving on http://127.0.0.1:%d, ready', $this->appPort));

            $this->startWorkers($say);
            $summary->record('papyrus', 'queue worker and scheduler started');

            $summary->record('papyrus', sprintf('ready at http://127.0.0.1:%d', $this->appPort));

            return array_keys($this->started);
        } catch (\Throwable $e) {
            // A half started install is worse than a stopped one, so unwind whatever came up
            // before rethrowing. Leaving Suwayomi running with no web server is the state a
            // reader cannot diagnose and we can.
            $this->stopAll($say);

            // Recorded before rethrowing, because a thrown exception in a terminal window is the
            // one message that never reaches a log file, and this is the reader's only record
            // that the install tried and failed.
            $summary->problem('papyrus', sprintf(
                'did not start: %s (see the collected logs in %s)',
                $e->getMessage(),
                $this->logDirectory,
            ));

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Stop everything that was started, newest first, and report what each needed.
     *
     * @param  (callable(string): void)|null  $say
     * @return array<string, string> Process name to how it ended.
     */
    public function stopAll(?callable $say = null): array
    {
        $say = $say ?? static fn (string $line): null => null;

        $outcomes = [];

        foreach (array_reverse(array_keys($this->started), true) as $name) {
            $process = $this->started[$name];
            $outcome = $process->stop();

            $outcomes[$name] = $outcome;
            $say(sprintf('%s: %s', $name, $outcome));
        }

        $this->started = [];

        if ($this->lock !== null) {
            $this->lock->release();
            $this->lock = null;
        }

        /*
         * The stop line, and the single most useful line in the file.
         *
         * When a reader says "it crashed", this is what answers whether the exit was orderly
         * without them having to describe how it ended. A stop that reports four processes is a
         * different conversation from one that reports it had to kill something.
         */
        if ($outcomes !== []) {
            $this->summary()->record('papyrus', sprintf(
                'stopped cleanly, %d process%s: %s',
                count($outcomes),
                count($outcomes) === 1 ? '' : 'es',
                implode(', ', array_keys($outcomes)),
            ));
        }

        return $outcomes;
    }

    /**
     * @return array<string, bool>
     */
    public function status(): array
    {
        $status = [];

        foreach ($this->started as $name => $process) {
            $status[$name] = $process->isRunning();
        }

        return $status;
    }

    private function startSuwayomi(callable $say): void
    {
        if ($this->prober->isPortTaken($this->suwayomiPort)) {
            /*
             * Do not adopt it and do not start a second one. This machine has Suwayomi running
             * from a shortcut, and a launcher that adopted it would have no handle to stop it
             * with. Saying so plainly, and naming the port, is the most useful thing here.
             */
            $say(sprintf(
                'Something is already listening on port %d. Papyrus will use it rather than start a '
                .'second Suwayomi, and cannot stop it for you.',
                $this->suwayomiPort,
            ));

            return;
        }

        $java = $this->javaExecutable ?? $this->path('suwayomi', 'runtime', 'bin', 'java.exe');

        if (! is_file($java)) {
            $java = $this->path('suwayomi', 'runtime', 'bin', 'java');
        }

        $jar = $this->suwayomiJar ?? $this->locateJar();

        if ($jar === null) {
            throw new RuntimeException(
                'No Suwayomi jar was found. Papyrus cannot run without it, and it is not installed '
                .'here. Reinstall, or point the install at an existing Suwayomi.'
            );
        }

        $say('starting Suwayomi, which takes the longest of the four');

        $process = new ManagedProcess(
            name: 'suwayomi',
            executable: $java,
            arguments: ['-jar', $jar],
            workingDirectory: $this->dataDirectory,
            logFile: $this->logDirectory.DIRECTORY_SEPARATOR.'suwayomi.log',
            environment: ['TACHIDESK_DIR' => $this->dataDirectory],
        );

        $process->start();
        $this->started['suwayomi'] = $process;

        $ready = $this->prober->waitFor(
            fn (): bool => $this->suwayomiAnswers(),
            180,
            'Suwayomi to answer its GraphQL API',
            $say,
        );

        if (! $ready) {
            throw new RuntimeException(sprintf(
                'Suwayomi did not answer on port %d within three minutes. Its own log is at %s, and '
                .'it is almost always a JVM the machine could not afford or a port already in use.',
                $this->suwayomiPort,
                $this->logDirectory.DIRECTORY_SEPARATOR.'suwayomi.log',
            ));
        }

        $say('Suwayomi is answering');
    }

    private function migrate(callable $say): void
    {
        $say('checking whether the database needs migrating');

        $result = $this->runArtisan(['migrate', '--force']);

        if ($result !== 0) {
            throw new RuntimeException(sprintf(
                'The database could not be migrated. Papyrus will not start against a schema it does '
                .'not understand, because every page after that would fail differently. The output is '
                .'in %s.',
                $this->logDirectory.DIRECTORY_SEPARATOR.'migrate.log',
            ));
        }

        $say('database is current');
    }

    private function startWebServer(callable $say): void
    {
        if ($this->prober->isPortTaken($this->appPort)) {
            throw new RuntimeException(sprintf(
                'Something is already listening on port %d, which is the one Papyrus uses. Close it and '
                .'start again. This is almost always a Papyrus already running.',
                $this->appPort,
            ));
        }

        $say(sprintf('starting the web server on port %d', $this->appPort));

        /*
         * PHPRC, not a flag.
         *
         * FrankenPHP embeds its own copy of PHP and searches for php.ini beside *its*
         * executable, not beside the php.exe that `-c` points somewhere else. So the web
         * server came up with no ini at all, loaded none of the extensions the packaged one
         * enables, and every page failed with "could not find driver" while the log said the
         * server was running and serving.
         *
         * The two halves of the install disagreed in a way nothing reported: `artisan migrate`
         * through php.exe was perfectly happy, and every request through the web server could
         * not open a database. PHPRC is the documented way to tell an embedded interpreter
         * which ini is the real one.
         */
        $process = new ManagedProcess(
            name: 'web',
            executable: $this->frankenphpExecutable ?? $this->path('runtime', 'bin', 'frankenphp.exe'),
            arguments: ['run', '--config', $this->resolvedConfig ?? $this->caddyfile()],
            workingDirectory: $this->root,
            logFile: $this->logDirectory.DIRECTORY_SEPARATOR.'web.log',
            // Both of these, and neither is optional. PHPRC is how FrankenPHP finds the ini at
            // all, and PAPYRUS_ENV is how the application finds the `.env` that carries its app
            // key. With PHPRC alone the server starts and serves, and answers every request with
            // a 503 from `/ready` because it has no key.
            environment: ['PHPRC' => $this->ini()] + $this->applicationEnvironment(),
        );

        $process->start();
        $this->started['web'] = $process;

        $ready = $this->prober->waitFor(
            fn (): bool => $this->appIsReady(),
            60,
            'the web server to report itself ready',
            $say,
        );

        if (! $ready) {
            throw new RuntimeException(sprintf(
                'The web server started but never reported ready. Its log is at %s.',
                $this->logDirectory.DIRECTORY_SEPARATOR.'web.log',
            ));
        }

        $say('the app reports ready');
    }

    private function startWorkers(callable $say): void
    {
        /*
         * Both of these name the ini explicitly, exactly as `runArtisan` does.
         *
         * These are the two processes nobody sees fail. A queue worker without pdo_sqlite does
         * not crash, it pops nothing and exits quietly, and a scheduler without it logs
         * nothing at all. The app looks completely healthy and every download, search and
         * library check silently never happens.
         */
        $php = $this->phpExecutable ?? $this->path('runtime', 'bin', 'php.exe');

        $queue = new ManagedProcess(
            name: 'queue',
            executable: $php,
            arguments: ['-c', $this->ini(), $this->path('artisan'), 'queue:work', '--tries=3', '--sleep=1'],
            workingDirectory: $this->root,
            logFile: $this->logDirectory.DIRECTORY_SEPARATOR.'queue.log',
            environment: $this->applicationEnvironment(),
        );

        $schedule = new ManagedProcess(
            name: 'scheduler',
            executable: $php,
            arguments: ['-c', $this->ini(), $this->path('artisan'), 'schedule:work'],
            workingDirectory: $this->root,
            logFile: $this->logDirectory.DIRECTORY_SEPARATOR.'scheduler.log',
            environment: $this->applicationEnvironment(),
        );

        $queue->start();
        $schedule->start();

        $this->started['queue'] = $queue;
        $this->started['scheduler'] = $schedule;

        $say('queue worker and scheduler started');
    }

    /**
     * The environment every Papyrus process needs, keyed on the `.env` the launcher wrote.
     *
     * All four processes need it, and the web server needs it most, because that is where the
     * failure is invisible. The app boots, has no `APP_KEY`, `/ready` answers 503 with
     * `"key": false`, and the launcher polls a permanently unready application until it reports
     * that the web server never started while the server's own log says "server running".
     *
     * The queue worker and scheduler fail the same way and worse: a worker with no database
     * driver pops nothing and exits quietly, and a scheduler with one logs nothing at all. The
     * install looks perfectly healthy and every download, search and library check silently never
     * happens.
     *
     * Named in one place so a fourth process cannot be added without it. The alternative is a
     * `PAPYRUS_ENV` literal repeated at each call site, which is how two of these four were
     * missed in the first place.
     */
    private function applicationEnvironment(): array
    {
        return ['PAPYRUS_ENV' => $this->environmentFile()];
    }

    /** The `.env` the launcher wrote, which lives in the data directory rather than the install. */
    private function environmentFile(): string
    {
        return $this->dataDirectory.DIRECTORY_SEPARATOR.'.env';
    }

    /**
     * Whether Suwayomi's GraphQL answers.
     *
     * A short timeout on the socket deliberately. This is called in a polling loop, and a
     * Suwayomi that is slow to respond should be retried rather than have the whole wait
     * extended by a single slow answer.
     */
    private function suwayomiAnswers(): bool
    {
        $body = json_encode([
            'query' => '{ aboutServer { version buildTime } }',
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(
            sprintf('http://127.0.0.1:%d/api/graphql', $this->suwayomiPort),
            false,
            $context,
        );

        return is_string($response) && str_contains($response, 'aboutServer');
    }

    /**
     * Whether the app's own readiness probe passes.
     */
    private function appIsReady(): bool
    {
        $context = stream_context_create([
            'http' => ['timeout' => 3, 'ignore_errors' => true],
        ]);

        $response = @file_get_contents(
            sprintf('http://127.0.0.1:%d/ready', $this->appPort),
            false,
            $context,
        );

        if (! is_string($response)) {
            return false;
        }

        // A 503 with `"not ready"` is the application answering correctly and reporting that it
        // cannot be used, which is a different thing from the server being unreachable and worth
        // saying out loud. The symptom it replaced was a launcher that polled a permanently
        // unready app for a minute and then reported "the web server started but never reported
        // ready", while the server's log said "server running" and the answer sat in the body.
        $status = $http_response_header[0] ?? '';

        if (str_contains($response, '"not ready"') || str_contains($response, '"not found"')) {
            $this->reportUnready($status, $response);
        }

        return str_contains($response, '"ready"');
    }

    /**
     * Say, once, that the app is answering and refusing, and why.
     *
     * Without this the only evidence that the application is alive and unhappy is in a response
     * body the launcher discards. The install then fails with a message about the web server,
     * which is the one component that was working.
     *
     * Reported at most once per start, because this runs in a polling loop and a message printed
     * sixty times is the same as a message printed never.
     */
    private function reportUnready(string $status, string $response): void
    {
        if ($this->reportedUnready) {
            return;
        }

        $this->reportedUnready = true;

        $checks = [];

        if (preg_match('#"checks":(\{.*?\})#', $response, $matches) === 1) {
            $decoded = json_decode($matches[1], true);

            if (is_array($decoded)) {
                foreach ($decoded as $name => $value) {
                    $checks[] = $name.'='.(is_bool($value) ? ($value ? 'ok' : 'FAILED') : (string) $value);
                }
            }
        }

        fwrite(STDERR, sprintf(
            PHP_EOL.'  the application is answering and reporting that it is not ready (%s).' % PHP_EOL
            .'  checks: %s'.PHP_EOL
            .'  A failed "key" means the app is not reading the .env this launcher wrote. It is at %s'.PHP_EOL
            .'  and is passed in as PAPYRUS_ENV.'.PHP_EOL.PHP_EOL,
            $status !== '' ? $status : 'no status line',
            $checks === [] ? 'none reported' : implode(', ', $checks),
            $this->environmentFile(),
        ));
    }

    /**
     * Run an artisan command and wait for it.
     *
     * The `-c` is not optional and neither is `artisan`. Leaving `artisan` out produces
     * "Could not open input file: migrate", because PHP then treats the first argument as a
     * script path, which is a confusing way to be told the command was built wrong.
     *
     * `php.ini` has to be named explicitly because the working directory is the application
     * root and PHP looks for an ini beside the executable, not beside the script. A packaged
     * runtime that keeps its ini in `runtime/php/` therefore gets none of its extensions
     * unless it is told, and a Laravel without pdo_sqlite cannot boot.
     */
    private function runArtisan(array $arguments, array $extra = [], string $logName = 'migrate.log'): int
    {
        $log = $this->logDirectory.DIRECTORY_SEPARATOR.$logName;
        @mkdir($this->logDirectory, 0o777, true);

        $command = array_merge(
            [
                $this->phpExecutable ?? $this->path('runtime', 'bin', 'php.exe'),
                '-c',
                $this->ini(),
                $this->path('artisan'),
            ],
            $arguments,
            $extra,
            ['--no-interaction'],
        );

        /*
         * The environment is merged rather than omitted, and it is the difference between
         * migrating this install's database and migrating the wrong one.
         *
         * `proc_open`'s fifth argument replaces the child's environment outright rather than
         * adding to it, so it has to carry the inherited set as well or the process loses
         * `SystemRoot`, `PATH` and `TEMP` and dies before logging anything. See
         * ManagedProcess::inheritedEnvironment for the same problem solved once.
         *
         * Passing this is what makes `artisan migrate` write into the **data directory**. Without
         * it the command loads the base path's `.env`, finds a database path pointing inside the
         * install, and creates a 475KB SQLite file there. The launcher then starts a web server
         * whose `DB_DATABASE` points somewhere else entirely, and `/ready` reports
         * `database: false` against an install that looks correctly populated.
         */
        $inherited = getenv();
        $environment = is_array($inherited) ? $inherited : [];

        foreach ($this->applicationEnvironment() as $name => $value) {
            $environment[$name] = $value;
        }

        // The array form on every platform, for the reason in ManagedProcess: a string command on
        // Windows is run through `cmd /c`, whose quoting rules then reinterpret arguments this
        // method has already escaped correctly.
        $process = @proc_open(
            $command,
            [
                0 => ['file', ManagedProcess::nullDevice(), 'r'],
                1 => ['file', $log, 'a'],
                2 => ['file', $log, 'a'],
            ],
            $pipes,
            $this->root,
            $environment,
        );

        if (! is_resource($process)) {
            return 1;
        }

        return proc_close($process);
    }

    /**
     * Find the Suwayomi jar, wherever the install put it.
     *
     * A short glob rather than a fixed path, because the shape of the runtime directory is
     * phase 1's decision and this should not have to change when it is made. Returns null
     * rather than throwing so the caller can say something about the whole install rather than
     * about one missing file.
     */
    private function locateJar(): ?string
    {
        $matches = glob($this->path('suwayomi', '*.jar'))
            ?: glob($this->path('suwayomi', 'runtime', '*.jar'))
            ?: [];

        $jars = array_values(array_filter(
            $matches,
            static fn (string $path): bool => ! str_contains(basename($path), 'tachiyomi-'),
        ));

        return $jars[0] ?? null;
    }

    /**
     * Make sure the data directory exists, is outside the install, and is writable.
     *
     * Before anything starts, and before the lock is taken, because every one of these is a
     * problem the reader can fix and none of them should be discovered halfway through bringing
     * four processes up.
     *
     * @param  callable(string): void  $say
     */
    /**
     * Run the layout step alone, for the build to verify an install with.
     *
     * Exists because the build needs to know that a finished install can be laid out and
     * configured, and the only trustworthy way to ask that is to run the same code the launcher
     * runs. Reimplementing the token substitution inside the build instead, which is what a build
     * script naturally reaches for, proves the build's own copy of the behaviour rather than the
     * product's, and the two drift apart the first time either is edited.
     *
     * Needs no JVM, no port and no Suwayomi, which is what makes it safe to call from a build.
     * The data directory passed in should be a throwaway: this writes a resolved ini, a Caddyfile
     * and a real `.env` with a generated APP_KEY into it.
     */
    /**
     * The ports this install uses, for the health command.
     *
     * Read from the supervisor rather than re-derived, because the launcher is the only thing that
     * knows them. A health command that guessed 4567 and 8402 would report a Suwayomi that had
     * chosen a different free port as missing, which is how a diagnostic becomes a second source
     * of truth and disagrees with the first.
     */
    public function suwayomiPort(): int
    {
        return $this->suwayomiPort;
    }

    public function appPort(): int
    {
        return $this->appPort;
    }

    public function logDirectory(): string
    {
        return $this->logDirectory;
    }

    public function dataDirectory(): string
    {
        return $this->dataDirectory;
    }

    /**
     * Where this install's database actually is.
     *
     * Read out of the `.env` the launcher wrote, not derived. Guessing a path is how health came
     * to report a database as missing while `/ready` reported the same database open and healthy:
     * the real file is `<data>/database.sqlite`, not `<data>/database/database.sqlite`. A
     * diagnostic that guesses is a second source of truth, and it disagreed with the first.
     *
     * Falls back to the data directory root, which is where it actually lives, so a missing or
     * unreadable `.env` degrades to the right answer rather than to a wrong one.
     */
    public function databaseFile(): string
    {
        $env = $this->environmentFile();

        if (is_file($env)) {
            $contents = (string) @file_get_contents($env);

            // Single quotes first, because that is what papyrus:configure writes for a path with
            // a space in it, and double quotes are not what it emits. Unquoted is last because it
            // cannot hold a path with whitespace anyway.
            foreach (["/^DB_DATABASE\s*=\s*'(.*)'\s*$/m", '/^DB_DATABASE\s*=\s*"(.*)"\s*$/m', '/^DB_DATABASE\s*=\s*(\S+)\s*$/m'] as $pattern) {
                if (preg_match($pattern, $contents, $matches) === 1 && trim($matches[1]) !== '') {
                    return trim($matches[1]);
                }
            }
        }

        return $this->dataDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
    }

    /**
     * Whether one of the four processes is actually up, whoever started it.
     *
     * ## Why this cannot ask this object
     *
     * `$started` only holds processes this particular Supervisor instance launched. A `health` or
     * `status` process is a different instance, so it has an empty array and every worker looks
     * dead. The first run of `papyrus health` against a live install reported both workers as
     * NOT RUNNING while `/ready` returned 200 and both processes were verifiably alive.
     *
     * So the question is answered from the operating system instead: the launcher passes the
     * command line each worker is started with, and any process running that command under this
     * install is that worker. That is true across sessions, which is the case that matters, since
     * a reader running `health` is by definition in a different session from the one that started
     * Papyrus.
     */
    public function isRunning(string $name): bool
    {
        if (isset($this->started[$name]) && $this->started[$name]->isRunning()) {
            return true;
        }

        $marker = match ($name) {
            'queue' => 'queue:work',
            'scheduler' => 'schedule:work',
            'suwayomi' => basename($this->locateJar() ?? 'Suwayomi-Server'),
            'web' => 'frankenphp',
            default => null,
        };

        if ($marker === null) {
            return false;
        }

        /*
         * A temporary script file, run with `-File`, rather than `-Command`.
         *
         * `-Command` needs the PowerShell source wrapped in double quotes for the shell, and the
         * source itself contains double quotes in both the WMI filter and the `-like` pattern.
         * Escaping that nest correctly is possible and was tried, and it silently returned nothing
         * on a machine where the identical command worked when pasted into a shell, which is the
         * worst possible failure: health reporting both workers dead while they were running.
         *
         * `-File` has no quoting layer at all. The script takes the pattern as an argument, so
         * there is exactly one place a value could need escaping and it is not inside a string in
         * two languages at once.
         *
         * WMIC was the cheap alternative and is gone: this machine has no `wmic` at all, so the
         * obvious implementation reports everything dead on any Windows build that has deprecated
         * it. `health` is asked for precisely when something is already wrong, and a diagnostic
         * that reports all four processes broken because its own query tool is missing is worse
         * than one that is slow.
         */
        $script = tempnam(sys_get_temp_dir(), 'papyrus-probe-');

        if ($script === false) {
            return false;
        }

        file_put_contents($script, implode(PHP_EOL, [
            'param([string]$Pattern)',
            '$ErrorActionPreference = "SilentlyContinue"',
            'Get-CimInstance Win32_Process |',
            '  Where-Object { $_.CommandLine -and $_.CommandLine.Contains($Pattern) } |',
            '  Select-Object -First 1 -ExpandProperty ProcessId',
        ]));

        $output = [];
        $status = 1;

        @exec(
            sprintf(
                'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File %s %s 2>NUL',
                escapeshellarg($script),
                escapeshellarg($marker),
            ),
            $output,
            $status,
        );

        @unlink($script);

        foreach ($output as $line) {
            if (trim($line) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * What `/ready` says, as the health command needs it.
     *
     * Returns null when nothing is listening, which is different from a 503, which is the app
     * answering and refusing. The distinction is the whole reason this returns the status code
     * rather than a boolean.
     *
     * @return array{status: int, checks: array{key: bool, database: bool, pending_migrations: int}}|null
     */
    public function readiness(): ?array
    {
        if (! $this->prober->isPortTaken($this->appPort)) {
            return null;
        }

        $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);

        $response = @file_get_contents(
            sprintf('http://127.0.0.1:%d/ready', $this->appPort),
            false,
            $context,
        );

        if (! is_string($response)) {
            return null;
        }

        $status = 0;

        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $matches) === 1) {
            $status = (int) $matches[1];
        }

        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            return ['status' => $status, 'checks' => ['key' => false, 'database' => false, 'pending_migrations' => -1]];
        }

        return [
            'status' => $status,
            'checks' => [
                'key' => (bool) ($decoded['checks']['key'] ?? false),
                'database' => (bool) ($decoded['checks']['database'] ?? false),
                'pending_migrations' => (int) ($decoded['checks']['pending_migrations'] ?? -1),
            ],
        ];
    }

    /** The summary log, for the health command and for the messages `start` writes. */
    public function summary(): SummaryLog
    {
        return $this->summary ??= new SummaryLog($this->logDirectory);
    }

    public function prepareLayoutForBuild(): void
    {
        $this->prepareLayout(static function (string $line): void {
            // The build reports its own steps, so this is deliberately silent.
        });
    }

    private function prepareLayout(callable $say): void
    {
        $layout = new InstallLayout($this->root, $this->dataDirectory);

        $made = $layout->prepare($say);

        if ($made !== []) {
            $say('created '.count($made).' directories for this install');
        }

        $this->resolveWebConfig();

        $this->resolvePhpConfig();

        /*
         * The .env is written here rather than in a separate configure step, because the web
         * server cannot answer `/ready` without an app key and the launcher has nothing to poll
         * until there is one.
         *
         * These are written as single `--name=value` arguments rather than as a name and a value
         * in separate array entries. An associative array reads as though it would produce
         * `--name value`, and it does, which works only while the option is a plain value option.
         * `--env` was not: Symfony Console defines it as a global application option, so the
         * application took the name and the path arrived as a stray argument, and the launcher
         * refused to start. The command's own tests never saw it because `$this->artisan()`
         * bypasses argv entirely.
         */
        if (is_file($this->ini()) && is_file($this->path('artisan'))) {
            $this->runArtisan(
                ['papyrus:configure'],
                extra: [
                    '--env-file='.$this->environmentFile(),
                    '--data='.$this->dataDirectory,
                    '--suwayomi='.sprintf('http://127.0.0.1:%d', $this->suwayomiPort),
                ],
                logName: 'configure.log',
            );
        }
    }

    /**
     * Write the web server's configuration with this install's paths and ports filled in.
     *
     * Done here rather than at install time so that the launcher is the only thing holding a
     * port number. A config file with the port baked into it is a second source of truth, and
     * when the two disagree the launcher polls a port nothing is listening on while the server
     * serves perfectly, and reports that the web server never became ready.
     *
     * Written to the data directory rather than into the install, so an install folder can be
     * read-only and so the resolved file is regenerated rather than being stale state.
     */
    private function resolveWebConfig(): void
    {
        $template = $this->caddyfile();

        $target = $this->dataDirectory.DIRECTORY_SEPARATOR.'frankenphp'.DIRECTORY_SEPARATOR.'Caddyfile';

        @mkdir(dirname($target), 0o777, true);

        $resolved = str_replace(
            ['{{APP_ROOT}}', '{{APP_PORT}}', '{{SUWAYOMI_PORT}}', '{{LOGS}}'],
            [
                // Forward slashes, because the template joins with them for Linux and macOS and
                // a Windows path arriving with backslashes produces a mixed path that Caddy
                // accepts at startup and then cannot find a document root under.
                str_replace('\\', '/', $this->root),
                (string) $this->appPort,
                (string) $this->suwayomiPort,
                str_replace('\\', '/', $this->logDirectory),
            ],
            (string) file_get_contents($template),
        );

        file_put_contents($target, $resolved);

        $this->resolvedConfig = $target;
    }

    /**
     * Write the php.ini for this install, with its paths filled in.
     *
     * Written into the data directory rather than over the shipped template, for the same
     * reason the Caddyfile is: the install directory can be read only, because it may live under
     * Program Files, and a launcher that cannot write its own configuration is a launcher that
     * fails only on a machine installed the way the installer intends.
     *
     * `{{RUNTIME}}` points back into the install rather than the data directory. That looks like
     * an inconsistency and is not: the extensions are only ever read, so a read only install
     * directory is exactly right for them, and copying 130MB of DLLs into the data directory on
     * every start to satisfy a path that is only read would be the worse trade.
     */
    private function resolvePhpConfig(): void
    {
        $template = $this->iniTemplate();

        $target = $this->dataDirectory.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'php.ini';

        @mkdir(dirname($target), 0o777, true);

        $resolved = str_replace(
            ['{{RUNTIME}}', '{{LOGS}}', '{{TEMP}}'],
            [
                // Forward slashes, matching the Caddyfile. An ini path is read by the C runtime
                // rather than by PHP's own path handling, so a mixed path is at best a warning.
                str_replace('\\', '/', $this->path('runtime')),
                str_replace('\\', '/', $this->logDirectory),
                str_replace('\\', '/', $this->dataDirectory.DIRECTORY_SEPARATOR.'tmp'),
            ],
            (string) file_get_contents($template),
        );

        $this->refuseUnresolvedTokens($resolved, $target);

        file_put_contents($target, $resolved);

        $this->resolvedIni = $target;
    }

    /**
     * Refuse to start on an ini that still contains a template token.
     *
     * This exists because the failure it prevents is silent. PHP does not reject
     * `extension_dir = "{{RUNTIME}}/ext"`. It treats it as a literal directory, fails to load
     * every extension in it, prints a warning to stderr that the launcher is not reading, and
     * serves an app that cannot reach its database. Every clue points at the database, which is
     * why this check exists rather than a comment warning it off.
     *
     * Comment lines are skipped, because the shipped ini explains the templating in a comment
     * that mentions the token syntax by name. Checking the whole file would refuse to start on
     * a perfectly correct configuration.
     */
    private function refuseUnresolvedTokens(string $resolved, string $target): void
    {
        foreach (preg_split('#\R#', $resolved) ?: [] as $line) {
            if (str_starts_with(ltrim($line), ';')) {
                continue;
            }

            if (preg_match('#\{\{[A-Z_]+\}\}#', $line) === 1) {
                throw new RuntimeException(sprintf(
                    'The PHP configuration resolved into %s still contains a template token: %s',
                    $target,
                    trim($line),
                ));
            }
        }
    }

    private function takeLock(): void
    {
        @mkdir(dirname($this->lockPath()), 0o777, true);

        $lock = new InstanceLock($this->lockPath());

        if (! $lock->acquire()) {
            throw new RuntimeException(sprintf(
                'Another Papyrus launcher already owns this install (%s). Close it first, or use '
                .'that one.',
                $lock->describe(),
            ));
        }

        $this->lock = $lock;
    }

    private function lockPath(): string
    {
        return $this->dataDirectory.DIRECTORY_SEPARATOR.'launcher.lock';
    }

    private function path(string ...$parts): string
    {
        return $this->root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
    }
}
