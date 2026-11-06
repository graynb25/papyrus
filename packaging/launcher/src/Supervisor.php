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

    /** How many migrations the database has that this build does not know. */
    private int $schemaAhead = 0;

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
        ?Translator $translator = null,
    ) {
        $this->prober = $prober ?? new Prober;
        $this->translator = $translator ?? new Translator;
        $this->finder = new ProcessFinder;
    }

    /** The messages this install speaks, shared with the commands it starts. */
    private readonly Translator $translator;

    public function translator(): Translator
    {
        return $this->translator;
    }

    /**
     * What identifies one of the four processes as belonging to *this* install.
     *
     * A query has three parts: the image name, the executable path, and the command line markers.
     * The name and path are exact anchors, and the markers are what distinguish this install from
     * another one running the same program.
     *
     * ## Why the anchors exist
     *
     * Because the old matcher was a list of substrings, and the web marker was the literal
     * `frankenphp`. The data directory contains `...\Papyrus\frankenphp\Caddyfile`, so any process
     * whose command line named that file matched, including a text editor, a diff tool or a backup
     * tool. `stop` then ran `taskkill /T /F` against it. The image name and the executable path
     * cannot be satisfied by an editor, because an editor is `notepad.exe` in `C:\Windows`.
     *
     * ## Why the data directory and not the install root
     *
     * Because two launchers can share one install root with different data directories: the
     * lock lives in the data directory, so they do not exclude each other, and both start. Their
     * four processes then carry an identical install root, so a matcher keyed only on it would have
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
     * The install root is still used for the executable path, which is an anchor rather than an
     * identity: it says which program, and the data directory says which install.
     *
     * @return array{}|array{name: string|null, executable: string|null, tokens: list<string>}
     */
    private function specFor(string $name): array
    {
        $php = $this->phpExecutable ?? $this->path('runtime', 'bin', 'php.exe');
        $frankenphp = $this->frankenphpExecutable ?? $this->path('runtime', 'bin', 'frankenphp.exe');
        $java = $this->javaExecutable ?? $this->path(
            'suwayomi',
            'runtime',
            'bin',
            PHP_OS_FAMILY === 'Windows' ? 'java.exe' : 'java',
        );

        // The resolved ini and Caddyfile live in the data directory. A `stop` process has not run
        // the layout step, so it cannot ask `$this->ini()` or `$this->caddyfile()` without either
        // writing the file or throwing; the path is the same one both methods resolve to.
        $ini = $this->dataDirectory.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'php.ini';
        $caddyfile = $this->dataDirectory.DIRECTORY_SEPARATOR.'frankenphp'.DIRECTORY_SEPARATOR.'Caddyfile';

        $jar = $this->suwayomiJar ?? $this->locateJar();

        return match ($name) {
            'queue' => $this->spec($php, [$this->dataDirectory, $ini, 'queue:work']),
            'scheduler' => $this->spec($php, [$this->dataDirectory, $ini, 'schedule:work']),
            'web' => $this->spec($frankenphp, [$this->dataDirectory, $caddyfile]),
            // No jar means nothing this launcher can call Suwayomi. An empty query matches
            // nothing, which is the correct answer rather than a warning.
            'suwayomi' => $jar === null ? [] : $this->spec($java, [$this->dataDirectory, $jar]),
            'kcef' => $this->spec(
                PHP_OS_FAMILY === 'Windows' ? 'jcef_helper.exe' : 'jcef_helper',
                [$this->dataDirectory],
                executable: false,
            ),
            default => [],
        };
    }

    /**
     * @param  list<string>  $tokens
     * @param  bool  $executable  False for a process launched out of the data directory, whose own
     *                            path is not a stable anchor because it is extracted on first run.
     * @return array{name: string|null, executable: string|null, tokens: list<string>}
     */
    private function spec(string $program, array $tokens, bool $executable = true): array
    {
        return [
            'name' => basename($program),
            'executable' => $executable ? $program : null,
            'tokens' => $tokens,
        ];
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
     * The ini to hand a PHP process, which is never the template.
     *
     * ## Why this resolves rather than answering
     *
     * Because the template carries `{{RUNTIME}}` in its `extension_dir`, and a PHP process started
     * against it loads no extensions at all. Measured on the reader's own install, packaged runtime:
     *
     *     php.exe -c <ROOT>\runtime\php\php.ini   ->  (no pdo_sqlite, no sqlite3)
     *     php.exe -c <DATA>\php\php.ini           ->  pdo_sqlite, sqlite3
     *
     * So "where is the ini" and "which ini" were two different questions and only one of them was
     * being asked. This used to return `$this->ini()`, which is the template unless `start` has
     * already resolved it into the data directory, and only `start` resolves it.
     *
     * The result was that `health`, `restore` and `snapshot` were all handed an unresolved template
     * by a caller that would then run a real PHP against it. The first snapshot on the reader's
     * install failed with:
     *
     *     Unable to load dynamic library 'php_pdo_sqlite.dll'
     *       (tried: {{RUNTIME}}/ext\php_pdo_sqlite.dll ...)
     *
     * `Health` had already hit this and worked around it by rebuilding the resolved path itself,
     * which is lesson 225: a fix applied to one occurrence of a pattern leaves the other occurrences
     * in place, and the other occurrences are nearby. There is one answer here now, and it is here.
     *
     * The on-disk file is preferred rather than rewritten, so a diagnostic stays a diagnostic and
     * does not write to the install it is reporting on. Substitution only happens when there is no
     * resolved ini at all, which is a fresh install, which is exactly when `start` needs it.
     */
    public function resolvedIniPath(): string
    {
        if ($this->resolvedIni === null) {
            $existing = $this->dataDirectory.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'php.ini';

            $this->resolvedIni = is_file($existing) ? $existing : null;

            if ($this->resolvedIni === null) {
                $this->resolvePhpConfig();
            }
        }

        $ini = $this->ini();

        if (str_contains($ini, '{{')) {
            /*
             * Refused rather than returned.
             *
             * A caller will hand this straight to `php.exe -c`, and PHP does not fail to start over an
             * ini it cannot make sense of. It starts, loads nothing, and answers every question with a
             * confident wrong value. Returning a path that is known to be unusable here would put
             * that decision somewhere the message cannot reach.
             */
            throw new RuntimeException($this->translator->get('supervisor.ini_unresolved', [
                'path' => $ini,
            ]));
        }

        return $ini;
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
            $this->translator->get('supervisor.missing_php_config'),
            $this->translator->get('supervisor.php_config_consequence'),
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
            $this->translator->get('supervisor.missing_web_config'),
            $this->translator->get('supervisor.web_config_consequence'),
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
            throw new RuntimeException($this->translator->get('supervisor.missing_file', [
                'what' => $what,
                'path' => $path,
                'consequence' => $consequence,
            ]));
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
            $summary->record('web', sprintf('serving on %s, ready', $this->readerUrl()));

            $this->startWorkers($say);
            $summary->record('papyrus', 'queue worker and scheduler started');

            $summary->record('papyrus', sprintf('ready at %s', $this->readerUrl()));

            return array_keys($this->started);
        } catch (\Throwable $e) {
            // A half started install is worse than a stopped one, so unwind whatever came up
            // before rethrowing. Leaving Suwayomi running with no web server is the state a
            // reader cannot diagnose and we can.
            try {
                $this->stopAll($say);
            } catch (ProcessProbeFailed $probeFailure) {
                /*
                 * The reason the start failed is what the reader has to act on. A probe that
                 * cannot read the process list during cleanup is part of that failure, not a
                 * replacement for it, so both messages travel and the original stays the cause.
                 */
                $e = new RuntimeException(
                    $e->getMessage().' '.$probeFailure->getMessage(),
                    (int) $e->getCode(),
                    $e,
                );
            }

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
     *
     * @throws ProcessProbeFailed When the operating system process list could not be read.
     */
    public function stopAll(?callable $say = null): array
    {
        $say = $say ?? static fn (string $line): null => null;

        $outcomes = [];

        foreach (array_reverse(array_keys($this->started), true) as $name) {
            $process = $this->started[$name];
            $outcome = $process->stop();

            $outcomes[$name] = $outcome;
            $say($name.': '.$this->outcome($outcome));
        }

        $this->started = [];

        /*
         * The four in the order stopAll has always used, and for the same reason: scheduler and
         * queue are the ones writing to the database, so they go first.
         *
         * Then the embedded browser, which belongs to Suwayomi and so goes after Suwayomi itself.
         * It is last because it is the one that is usually already gone, and a name that finds
         * nothing prints nothing.
         *
         * Skipped entirely when this process held the handle for that name. Re-finding a process
         * that has just been stopped politely and terminating it anyway would report a kill for
         * every process on every stop, which turns the one line a reader sends with a bug report
         * into four that say the wrong thing.
         */
        foreach (['scheduler', 'queue', 'web', 'suwayomi', 'kcef'] as $name) {
            if (isset($outcomes[$name])) {
                continue;
            }

            foreach ($this->finder->find($this->specFor($name)) as $pid) {
                $outcomes[$name] = $this->finder->terminate($pid);
                $say($this->translator->get('supervisor.outcome.found', [
                    'name' => $name,
                    'outcome' => $this->outcome($outcomes[$name]),
                    'pid' => $pid,
                ]));
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
            $incomplete = array_filter(
                $outcomes,
                static fn (string $outcome): bool => $outcome === 'still running' || $outcome === 'could not be stopped',
            );

            $this->summary()->record('papyrus', sprintf(
                '%s, %d process%s: %s',
                $incomplete === [] ? 'stopped cleanly' : 'stop incomplete',
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
             * A listener on this port is not automatically this install's Suwayomi.
             *
             * It can be a second Windows account, or any process in another user's session, that
             * bound 4567 first. The application then sends the login mutation with the configured
             * username and password to that listener and accepts whatever it answers for library,
             * search and cover requests. So the owner is checked before adoption, and a port whose
             * owner cannot be identified is refused rather than assumed to be friendly.
             */
            $holder = $this->prober->portHolder($this->suwayomiPort);

            if (! $this->isThisInstallsSuwayomi($holder)) {
                throw new RuntimeException($this->translator->get('supervisor.suwayomi_port_other', [
                    'port' => $this->suwayomiPort,
                    'holder' => $holder === null ? '' : $this->translator->get('supervisor.suwayomi_port_holder', [
                        'name' => $holder['name'],
                        'pid' => $holder['pid'],
                    ]),
                ]));
            }

            $say($this->translator->get('supervisor.using_suwayomi'));

            return;
        }

        $java = $this->javaExecutable ?? $this->path('suwayomi', 'runtime', 'bin', 'java.exe');

        if (! is_file($java)) {
            $java = $this->path('suwayomi', 'runtime', 'bin', 'java');
        }

        $jar = $this->suwayomiJar ?? $this->locateJar();

        if ($jar === null) {
            throw new RuntimeException($this->translator->get('supervisor.no_jar'));
        }

        $say($this->translator->get('supervisor.starting_suwayomi'));

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
            $this->translator->get('supervisor.waiting_suwayomi'),
            $say,
            $this->translator,
        );

        if (! $ready) {
            throw new RuntimeException($this->translator->get('supervisor.suwayomi_timeout', [
                'port' => $this->suwayomiPort,
                'log' => $this->logDirectory.DIRECTORY_SEPARATOR.'suwayomi.log',
            ]));
        }

        $say($this->translator->get('supervisor.suwayomi_answering'));
    }

    /**
     * Whether the process holding the Suwayomi port is the one this install would have started.
     *
     * The owner is this install's Java runtime running this install's jar with this install's data
     * directory on its command line. Anything else is refused, and so is an owner that could not be
     * identified: `portHolder()` returns null when the machine will not answer, and a launcher that
     * treated "could not find out" as "probably fine" would hand the reader's credentials to
     * exactly the process it failed to identify.
     *
     * @param  array{pid: int, name: string, command: string, addresses: list<string>}|null  $holder
     */
    private function isThisInstallsSuwayomi(?array $holder): bool
    {
        if ($holder === null) {
            return false;
        }

        $name = strtolower(basename($holder['name']));

        if (! in_array($name, ['java.exe', 'java'], true)) {
            return false;
        }

        $jar = $this->suwayomiJar ?? $this->locateJar();

        if ($jar === null) {
            return false;
        }

        $command = $holder['command'];

        return $command !== ''
            && str_contains($command, $this->dataDirectory)
            && str_contains($command, basename($jar));
    }

    private function migrate(callable $say): void
    {
        $record = new MigrationRecord($this->dataDirectory, $this->root);
        $previousRecord = is_file($record->path()) ? (string) @file_get_contents($record->path()) : null;

        /*
         * A fresh install has no database file yet.
         *
         * The pre-migration snapshot was added to protect an existing library. On a fresh
         * install there is nothing to copy, and `VACUUM INTO` fails with "Database file does
         * not exist", which made the launcher refuse the first migration and never start.
         * Skip the snapshot only when there is no database; an existing empty file is also
         * skipped, because the first migration is about to create its tables.
         */
        if ($this->hasDatabase()) {
            $say($this->translator->get('supervisor.snapshot_before_migrating'));

            $snapshot = $this->runArtisan(['papyrus:snapshot', '--keep=14'], [], 'snapshot.log');

            if ($snapshot !== 0) {
                throw new RuntimeException($this->translator->get('supervisor.snapshot_failed', [
                    'log' => $this->logDirectory.DIRECTORY_SEPARATOR.'snapshot.log',
                ]));
            }

            /*
             * Name the snapshot before the migration can fail.
             *
             * The snapshot command writes a timestamped file, and the log that names it is not a place a
             * reader can be asked to look. `MigrationRecord` writes the path, digest, version and commit
             * where `papyrus rollback` reads it. If that record cannot be written, the migration does not
             * run: a snapshot nobody can name is not a rollback path.
             */
            $snapshotPath = $this->newestSnapshot(dirname($this->databaseFile()).DIRECTORY_SEPARATOR.'reader-snapshots');

            if ($snapshotPath === null) {
                throw new RuntimeException($this->translator->get('supervisor.snapshot_not_written'));
            }

            $record->write($snapshotPath);

            $say($this->translator->get('supervisor.snapshot_recorded', [
                'path' => $snapshotPath,
            ]));
        } else {
            $say($this->translator->get('supervisor.no_database'));
        }

        $say($this->translator->get('supervisor.checking_migrations'));

        /*
         * Truncated before the run, because `runArtisan` appends to its log. A previous
         * "Nothing to migrate" left in the file would make this run look like a no-op even when it
         * applied migrations, and the previous rollback record would be kept for the wrong reason.
         */
        @file_put_contents($this->logDirectory.DIRECTORY_SEPARATOR.'migrate.log', '');

        $result = $this->runArtisan(['migrate', '--force']);

        if ($result !== 0) {
            throw new RuntimeException($this->translator->get('supervisor.migrate_failed', [
                'log' => $this->logDirectory.DIRECTORY_SEPARATOR.'migrate.log',
            ]));
        }

        /*
         * A start with nothing to migrate must not replace the last real rollback point.
         *
         * The snapshot is taken on every start, so the newest file is usually a post-migration
         * copy. The record should keep naming the snapshot taken before the last migration that
         * actually ran, or a reader who upgrades, restarts once, and then wants the previous
         * version has lost the record they need.
         */
        if ($previousRecord !== null && $this->migrateWasNoop()) {
            if (file_put_contents($record->path(), $previousRecord) === false) {
                throw new RuntimeException($this->translator->get('supervisor.migration_record_failed', [
                    'path' => $record->path(),
                ]));
            }

            $say($this->translator->get('supervisor.previous_record_kept'));
        }

        $say($this->translator->get('supervisor.database_current'));
    }

    /**
     * Whether the last migrate run said there was nothing to do.
     *
     * Laravel writes "Nothing to migrate" for a no-op. The log is the supervisor's own record of
     * the command, so this reads the same output a reader would see in a bug report.
     */
    private function migrateWasNoop(): bool
    {
        $log = $this->logDirectory.DIRECTORY_SEPARATOR.'migrate.log';
        $contents = @file_get_contents($log);

        return is_string($contents) && str_contains($contents, 'Nothing to migrate');
    }

    /**
     * The newest reader-half snapshot in a directory, or null when there is none.
     *
     * Newest by modification time, not by name, because two snapshots in one second would sort
     * unpredictably by filename and the migration needs the one just written.
     */
    private function newestSnapshot(string $directory): ?string
    {
        $files = glob($directory.DIRECTORY_SEPARATOR.'reader-half-*.sqlite') ?: [];

        if ($files === []) {
            return null;
        }

        usort(
            $files,
            static fn (string $a, string $b): int => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0),
        );

        return $files[0];
    }

    private function startWebServer(callable $say): void
    {
        if ($this->prober->isPortTaken($this->appPort)) {
            throw new RuntimeException($this->translator->get('supervisor.web_port_taken', [
                'port' => $this->appPort,
            ]));
        }

        $say($this->translator->get('supervisor.starting_web', [
            'port' => $this->appPort,
        ]));

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
            $this->translator->get('supervisor.waiting_web'),
            $say,
            $this->translator,
        );

        if ($this->schemaAhead > 0) {
            throw new RuntimeException($this->translator->get('supervisor.schema_ahead', [
                'count' => $this->schemaAhead,
            ]));
        }

        if (! $ready) {
            throw new RuntimeException($this->translator->get('supervisor.web_not_ready', [
                'log' => $this->logDirectory.DIRECTORY_SEPARATOR.'web.log',
            ]));
        }

        $say($this->translator->get('supervisor.app_ready'));
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
         *
         * And **PHPRC as well as the flag**, which is the half that was missing and which took
         * the whole install down.
         *
         * `schedule:work` does not run the schedule itself. It shells out to `artisan
         * schedule:run`, and it builds that command from `PHP_BINARY` with no `-c` of its own,
         * because Laravel assumes it inherited a configured interpreter. It did not: this
         * launcher's own PHP loads no ini at all, so the child found none, loaded no extensions,
         * and died on its first query with
         *
         *     PDOException: could not find driver
         *     sqlite:...\AppData\Local\Papyrus\database.sqlite
         *
         * The failure was invisible in three places at once. The supervisor reported all four
         * processes healthy, because the child dies inside a parent that is still alive. The
         * child wrote to a temp file under `tmp/` rather than to the log, so `scheduler.log`
         * was a wall of stack trace with no explanation of what had gone wrong. And it recurred
         * every ten seconds, because `schedule:run` is what dispatches the work.
         *
         * `PHPRC` is the documented way to tell a PHP interpreter where its ini is, and an
         * environment variable is inherited by every grandchild. FrankenPHP has had it all along
         * for exactly this reason; the two workers had only the flag.
         */
        $php = $this->phpExecutable ?? $this->path('runtime', 'bin', 'php.exe');

        $environment = ['PHPRC' => $this->ini()] + $this->applicationEnvironment();

        $queue = new ManagedProcess(
            name: 'queue',
            executable: $php,
            arguments: ['-c', $this->ini(), $this->path('artisan'), 'queue:work', '--tries=3', '--sleep=1'],
            workingDirectory: $this->root,
            logFile: $this->logDirectory.DIRECTORY_SEPARATOR.'queue.log',
            environment: $environment,
        );

        $schedule = new ManagedProcess(
            name: 'scheduler',
            executable: $php,
            arguments: ['-c', $this->ini(), $this->path('artisan'), 'schedule:work'],
            workingDirectory: $this->root,
            logFile: $this->logDirectory.DIRECTORY_SEPARATOR.'scheduler.log',
            environment: $environment,
        );

        $queue->start();
        $schedule->start();

        $this->started['queue'] = $queue;
        $this->started['scheduler'] = $schedule;

        $say($this->translator->get('supervisor.workers_started'));
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
        $say($this->translator->get('supervisor.checking_suwayomi'));

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

        $decoded = json_decode($response, true);

        if (is_array($decoded) && is_array($decoded['checks'] ?? null)) {
            $ahead = $decoded['checks']['schema_ahead'] ?? 0;

            if (is_int($ahead) && $ahead > 0) {
                $this->schemaAhead = $ahead;
                $this->reportUnready($status, $response);

                return false;
            }
        }

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

        fwrite(STDERR, $this->translator->get('supervisor.unready_block', [
            'status' => $status !== '' ? $status : $this->translator->get('supervisor.unready_no_status'),
            'checks' => $checks === [] ? $this->translator->get('supervisor.unready_no_checks') : implode(', ', $checks),
            'env' => $this->environmentFile(),
        ]));
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
     *
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

    /**
     * The address a reader should open, which is not the same string as any address we probe.
     *
     * ## Why `localhost` and not `127.0.0.1`
     *
     * Because an IP literal cannot be a WebAuthn relying-party id. The browser refuses a passkey on
     * `http://127.0.0.1:8000` before Papyrus is involved at all, and says so in a message telling the
     * reader to use `localhost`, which is an address this launcher was never handing them. The
     * relying-party id is `parse_url(APP_URL, PHP_URL_HOST)` and `APP_URL` is
     * `http://localhost:8000`, so `localhost` is the spelling that agrees with the server's own
     * config.
     *
     * ## Why the probes stay on `127.0.0.1`
     *
     * Because a probe is not something a reader types and is not a security boundary. Every internal
     * check, the readiness poll and the Suwayomi GraphQL call keep using the literal, and there is
     * nothing to gain by making them depend on name resolution. Exactly one string, this one, is
     * shown to a person.
     *
     * ## It does not widen what is reachable
     *
     * Both spellings are loopback. The listener is restricted by the explicit `bind` in the
     * Caddyfile, which is the thing that actually confines it, and naming a host does nothing for
     * that on its own.
     */
    public function readerUrl(): string
    {
        return sprintf('http://localhost:%d', $this->appPort);
    }

    public function logDirectory(): string
    {
        return $this->logDirectory;
    }

    /**
     * Where the program itself lives, as opposed to where the reader's data lives.
     *
     * Exposed because `path()` is private and callers outside this class legitimately need to reach
     * a file that ships beside the program rather than beside the data: `suwayomi.json` records the
     * Suwayomi release this build expects, and `version.txt` records the app version and commit.
     *
     * The distinction matters and is easy to get wrong. The data directory moves between installs
     * and can be portable, sitting on a USB stick beside the program; the install root cannot. A
     * caller reaching for a shipped file through `dataDirectory()` would appear to work on a per-user
     * install, where the two happen to share a parent, and find nothing at all on a portable one.
     */
    public function installRoot(): string
    {
        return $this->root;
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
     * Whether there is an existing database with bytes in it.
     *
     * A fresh install has no file, and the first migration creates it. The pre-migration
     * snapshot must be skipped in that case: `VACUUM INTO` fails when the source file does not
     * exist, and 1.5.2 refused the first migration for exactly that reason.
     */
    public function hasDatabase(): bool
    {
        $database = $this->databaseFile();
        $size = @filesize($database);

        return is_file($database) && is_int($size) && $size > 0;
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
     *
     * @throws ProcessProbeFailed When the operating system process list could not be read.
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

        if ($this->specFor($name) === []) {
            return false;
        }

        /*
         * The same query `stop` uses, and the same finder.
         *
         * This used to build its own marker and its own PowerShell query. The marker was a bare
         * `queue:work`, `schedule:work`, `frankenphp` or a jar name, none of which say *which*
         * install they belong to, so a second Papyrus on the machine matched all four and reported
         * itself perfectly healthy over a database that was not there. Measured, not theorised: run
         * from a copy of the install in another folder, this reported the installed app's four
         * processes as its own, all green, while failing on its own database.
         *
         * It is lesson 223 one level up, and it survived a fix that was correct one screen away.
         * `specFor()` had carried the data directory for some time and `stopAll` had been using
         * it, and nobody noticed the question had been answered twice.
         *
         * Which is the actual lesson, and it is 225 again: a fix applied to one occurrence of a
         * pattern leaves the other occurrences in place, and the other occurrences are nearby. The
         * two answers disagreed for the life of the feature and nothing failed, because `health` is
         * only ever run by somebody who already believes something is wrong, and this particular
         * wrong answer says everything is fine.
         *
         * The probe is deleted rather than corrected. It was a second implementation of
         * `ProcessFinder::find()`, one token where that takes a list, with its own temporary script.
         * Keeping a corrected copy would leave the next person two questions and one answer, which
         * is how the two came to disagree in the first place.
         *
         * Its commentary about `-File` and the death of WMIC is worth keeping, because
         * `ProcessFinder` was built for exactly those reasons and the reasoning should not be lost
         * with the code that repeated it. See {@see ProcessFinder}.
         */
        return $this->pidsFor($name) !== [];
    }

    /**
     * The pids of every running process that is this install's `$name`, asked of the operating system.
     *
     * Public because a snapshot has to know which pid to wait for, and `stopAll` destroys that
     * information by the time it returns. It had to be asked *before* the stop, from the same tokens
     * and the same finder `stop` uses, because a second implementation of "which processes are mine"
     * is lesson 225 and this class already carries a long note about having had one.
     *
     * @return list<int> Empty when the process is not running, or when its tokens cannot be built.
     *                   Both are the same answer for this caller and neither is guessed at.
     *
     * @throws ProcessProbeFailed When the operating system process list could not be read.
     */
    public function pidsFor(string $name): array
    {
        $spec = $this->specFor($name);

        if ($spec === []) {
            return [];
        }

        return $this->finder->find($spec);
    }

    /**
     * The pid of a live launcher that is supervising this install, or null when none is.
     *
     * The instance lock is the only thing here that says which launcher owns an install, and until
     * now only `start` ever read it. So `snapshot` and `restore` could stop an install that another
     * launcher was holding open, with neither of them knowing. Measured, on the reader's install, the
     * full transcript being in {@see InstallControl::stop()}:
     *
     *     the holder saw its children die, said "stopped on its own", shut the rest down and exited 1
     *     the snapshot finished all six stages, said "up again", and exited 0
     *     the install was down
     *
     * Both were telling the truth about what they had done. Together they had left a stopped install
     * that both of them claimed to have left running, which is the combination this project keeps
     * meeting and the reason a guard that only one caller honours is not a guard.
     *
     * Answers from the lock rather than from a process search, because the lock carries a start time
     * and a pid alone is not an identity. A crashed launcher leaves a stale file behind, and a reader
     * who is told another launcher owns the install when none does would never start it.
     */
    public function supervisorPid(): ?int
    {
        return (new InstanceLock($this->lockPath()))->holderPid();
    }

    /**
     * The version of Suwayomi this install ships, read from the jar's own name.
     *
     * `Suwayomi-Server-v2.4.2366.jar` gives `2.4.2366`, which is the number `suwayomi.json` records
     * and the number a manifest should carry.
     *
     * Read from the file rather than asked over GraphQL on purpose. A backup manifest has to be
     * writable whether or not the server is up, and this is the command that is about to stop it, so
     * a version read from a live server would have to be fetched before the stop and could not be
     * re-read afterwards to check it. It is also the same number: the jar *is* the server.
     *
     * `unknown` rather than an empty string, because {@see BackupPair} refuses an empty one and a
     * reader is better served by a manifest that says it does not know than by no backup at all.
     */
    public function suwayomiVersion(): string
    {
        $jar = $this->locateJar();

        if ($jar !== null && preg_match('/(\d+\.\d+(?:\.\d+)*)/', basename($jar), $found) === 1) {
            return $found[1];
        }

        return 'unknown';
    }

    /** The summary log, for the health command and for the messages `start` writes. */
    public function summary(): SummaryLog
    {
        return $this->summary ??= new SummaryLog($this->logDirectory, $this->translator);
    }

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
    public function prepareLayoutForBuild(): void
    {
        $this->prepareLayout(static function (string $line): void {
            // The build reports its own steps, so this is deliberately silent.
        });
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
            $say($this->translator->get('supervisor.created_directories', [
                'count' => count($made),
            ]));
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
                    /*
                     * The app's own URL has to be the port it is bound to. Without this,
                     * APP_URL stays the example's 8000 while PAPYRUS_APP_PORT can be 8402,
                     * and every redirect, including the one after registration, goes to a
                     * port nothing is listening on.
                     */
                    '--app-url='.$this->readerUrl(),
                    /*
                     * The language the installer or the reader chose becomes the install's
                     * default. Without it the app starts in the source language and the reader
                     * has to choose again in Settings.
                     */
                    '--locale='.$this->translator->locale(),
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
            ['{{APP_ROOT}}', '{{RUNTIME}}', '{{DATA}}', '{{LOGS}}', '{{TEMP}}', '{{TIMEZONE}}'],
            [
                // Forward slashes, matching the Caddyfile. An ini path is read by the C runtime
                // rather than by PHP's own path handling, so a mixed path is at best a warning.
                str_replace('\\', '/', $this->root),
                str_replace('\\', '/', $this->path('runtime')),
                str_replace('\\', '/', $this->dataDirectory),
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
                throw new RuntimeException($this->translator->get('supervisor.template_token', [
                    'path' => $target,
                    'line' => trim($line),
                ]));
            }
        }
    }

    private function takeLock(): void
    {
        @mkdir(dirname($this->lockPath()), 0o777, true);

        $lock = new InstanceLock($this->lockPath());

        if (! $lock->acquire()) {
            throw new RuntimeException($this->translator->get('supervisor.lock_held', [
                'holder' => $lock->describe(),
            ]));
        }

        $this->lock = $lock;
    }

    private function lockPath(): string
    {
        return $this->dataDirectory.DIRECTORY_SEPARATOR.'launcher.lock';
    }

    /**
     * The reader's word for a process outcome, with the raw token as a last resort.
     *
     * `ManagedProcess` and `ProcessFinder` return stable English tokens used by the command logic.
     * Only the display is translated, so `stopAll` and the installer can keep comparing the tokens.
     */
    private function outcome(string $outcome): string
    {
        $key = 'supervisor.outcome.'.str_replace(' ', '_', $outcome);

        return $this->translator->has($key) ? $this->translator->get($key) : $outcome;
    }

    private function path(string ...$parts): string
    {
        return $this->root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
    }
}
