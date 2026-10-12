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

    /**
     * Asks the operating system which processes are this install's.
     *
     * A property rather than a constructor argument because almost nothing needs it: `start` has
     * handles for everything it started and never asks. Only `stop` from a second process does,
     * and tests need to be able to answer without one.
     */
    private ProcessFinder $finder;

    public function useProcessFinder(?ProcessFinder $finder): void
    {
        $this->finder = $finder ?? new ProcessFinder;
    }

    /**
     * Overrides how process liveness is determined, for tests.
     *
     * @param  (callable(string): bool)|null  $probe  Null restores the real answer.
     */
    public function useLivenessProbe(?callable $probe): void
    {
        $this->livenessProbe = $probe;
    }

    /** @var (callable(string): bool)|null */
    private $livenessProbe = null;

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
        $this->finder = new ProcessFinder;
    }

    /**
     * The tokens that identify one of the four processes as belonging to *this* install.
     *
     * Two per process, and never one. The data directory is what stops a `stop` reaching into
     * another install, and the role marker is what stops it reaching into this install's web
     * server when it was asked for the queue worker.
     *
     * ## Why the data directory and not the install root
     *
     * Because two launchers can share one install root with different data directories: the
     * lock lives in the data directory, so they do not exclude each other, and both start. Their
     * four processes then carry an identical install root, so a matcher keyed on it would have
     * `stop` on one install terminate the workers of the other, which is the worst thing a stop
     * can do.
     *
     * The data directory cannot collide that way, because it is where the lock is. And every one
     * of the four carries it on its own command line, which is the only place a matcher can look:
     *
     *     queue     php -c <DATA>\php\php.ini <ROOT>\artisan queue:work
     *     scheduler php -c <DATA>\php\php.ini <ROOT>\artisan schedule:work
     *     web       frankenphp run --config <DATA>\frankenphp\Caddyfile
     *     suwayomi  java -D...rootDir=<DATA>\suwayomi -jar <ROOT>\suwayomi\....jar
     *
     * Returned as a list rather than joined into one string, because on Windows every argument is
     * quoted and so a joined string containing a space can never match. Measured here rather than
     * assumed; see {@see ProcessFinder}.
     *
     * @return list<string>
     */
    private function tokensFor(string $name): array
    {
        $role = match ($name) {
            'queue' => 'queue:work',
            'scheduler' => 'schedule:work',
            // The resolved config rather than the executable name, because `--config` names this
            // install's resolved Caddyfile, which lives in the data directory, and every other
            // frankenphp on the machine is somebody else's business.
            'web' => 'frankenphp',
            // The jar's own name rather than the Suwayomi directory, because the directory is
            // named `suwayomi` and a reader who keeps their library there would have any process
            // with that word on its command line matched.
            'suwayomi' => $this->locateJar() !== null ? basename((string) $this->locateJar()) : null,
            default => null,
        };

        if ($role === null) {
            return [];
        }

        // The data directory exactly as this install knows it, with no normalisation at all.
        //
        // Normalising the separators to forward slashes looked tidy and was wrong, measured
        // against a live worker. `proc_open` puts the path on the child's command line as it was
        // given, so what CIM holds is the backslash form:
        //
        //     "...php.exe" "-c" "...\php.ini" "D:\Program Files\Papyrus\artisan" "queue:work"
        //
        // A forward slash token matches none of that and finds nothing, silently. A token that
        // finds nothing is the same output as a token that found a machine with nothing running,
        // which is why this could have shipped looking correct.
        return [$this->dataDirectory, $role];
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
     * The ini, for a caller outside this class that has to start a PHP process.
     *
     * {@see Health} does, and it cannot guess: the resolved ini lives in the data directory, its
     * path depends on the layout, and a caller that reconstructed it would be a third
     * implementation of "where the ini is". Public so that there is one answer rather than two.
     */
    public function resolvedIniPath(): string
    {
        return $this->ini();
    }

    /**
     * The packaged PHP, for a caller that has to start it.
     *
     * {@see Health} does, and the reason is that the launcher's own interpreter cannot read the
     * queue table: it loads no ini, so it has no `pdo_sqlite`. Asking the install's own PHP means
     * the diagnostic uses the same interpreter and the same extensions the application does, rather
     * than a second arrangement that answers a slightly different question.
     */
    public function phpExecutable(): string
    {
        return $this->phpExecutable
            ?? $this->path('runtime', 'bin', 'php.exe');
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

            $this->assertSuwayomiIsReadable($say);
            $summary->record('suwayomi', 'accepts this install');

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
     * Stop everything this install is running, newest first, and report what each needed.
     *
     * Both halves matter, and the second half is the one that was missing.
     *
     * Processes this process started are stopped through their handles, which is the polite
     * path and the only one that can ask a process to unwind. Processes another process started
     * cannot be reached that way at all, because {@see ManagedProcess::stop()} needs a
     * `proc_open` handle and a `stop` command from a reader has none.
     *
     * That made `stop` work only inside the exact process that ran `start`, while its own help
     * text described it as the command that brings everything down when the application is too
     * broken to tell you what happened. The sandbox gate is what caught it: it started the
     * install, ran `stop` from a second process, and found both ports still held and the JVM
     * still alive, with the launcher reporting that nothing had been started by this session.
     *
     * So the second half asks the operating system which processes are this install's, matched
     * on the install root **and** the role marker together. Both, because on its own a role
     * marker like `queue:work` matches any Laravel worker on the machine, and killing a
     * developer's own checkout because a reader typed `stop` is the worst thing this could do.
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

        /*
         * The four in the order stopAll has always used, and for the same reason: scheduler and
         * queue are the ones writing to the database, so they go first.
         *
         * Skipped entirely when this process held the handle for that name. Re-finding a process
         * that has just been stopped politely and terminating it anyway would report a kill for
         * every process on every stop, which turns the one line a reader sends with a bug report
         * into four that say the wrong thing.
         */
        foreach (['scheduler', 'queue', 'web', 'suwayomi'] as $name) {
            if (isset($outcomes[$name])) {
                continue;
            }

            foreach ($this->finder->find($this->tokensFor($name)) as $pid) {
                $outcomes[$name] = $this->finder->terminate($pid);
                $say(sprintf('%s: %s (pid %d, found rather than started here)', $name, $outcomes[$name], $pid));
            }
        }

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

        /*
         * The root directory and the port are both set before the JVM is started, and both have to
         * be. Pointed at a root that does not exist, Suwayomi dies inside
         * ApplicationRootDirKt.getApplicationRootDir before reading anything, which looks exactly
         * like the property below being ignored.
         *
         * TACHIDESK_DIR used to be passed here. Suwayomi does not read it: its root comes from the
         * system property in suwayomiConfiguration(), and with only the environment variable set a
         * reader's library went to their Windows profile while this data directory sat empty.
         */
        (new SuwayomiConfiguration)->prepare($this->dataDirectory, $this->suwayomiPort);

        $process = new ManagedProcess(
            name: 'suwayomi',
            executable: $java,
            /*
             * `-Duser.timezone` is here because the JVM would otherwise follow the machine while
             * PHP follows the ini, and the two disagreeing makes every Suwayomi timestamp in a
             * diagnostics bundle wrong by the reader's offset. See machineTimezone().
             */
            arguments: [
                '-Duser.timezone='.$this->machineTimezone(),
                '-D'.SuwayomiConfiguration::ROOT_PROPERTY.'='.(new SuwayomiConfiguration)->rootDirectory($this->dataDirectory),
                '-jar',
                $jar,
            ],
            workingDirectory: $this->dataDirectory,
            logFile: $this->logDirectory.DIRECTORY_SEPARATOR.'suwayomi.log',
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
     *
     * The Suwayomi credentials travel with it as well. An unattended install supplies them as
     * environment variables and never writes them to disk, so without this the application would
     * see the URL the launcher resolved and none of the credentials, and would go back to refusing
     * every query for a reason it could not name.
     *
     * @return array<string, string>
     */
    private function applicationEnvironment(): array
    {
        return ['PAPYRUS_ENV' => $this->environmentFile()] + (new SuwayomiCredentials(
            environmentFile: $this->environmentFile(),
            environment: $this->launcherEnvironment(),
        ))->childEnvironment();
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
     * Refuse to start when this install cannot read anything from Suwayomi.
     *
     * Placed between Suwayomi coming up and the database being migrated, because either side of
     * that gap is too late or too early. Before it, Suwayomi is not answering and the answer would
     * be meaningless. After it, the web server is serving a library that says every series is
     * missing, and the reader has to work out why.
     *
     * The refusal is not the interesting part; the fact that nothing else in the launcher notices
     * this is. `suwayomiAnswers()` above returns true against a Suwayomi that will refuse every
     * query the application makes, because it asks `aboutServer`, which is not `@RequireAuth`.
     * That check and `papyrus health` both stayed green through a whole evening of this.
     */
    private function assertSuwayomiIsReadable(callable $say): void
    {
        $say('checking that Suwayomi will answer this install');

        $refusal = (new SuwayomiCredentials(
            environmentFile: $this->environmentFile(),
            environment: $this->launcherEnvironment(),
            probe: fn (string $query): ?string => $this->suwayomiGraphQL($query),
        ))->refusal();

        /*
         * Null means either "there is no problem" or "there was no way to find out", and both let
         * the install start. Refusing on an inconclusive probe would stop a working install over a
         * network blip, which is a worse failure than the one being guarded against.
         */
        if ($refusal === null) {
            return;
        }

        throw new RuntimeException(PHP_EOL.$refusal.PHP_EOL);
    }

    /**
     * The launcher's own environment, as an array.
     *
     * Read once and passed in rather than have SuwayomiCredentials reach for `getenv()` itself, so
     * the precedence between an inherited variable and the `.env` can be tested by handing it a
     * different array rather than by mutating the process a test suite is sharing.
     *
     * @return array<string, string>
     */
    private function launcherEnvironment(): array
    {
        $values = [];

        foreach (['SUWAYOMI_USERNAME', 'SUWAYOMI_PASSWORD'] as $name) {
            $value = getenv($name);

            if (is_string($value)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * One unauthenticated GraphQL POST, returning the body or null when it could not be made.
     *
     * Null rather than an empty string, because "Suwayomi did not answer" and "Suwayomi answered
     * with nothing" lead to different conclusions and collapsing them makes the first look like the
     * second, which is a pass rather than a refusal.
     */
    private function suwayomiGraphQL(string $query): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode(['query' => $query]),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(
            sprintf('http://127.0.0.1:%d/api/graphql', $this->suwayomiPort),
            false,
            $context,
        );

        return is_string($response) ? $response : null;
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
            PHP_EOL.'  the application is answering and reporting that it is not ready (%s).'.PHP_EOL
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
    /**
     * @param  list<string>  $arguments
     * @param  list<string>  $extra
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
        $environment = getenv() ?: [];

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

    /**
     * Where this install lives, as opposed to where it keeps its data.
     *
     * The two are different directories in a packaged install and the same one in a checkout, and
     * a diagnostics report that named only the data directory would send a reader looking in the
     * wrong place for the reason they installed it.
     */
    public function environmentPath(): string
    {
        return $this->root;
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

        /*
         * An injected liveness answer, before the operating system is asked.
         *
         * The OS query is correct and unfakeable in production, which makes it untestable: a
         * health test asserting that a missing worker is reported as missing would find this
         * machine's real workers and report them as running. It did exactly that, and three tests
         * failed for a reason that had nothing to do with the code under test.
         */
        if ($this->livenessProbe !== null) {
            return ($this->livenessProbe)($name);
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
            ['{{RUNTIME}}', '{{LOGS}}', '{{TEMP}}', '{{TIMEZONE}}'],
            [
                // Forward slashes, matching the Caddyfile. An ini path is read by the C runtime
                // rather than by PHP's own path handling, so a mixed path is at best a warning.
                str_replace('\\', '/', $this->path('runtime')),
                str_replace('\\', '/', $this->logDirectory),
                str_replace('\\', '/', $this->dataDirectory.DIRECTORY_SEPARATOR.'tmp'),
                $this->machineTimezone(),
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
    /**
     * The one timezone all four processes use, which is the launcher's own.
     *
     * ## Why the JVM follows the launcher rather than the other way round
     *
     * Because they disagreed. The JVM was started with no timezone flag, so it followed the
     * machine, while the shipped ini pinned PHP to UTC. On any machine not on UTC that put five
     * hours between Papyrus's own log and Suwayomi's for the same few seconds, and every Suwayomi
     * timestamp in a diagnostics bundle was shifted by the reader's offset while looking precise.
     *
     * ## Why the launcher, and not the machine
     *
     * Two reasons, and the first is decisive. Windows will not answer in a form PHP can use: both
     * `tzutil /g` and the `TimeZoneInformation` registry key answer with a display name, "SA
     * Pacific Standard Time", which `timezone_open` rejects and which would have made the resolved
     * ini refuse to start. Asking the machine was tried and does not work.
     *
     * The second is ordering. The launcher resolves the ini that the *workers* load, so its own
     * zone is the only value available before that ini exists. Making it the single source means
     * the launcher, both workers and the JVM all report the same clock, which is the property that
     * actually matters: a reader comparing Papyrus's log with Suwayomi's can trust the order.
     *
     * On a packaged install, which loads no ini, that is UTC. The trade is that a reader not on
     * UTC sees Suwayomi logging in UTC rather than in local time. It is stated rather than hidden:
     * every collected timestamp carries its offset, and `environment.txt` reports the zone.
     */
    private function machineTimezone(): string
    {
        return date_default_timezone_get() ?: 'UTC';
    }

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
