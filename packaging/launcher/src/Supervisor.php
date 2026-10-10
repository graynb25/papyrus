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
        $this->prepareLayout($say);
        $this->takeLock();

        try {
            $this->startSuwayomi($say);
            $this->migrate($say);
            $this->startWebServer($say);
            $this->startWorkers($say);

            return array_keys($this->started);
        } catch (\Throwable $e) {
            // A half started install is worse than a stopped one, so unwind whatever came up
            // before rethrowing. Leaving Suwayomi running with no web server is the state a
            // reader cannot diagnose and we can.
            $this->stopAll($say);

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
            environment: ['PHPRC' => $this->ini()],
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
        );

        $schedule = new ManagedProcess(
            name: 'scheduler',
            executable: $php,
            arguments: ['-c', $this->ini(), $this->path('artisan'), 'schedule:work'],
            workingDirectory: $this->root,
            logFile: $this->logDirectory.DIRECTORY_SEPARATOR.'scheduler.log',
        );

        $queue->start();
        $schedule->start();

        $this->started['queue'] = $queue;
        $this->started['scheduler'] = $schedule;

        $say('queue worker and scheduler started');
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

        return is_string($response) && str_contains($response, '"ready"');
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
    private function prepareLayout(callable $say): void
    {
        $layout = new InstallLayout($this->root, $this->dataDirectory);

        $made = $layout->prepare($say);

        if ($made !== []) {
            $say('created '.count($made).' directories for this install');
        }

        $this->resolveWebConfig();

        /*
         * The .env is written here rather than in a separate configure step, because the web
         * server cannot answer `/ready` without an app key and the launcher has nothing to poll
         * until there is one.
         */
        if (is_file($this->ini()) && is_file($this->path('artisan'))) {
            $this->runArtisan(
                ['papyrus:configure'],
                extra: [
                    '--env' => $this->dataDirectory.DIRECTORY_SEPARATOR.'.env',
                    '--data' => $this->dataDirectory,
                    '--suwayomi' => sprintf('http://127.0.0.1:%d', $this->suwayomiPort),
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
