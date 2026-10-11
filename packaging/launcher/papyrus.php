<?php

/**
 * The packaged Papyrus launcher.
 *
 * Five commands, because that is what a phase 0 install needs:
 *
 *   start        bring everything up, wait until it is genuinely usable, then hold
 *   stop         bring it all down, leaving nothing behind
 *   status       say what is running, without changing anything
 *   health       the short answer to "is it working", for a reader to paste into a report
 *   diagnostics  gather everything into one bundle to attach to a bug report
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
require __DIR__.'/src/Prober.php';
require __DIR__.'/src/ProcessFinder.php';
require __DIR__.'/src/ManagedProcess.php';
require __DIR__.'/src/SuwayomiConfiguration.php';

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

$root = getenv('PAPYRUS_ROOT') ?: dirname(__DIR__, 2);
$data = getenv('PAPYRUS_DATA') ?: dirname($root).DIRECTORY_SEPARATOR.'papyrus-data';
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
            $supervisor->start($say);
            $say('');
            $say(sprintf(
                'Papyrus is running at http://127.0.0.1:%d',
                (int) (getenv('PAPYRUS_APP_PORT') ?: 8000),
            ));
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
                    $say(sprintf('%s did not stop politely and had to be killed.', $name));
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
            $say($health->render());

            exit(str_contains($health->render(), 'FAIL') ? 1 : 0);

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
            fwrite(STDERR, "Usage: papyrus.php [start|stop|status|health|diagnostics] [--zip]\n");

            exit(2);
    }
} catch (\RuntimeException $e) {
    fwrite(STDERR, PHP_EOL.$e->getMessage().PHP_EOL);

    exit(1);
}
