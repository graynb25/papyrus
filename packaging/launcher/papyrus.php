<?php

/**
 * The packaged Papyrus launcher.
 *
 * Seven commands, because that is what a phase 0 install needs:
 *
 *   start        bring everything up, wait until it is genuinely usable, then hold
 *   stop         bring it all down, leaving nothing behind
 *   status       say what is running, without changing anything
 *   health       the short answer to "is it working", for a reader to paste into a report
 *   diagnostics  gather everything into one bundle to attach to a bug report
 *   snapshot     take both databases as one pair, which only this can do
 *   restore      put a pair back, or leave both as they were
 *
 * ## Why this is plain PHP and not a compiled launcher
 *
 * Because the runtime already ships `php.exe`. A compiled launcher adds a toolchain, a
 * cross-compilation matrix and a second thing that can be broken, in exchange for a tray icon
 * that phase 0 explicitly does not have.
 *
 * It also has to keep working when the application does not, which is the whole reason a
 * reader calls `stop`. So this file and the four classes beside it depend on nothing but PHP
 * itself: no autoloader, no framework, no database. If the app is so broken that
 * `artisan` will not run, this still stops it.
 *
 * The tray icon, when it comes in phase 2, is a small native binary whose only job is a
 * system tray icon and a browser window, and it calls these same three commands.
 */

declare(strict_types=1);

namespace Papyrus\Launcher;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The launcher runs from a terminal, not a browser.\n");

    exit(1);
}

require __DIR__.'/src/ProcessIdentity.php';
require __DIR__.'/src/InstanceLock.php';
require __DIR__.'/src/BrowserLauncher.php';
require __DIR__.'/src/Prober.php';
require __DIR__.'/src/ProcessFinder.php';
require __DIR__.'/src/ManagedProcess.php';
require __DIR__.'/src/DataLocation.php';
require __DIR__.'/src/SuwayomiConfiguration.php';
require __DIR__.'/src/SuwayomiCredentials.php';
require __DIR__.'/src/SuwayomiCompatibility.php';

/*
 * Before Supervisor, and not merely alongside it.
 *
 * `Supervisor::prepareLayout` constructs an `InstallLayout` and catches a `LayoutRefused`, so
 * loading Supervisor without these two is not a warning, it is a fatal at the first line of a
 * start: "Class Papyrus\Launcher\InstallLayout not found". Every other class this file requires is
 * only touched later in the run, which is why the ordering is invisible until the layout step.
 *
 * Nothing local catches it. Both the test suite and the clean-room probe require all seven classes
 * explicitly, so both exercise a Supervisor that has already been handed its dependencies and
 * cannot tell you this file forgot to. It was found by running `papyrus.php start` in a sandbox
 * with nothing else loaded, which is the only arrangement a reader ever gets.
 */
require __DIR__.'/src/LayoutRefused.php';
require __DIR__.'/src/InstallLayout.php';

require __DIR__.'/src/SummaryLog.php';
require __DIR__.'/src/Health.php';
require __DIR__.'/src/Supervisor.php';

/*
 * Layer 2. ParsedLine and LineParser come first because every parser below type hints them, and
 * the launcher has no autoloader: these are plain requires, and the order is the dependency order.
 */
require __DIR__.'/src/ParsedLine.php';
require __DIR__.'/src/LineParser.php';
require __DIR__.'/src/LogbackParser.php';
require __DIR__.'/src/CaddyJsonParser.php';
require __DIR__.'/src/ConsoleParser.php';
require __DIR__.'/src/PhpErrorParser.php';
require __DIR__.'/src/LauncherParser.php';
require __DIR__.'/src/Collector.php';
require __DIR__.'/src/ZipWriter.php';
require __DIR__.'/src/EnvironmentReport.php';
require __DIR__.'/src/Bundle.php';

/*
 * Layer 3. Restoring both databases as one operation.
 *
 * The interface comes before its implementations because the launcher has no autoloader and these are
 * plain requires, so the order is the dependency order. `FileMover` first because `LocalFileMover`
 * type hints it, and `RestoreRefused` before both because every signature in this layer throws it.
 */
require __DIR__.'/src/RestoreRefused.php';
require __DIR__.'/src/FileMover.php';
require __DIR__.'/src/LocalFileMover.php';
require __DIR__.'/src/BackupPair.php';
require __DIR__.'/src/RestoreOutcome.php';
require __DIR__.'/src/Restorer.php';
require __DIR__.'/src/InstallControl.php';
require __DIR__.'/src/InstallFacts.php';
require __DIR__.'/src/SupervisorControl.php';
require __DIR__.'/src/RestoreCommand.php';
require __DIR__.'/src/MangaSnapshot.php';
require __DIR__.'/src/RunRecord.php';
require __DIR__.'/src/ReaderSnapshotResult.php';
require __DIR__.'/src/ReaderSnapshot.php';
require __DIR__.'/src/SnapshotCommand.php';

$root = getenv('PAPYRUS_ROOT') ?: dirname(__DIR__, 2);

/*
 * Where the data goes, and whether anybody chose it or we did.
 *
 * `$dataChosen` rather than testing the environment variable again further down, because "the
 * reader set this" and "we picked this" are different situations and the reader deserves to be
 * told which one they are in. A portable install keeps its data on whatever the folder is on; an
 * installed one cannot, and says so rather than refusing with a bare unwritable path.
 */
$dataChosen = is_string($explicit = getenv('PAPYRUS_DATA')) && $explicit !== '';

$decision = (new DataLocation)->resolve($root);

$data = $dataChosen ? $explicit : $decision['path'];
$logs = $data.DIRECTORY_SEPARATOR.'logs';

/** @var \Closure(string): void $say */
$say = static function (string $line): void {
    fwrite(STDOUT, $line.PHP_EOL);
};

$supervisor = new Supervisor(
    root: $root,
    dataDirectory: $data,
    logDirectory: $logs,
    suwayomiPort: (int) (getenv('PAPYRUS_SUWAYOMI_PORT') ?: 4567),
    appPort: (int) (getenv('PAPYRUS_APP_PORT') ?: 8000),
);

$command = $argv[1] ?? 'status';

try {
    switch ($command) {
        case 'start':
            /*
             * Where the data went, before anything else.
             *
             * A reader who cannot find their library will look in the install folder first, and a
             * reader who cannot find their logs will ask where to attach them from. One line here
             * answers both, and it is the only place it is true, because this is the process that
             * decided it.
             *
             * Said once, on start, and not by the summary log: a reader who cannot start Papyrus
             * at all is exactly the reader who needs to be told.
             */
            $say($dataChosen
                ? 'Your data directory is '.$data.', set by PAPYRUS_DATA.'
                : 'Your data directory is '.$data.', '.$decision['reason'].'.');
            $say('');

            $supervisor->start($say);
            $say('');

            /*
             * One source for the port, read from the supervisor rather than from the environment
             * again.
             *
             * The URL printed below used to be rebuilt from `getenv('PAPYRUS_APP_PORT')`, which is
             * the same expression the supervisor's constructor used, so they agreed by coincidence
             * rather than by construction. A URL that disagrees with the port actually bound is worse
             * than no URL, because a reader who follows it is told the app is broken.
             */
            $url = $supervisor->readerUrl();

            $say(sprintf('Papyrus is running at %s', $url));

            /*
             * Open the browser, so nobody has to know or guess the port.
             *
             * Opt-in rather than always, because this same command is run by the clean-room gate on a
             * machine with no browser worth opening, and by anyone scripting a start. `Papyrus.cmd`
             * is the thing that passes it, and that is the one a reader runs.
             */
            if (in_array('--open', $argv, true)) {
                /*
                 * The wording is deliberately not "Opened your browser."
                 *
                 * Because there is no way to know that happened. `cmd /c start` hands the URL to the
                 * shell and returns, and it returns success in at least one situation where nothing
                 * opened at all: a process with no interactive desktop, where it silently does
                 * nothing. Measured here, running the exact command from this shell: zero browser
                 * processes before, zero after, exit code fine.
                 *
                 * So this reports what it did, which is ask, and the address stays on screen either
                 * way because that is the part a reader can act on. Saying "opened" would be the most
                 * expensive kind of wrong answer available here: a reader whose browser genuinely did
                 * not open would be told it did.
                 *
                 * For a reader double-clicking the shortcut this still opens the browser, because a
                 * shortcut has an interactive desktop. This is the shape of the thing working, not of
                 * the thing failing.
                 */
                if ((new BrowserLauncher)->open($url)) {
                    $say('Asked your browser to open it.');
                } else {
                    $say('Could not open a browser from here. The address above is all you need.');
                }
            }

            $say('Press Ctrl+C to stop it.');

            // Hold the terminal open. The children are not daemons and this process is what
            // keeps them alive, so returning here would be a stop dressed as an exit.
            while (true) {
                sleep(1);

                foreach ($supervisor->status() as $name => $running) {
                    if (! $running) {
                        $say(sprintf('%s stopped on its own, shutting the rest down.', $name));
                        $supervisor->stopAll($say);

                        exit(1);
                    }
                }
            }

        case 'stop':
            /*
             * Reach for both halves of the install, not only what this process started.
             *
             * This command is documented as the one that brings everything down when the
             * application is too broken to explain itself, and the tray icon is documented as
             * calling it. Both arrive as their own process, which is why it has to find the
             * running processes rather than ask its own empty list. See Supervisor::stopAll.
             */
            $outcomes = $supervisor->stopAll($say);

            if ($outcomes === []) {
                $say('Nothing belonging to this install is running, so nothing was stopped.');

                exit(0);
            }

            foreach ($outcomes as $name => $outcome) {
                if ($outcome === 'killed') {
                    /*
                     * Not "did not stop politely", because on Windows nothing tried.
                     *
                     * Measured: `taskkill` without `/F` cannot terminate a console process at all, so a
                     * process this launcher did not start is always killed outright. Saying it "did not
                     * stop politely" describes an attempt that never happened, and this line is what a
                     * reader pastes into a bug report. See `ProcessFinder::terminate()`.
                     *
                     * The distinction that does matter is still here: a process stopped through a
                     * handle and one force-killed are different signals about whether something is
                     * wrong, and `stopAll` reports them differently.
                     */
                    $say(sprintf('%s was stopped by force, which is the only way Windows can stop it.', $name));
                }

                if ($outcome === 'still running' || $outcome === 'could not be stopped') {
                    $say(sprintf('%s did not stop. Something outside Papyrus may be holding it.', $name));
                }
            }

            exit(0);

        case 'status':
            $status = $supervisor->status();

            if ($status === []) {
                $say('This session has nothing running. Papyrus is not installed under this launcher.');
            }

            foreach ($status as $name => $running) {
                $say(sprintf('%-10s %s', $name, $running ? 'running' : 'not running'));
            }

            exit(0);

        case 'restore':
            /*
             * The one operation where a reader can lose work they cannot reconstruct.
             *
             * Suwayomi backs its own half up daily and nothing backed this project's half up until
             * recently, so a restore of only one database was not a degraded install but a broken
             * one: `series_states.suwayomi_manga_id` would point at rows that no longer mean what the
             * reader thinks they mean, and the symptom is a library that reads as empty.
             *
             * The command stops the install, puts both halves back as one operation, and starts it
             * again, including when the restore fails. It is a class rather than a case body because
             * the choreography is the part worth testing and a switch statement hides it.
             */
            exit(RestoreCommand::forSupervisor($supervisor)->run(array_slice($argv, 2), $say));

        case 'snapshot':
            /*
             * The other half of the same pair, and the only command that can produce one.
             *
             * `artisan papyrus:snapshot` writes `papyrus-20261013-031701.sqlite` beside the database
             * and calls itself a backup. It is not one: the two databases reference each other by id,
             * so a file holding one half restores into a library that reads as empty with nothing
             * saying why, and `BackupPair` refuses a manifest naming only one.
             *
             * Here the app is stopped, both files are copied, a manifest binds them to one token, and
             * the app comes back up. It comes back up even when the snapshot failed, because a reader
             * whose backup failed and who is then left with a dead install cannot tell a bad backup
             * from a broken application.
             */
            exit(SnapshotCommand::forSupervisor($supervisor)->run(array_slice($argv, 2), $say));

        case 'health':
            /*
             * The command a reader is asked to run before filing a bug.
             *
             * It changes nothing and answers in a second, because "is it working" should never
             * require opening a log file, and because a reader who can run one command and paste
             * the output has given us most of what we need before writing a word.
             *
             * Exits non-zero when something is wrong, so a script or a future tray icon can branch
             * on it rather than parsing the table.
             */
            $health = new Health(
                $supervisor,
                new Prober,
                $supervisor->summary(),
            );

            $say('');
            $say('  Papyrus health');
            $say('');

            $report = $health->render();

            /*
             * One render, and the exit code taken from the same string that was printed.
             *
             * Calling `render()` twice re-runs every check, so the table a reader reads and the
             * code a script branches on can disagree: the queue was re-measured between the two and
             * the second pass said something different. Health printed "something is wrong" and
             * exited zero, which is the one combination that makes `health` useless to the script
             * the comment above promises it is for.
             */
            $say($report);

            exit(str_contains($report, 'FAIL') ? 1 : 0);

        case 'diagnostics':
            /*
             * The command that produces the one thing a reader is asked to attach.
             *
             * It prints the path rather than the contents, because the reader's next action is to
             * attach the bundle, not to read it. What it does not do is judge: this exits zero
             * whenever a bundle was written, even an install that is completely broken, because
             * "did the command work" and "is the install healthy" are different questions and a
             * tray icon needs to be able to tell them apart.
             *
             * --zip also writes a single archive, for a reader attaching to an issue where eight
             * files is eight drags and any one of them left behind.
             */
            $bundle = new Bundle($supervisor, new Prober);
            $directory = $bundle->write();

            $say('');
            $say('  Papyrus diagnostics');
            $say('');
            $say('  Bundle written to');
            $say('    '.$directory);
            $say('');
            $say('  It contains');

            foreach ($bundle->listing($directory) as $name) {
                $say('    '.$name);
            }

            if (in_array('--zip', $argv, true)) {
                $say('');
                $say('  One file to attach');
                $say('    '.$bundle->zip($directory));
            }

            $say('');
            $say('  Please attach the whole bundle. Four files chosen at random is a worse');
            $say('  report than the whole thing, because we cannot tell what is missing from');
            $say('  what was never there.');
            $say('');

            exit(0);

        default:
            fwrite(STDERR, "Usage: papyrus.php [start|stop|status|health|diagnostics|snapshot|restore] [--zip] [--open] [--yes] [--into=DIR]\n");

            exit(2);
    }
} catch (\RuntimeException $e) {
    fwrite(STDERR, PHP_EOL.$e->getMessage().PHP_EOL);

    exit(1);
}
