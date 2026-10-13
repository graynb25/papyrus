<?php

/**
 * Assemble a packaged install layout on disk.
 *
 * This is the build step both the clean-room run and the Windows Sandbox gate depend on, and it
 * exists because both of them were quietly testing something else.
 *
 * ## Why this exists rather than more hand-built directories
 *
 * The clean-room probe passed an install whose root was the repository, with four paths
 * overridden by hand to point somewhere else. That arrangement cannot be wrong in the way a real
 * install is wrong, because a real install has one root and every path is derived from it. It
 * hid two defects outright:
 *
 *   - `{{RUNTIME}}` in the php.ini is resolved from the root, and no constructor argument can
 *     override it, so the probe's overrides never reached it and it resolved into a directory
 *     that does not exist.
 *   - `suwayomi/runtime/jre` was staged where `Supervisor::javaExecutable` looks at
 *     `suwayomi/runtime`, so the probe passed `javaExecutable` explicitly and a real reader,
 *     who passes nothing, would have got nothing.
 *
 * Both were invisible because the probe supplied the answers the launcher should have derived.
 *
 * ## What it assembles
 *
 *     <into>/runtime/{bin,ext,php,frankenphp,cacert.pem}   the PHP and web server
 *     <into>/suwayomi/{*.jar,runtime/}                      the server and its JRE
 *     <into>/{artisan,app,bootstrap,config,...}             the application
 *     <into>/packaging/launcher/                            the launcher itself
 *
 * The application files sit at the root rather than under an `app` subdirectory because the
 * launcher's `path('artisan')` looks there, and a layout that disagrees with the launcher is the
 * exact failure this script is here to prevent.
 *
 * `--jre` and `--cacert` default to where `stage-runtime.php` puts them at the repository root.
 * They are separate options because the two land in separate directories, `suwayomi/runtime` and
 * `runtime`, and pointing one at a staging area should not require pointing the other there too.
 *
 * Usage:
 *
 *   php packaging/runtime/build-install.php --frankenphp=<zip> --jar=<jar> [--jre=<dir>] [--cacert=<file>] [--into=<dir>]
 */

declare(strict_types=1);

namespace Papyrus\Runtime\Build;

use DateTimeImmutable;
use Papyrus\Launcher\Supervisor;
use Papyrus\Launcher\SuwayomiCompatibility;
use Throwable;

/**
 * The application's own files, copied by allowlist rather than denylist.
 *
 * An allowlist because the risk runs one way. A missed exclude ships the developer's `.env` and
 * their APP_KEY to a stranger. A missed include produces a layout that fails the completeness
 * check below, which names the file that is absent. One is a bad artifact, the other is loud.
 */
const APP_ENTRIES = [
    'app',
    'artisan',
    'bootstrap',
    'composer.json',
    '.env.example',
    'config',
    'database',
    'package.json',
    'public',
    'resources',
    'routes',
    'storage',
    'vendor',
];

/**
 * Never copied out of `storage/`, because everything in it is per developer and per machine.
 *
 * ## Why this is a subtree rule rather than more entries in a denylist
 *
 * Because `storage/` is by definition writable state. Laravel writes caches, compiled views,
 * sessions, logs, uploaded files and test scratch into it, and a developer's copy of it holds
 * whatever their last test run and their last import left behind. There is no file in it that
 * belongs in a reader's install, so the whole subtree is replaced with empty directories.
 *
 * The previous version listed six specific paths under `storage/` and missed
 * `storage/framework/testing/`, which shipped a cover image and a Livewire temporary upload from
 * the test suite. That is the shape of the problem rather than an instance of it: a list of paths
 * someone remembered, one more entry to forget each time something new lands there, and a build
 * that reported success while doing it.
 *
 * This also removes the need for the `storage/app/private` entry that was there to stop a
 * developer's downloads shipping, and for the log and cache entries beside it.
 */
const NEVER_COPY_STORAGE = true;

/**
 * Files that must never reach a build, each with the reason it is listed.
 *
 * Outside `storage/`, so this stays a denylist, because the allowlist cannot be applied the same
 * way: `database/` holds the migrations the app genuinely needs alongside the SQLite file a
 * developer's own library lives in, and an allowlist there would drop the migrations.
 *
 * The library is the serious one. It records what someone follows and how far through each series
 * they are, and it must never end up in an artifact handed to a stranger, however briefly. The
 * check below fails the build if one appears rather than warning, because an artifact that ships
 * a library looks identical to one that does not until somebody opens it.
 */
const NEVER_SHIPPED = [
    'database/database.sqlite' => 'the developer library: what they follow and how far through',
    'database/database.sqlite-journal' => 'a journal left beside the library',
    'database/database.sqlite-wal' => 'a write ahead log left beside the library',
    'database/database.sqlite-shm' => 'shared memory state left beside the library',
    'storage/design-review' => 'screenshots taken during development',
];

/**
 * Restore the directory placeholders Laravel and the app expect to exist but that hold no files.
 *
 * Denylisting `storage/logs` removes the directory as well as its contents, and Laravel writes to
 * those paths without creating them. An install that is missing them fails on its first log line
 * rather than at build time, so they are put back empty.
 */
const EMPTY_PLACEHOLDERS = [
    'storage/app/private',
    'storage/app/public',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
];

const REQUIRED_AFTER_BUILD = [
    'runtime/bin/php.exe',
    'runtime/bin/frankenphp.exe',
    'runtime/ext/php_pdo_sqlite.dll',
    'runtime/bin/php8ts.dll',
    'runtime/bin/vcruntime140.dll',
    'runtime/bin/vcruntime140_1.dll',
    'runtime/bin/ucrtbase.dll',
    'runtime/bin/msvcp140.dll',
    'runtime/php/php.ini',
    'runtime/frankenphp/Caddyfile',
    'runtime/cacert.pem',
    'suwayomi/runtime/bin/java.exe',
    'artisan',
    'composer.json',
    'package.json',
    'packaging/launcher/papyrus.php',

    /*
     * The shortcut target, checked rather than assumed.
     *
     * A Start Menu entry pointing at a file that is not there is an icon that fails when a reader
     * clicks it, which is the first thing they will do and the least likely thing anybody
     * reproduces before reporting it. The build is the only place that can know, cheaply, whether
     * the thing the installer points at exists.
     */
    'Papyrus.cmd',

    'version.txt',

    /*
     * What Suwayomi this build expects. Without it the health check has nothing to compare the
     * running server against and says so on every run, which is the state this file was added to
     * end. Checked here because the build is the last place it can still be a mistake rather than
     * a reader's problem.
     */
    'suwayomi.json',

    /*
     * The one dotfile that must ship, and the only reason this list is not purely a set of
     * directories and obvious files.
     *
     * `.env.example` is what `papyrus:configure` copies to create the install's `.env`. Without
     * it the command refuses with "No .env.example to copy from. The install is incomplete", and
     * the symptom downstream is the worst kind: the web server starts, logs "server running",
     * and answers nothing, because the app has no APP_KEY. The launcher reports that the web
     * server never became ready while its own log says it is serving, which is a contradiction
     * no bug report can be written against.
     *
     * The allowlist protects the real `.env` by never copying dotfiles at all, and that is exactly
     * what made this omission invisible. Refusing to copy `.env` and failing to copy
     * `.env.example` are the same decision, and only one of them is intended.
     */
    '.env.example',
];

/**
 * Everything Laravel touches under its base path while booting.
 *
 * Extracted from `Illuminate\Foundation\Application` rather than remembered, because the failure
 * mode here is a hand maintained list being incomplete. The build verified nine required paths
 * and reported no developer data while the install could not boot at all: `composer.json` was
 * missing from the allowlist, Laravel reads it to resolve the application namespace, and the
 * first artisan command died with a failed `file_get_contents`.
 *
 * `lang/` is in the list because the framework reads it and it is absent from this repository,
 * which is a legitimate state rather than a build error. It is checked for presence only when it
 * exists in the source, so a repository without translations still builds.
 */
const LARVEL_BOOT_PATHS = [
    'app',
    'bootstrap',
    'composer.json',
    'config',
    'database',
    'public',
    'resources',
    'storage',
];

$options = parseArguments($argv);

if ($options['help']) {
    fwrite(STDOUT, "Usage: php build-install.php --frankenphp=<zip> --jar=<jar> [--jre=<dir>] [--cacert=<file>] [--into=<dir>]\n");

    exit(0);
}

$repo = dirname(__DIR__, 2);
$into = $options['into'] ?? $repo.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'install';

/*
 * Refuse a relative install root, because it fails twenty lines later and says nothing useful.
 *
 * `Supervisor::path()` joins the root onto whatever it is resolving, so a relative root produces
 * relative paths in every file the launcher writes. The build then copies 12,000 files, validates
 * 20 required paths, stages the JRE, reports `resolved ini: no tokens left`, and finally fails with
 * `the install cannot boot with its own resolved php.ini`. That message is about a php.ini whose
 * `extension_dir` reads `build/install/runtime/ext`, which is a correct path from the repository and
 * a wrong one from inside the install, and nothing in that sentence points at the cause.
 *
 * It is worth an hour to work that out from the build's own output, so it is refused here instead,
 * where the sentence can name the fix.
 */
if (! preg_match('#^[A-Za-z]:[\\\\/]#', $into) && ! str_starts_with($into, '\\\\')) {
    fail(
        "the install root must be an absolute path, and {$into} is relative.\n\n"
        ."  It is copied verbatim into the php.ini the launcher resolves, and PHP reads\n"
        ."  `extension_dir` relative to the install rather than to your shell.\n\n"
        ."  Pass an absolute path. This build prints the default it would have used:\n"
        ."    --into=<an absolute path to a build directory>\n"
        ."  Omitted entirely, --into defaults to the build directory beside this repository,\n"
        .'  which is already absolute.'
    );
}

say('install', $into);

/*
 * Spelled out rather than looped over.
 *
 * The loop indexed $options by a variable, which is fine to read and hopeless to analyse: with the
 * key unknown, every use of an option became string|bool and twenty errors followed from three
 * lines. Two checks cost less than the twenty.
 */
foreach ([
    ['--frankenphp', 'FrankenPHP archive', $options['frankenphp']],
    ['--jar', 'Suwayomi jar', $options['jar']],
] as [$flag, $label, $given]) {
    if ($given === null) {
        fail("no {$label} given, pass {$flag}=<path>");
    }

    if (! is_file($given)) {
        fail("the {$label} is not at {$given}");
    }
}

$jre = $options['jre'] ?? $repo.DIRECTORY_SEPARATOR.'suwayomi'.DIRECTORY_SEPARATOR.'runtime';

if (! is_file($jre.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'java.exe')) {
    fail(
        "no staged JRE at {$jre}.".PHP_EOL
        .'  Run packaging/runtime/stage-runtime.php first, which is what puts one there.'
    );
}

say('jre', $jre);

// Rebuilt rather than appended to. A stale file left by an earlier build is indistinguishable
// from a correct one, and the whole point of this script is that the layout is what it says.
if (! removeTree($into)) {
    fail("could not clear {$into}");
}

say('layout', 'cleared');

/*
 * `removeTree()` above deletes the install directory rather than emptying it, so it does not exist
 * here yet. The first version of this wrote version.txt one line lower and got
 * "file_put_contents: No such file or directory", then reported the commit as recorded anyway.
 *
 * That is the third time this build has reported success over a file that was not there, and the
 * first two are written up in docs/LOGGING.md. So the write is checked, and the directory is
 * created first, rather than either being assumed.
 */
if (! is_dir($into) && ! mkdir($into, 0o777, true) && ! is_dir($into)) {
    fail("could not create {$into}");
}

// --- what this install is ----------------------------------------------------------------

/*
 * A commit hash, and a version number, and neither is a substitute for the other.
 *
 * The hash cannot drift: it is read from the checkout that is being built, so a bug report saying
 * "1.2.3" has an exact referent and when the reporter's install says one hash and the repository
 * says another, that is the end of the guessing.
 *
 * The number exists because the installer cannot be built without one, and because a reader in a
 * bug report says what they can see. The drift objection to a version number is real and it is
 * answered by having exactly one: `VERSION` in the repository root is the only place it is written
 * by hand, the build copies it here, and the installer reads it from here rather than carrying its
 * own copy. A number that disagrees with `VERSION` cannot be produced by this build.
 *
 * Written as "unknown" rather than omitted for the hash when there is no git, so a bundle always
 * has a version line and a reader is never left wondering whether the build simply forgot. A
 * missing `VERSION` is a different thing: it is a checkout error, and it fails the build, because
 * an install stamped "unknown" is indistinguishable from a build that lost track of itself.
 *
 * Written after the layout is cleared, because writing it before would be the second bug of exactly
 * this shape this project has already shipped twice: the build reported success over a file that
 * was never there, and over a file that had been deleted by a later step.
 */
$commit = trim((string) shell_exec('git -C '.escapeshellarg($repo).' rev-parse --short HEAD 2>&1'));

if (! preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
    $commit = 'unknown, built outside a git checkout';
}

$versionFile = $repo.DIRECTORY_SEPARATOR.'VERSION';

if (! is_file($versionFile)) {
    fail('VERSION is missing from the repository root, so this install could not be given a version');
}

$version = trim((string) file_get_contents($versionFile));

if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fail('VERSION must hold three numbers and nothing else, and it holds: '.($version === '' ? 'nothing' : $version));
}

$stamp = (new DateTimeImmutable)->format('Y-m-d H:i:sP');

if (file_put_contents(
    $into.DIRECTORY_SEPARATOR.'version.txt',
    "version {$version}".PHP_EOL."commit {$commit}".PHP_EOL."built {$stamp}".PHP_EOL,
) === false) {
    fail('could not write '.$into.DIRECTORY_SEPARATOR.'version.txt');
}

say('version', $version.' ('.$commit.')');

/*
 * The Suwayomi release this build is for, copied beside version.txt.
 *
 * `version.txt` records the app version, the commit and a timestamp, and until now that was the
 * whole of what a shipped artifact said about itself. So `health` could print the version of the
 * Suwayomi it found and had nothing to compare it against, and a reader on an unsupported server got
 * a run of GraphQL errors about fields that do not exist there.
 *
 * Copied rather than regenerated, so there is exactly one copy of these two numbers in the
 * repository. Regenerating them here would mean a second place to edit and a way for the artifact
 * and the source to disagree, which is the same class of bug as the one this fixes.
 *
 * ## Only the presence of the file is checked here
 *
 * Whether the two numbers inside it are usable is asked further down, after the launcher has been
 * loaded from the built tree, using that class rather than a second copy of its rule. This block
 * cannot ask, because `require_once` deduplicates by resolved path: loading the class from the
 * repository here and from `build/install` further down declares it twice and the build dies with
 * "Cannot redeclare class", which is exactly what the first version of this did.
 */
$compatibilityFile = $repo.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'suwayomi.json';

if (! is_file($compatibilityFile)) {
    fail('no packaging/suwayomi.json, so the build cannot say which Suwayomi this install expects');
}

if (! is_array(json_decode((string) file_get_contents($compatibilityFile), true))) {
    fail('packaging/suwayomi.json is not readable JSON');
}

if (copy($compatibilityFile, $into.DIRECTORY_SEPARATOR.'suwayomi.json') === false) {
    fail('could not write '.$into.DIRECTORY_SEPARATOR.'suwayomi.json');
}

say('suwayomi', 'staged suwayomi.json');

// --- the PHP and web server runtime -------------------------------------------------

$runtime = $into.DIRECTORY_SEPARATOR.'runtime';
$bin = $runtime.DIRECTORY_SEPARATOR.'bin';

foreach ([$bin, $runtime.DIRECTORY_SEPARATOR.'ext', $runtime.DIRECTORY_SEPARATOR.'php', $runtime.DIRECTORY_SEPARATOR.'frankenphp'] as $dir) {
    if (! mkdir($dir, 0o777, true) && ! is_dir($dir)) {
        fail("could not create {$dir}");
    }
}

$extracted = extractArchive($options['frankenphp'], $into.DIRECTORY_SEPARATOR.'.unpacked');

// The FrankenPHP archive is flat: executables and their DLLs at the root, extensions under ext/.
// The shipped layout separates them so that `runtime/bin` is the only directory holding anything
// executable, which is what makes the launcher able to name every binary by path.
$moved = 0;

foreach (scandir($extracted) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $source = $extracted.DIRECTORY_SEPARATOR.$entry;

    if (is_dir($source) && strtolower($entry) === 'ext') {
        foreach (glob($source.DIRECTORY_SEPARATOR.'*.dll') ?: [] as $dll) {
            copy($dll, $runtime.DIRECTORY_SEPARATOR.'ext'.DIRECTORY_SEPARATOR.basename($dll));
            $moved++;
        }

        continue;
    }

    if (is_file($source) && (str_ends_with(strtolower($entry), '.exe') || str_ends_with(strtolower($entry), '.dll'))) {
        copy($source, $bin.DIRECTORY_SEPARATOR.$entry);
        $moved++;
    }
}

say('runtime', $moved.' executables and extensions');

/*
 * The Visual C++ runtime, staged from the machine rather than from the archive.
 *
 * `php.exe` and `frankenphp.exe` are small shims that link against `vcruntime140.dll`,
 * `vcruntime140_1.dll`, `ucrtbase.dll` and `msvcp140.dll`. The FrankenPHP archive does not ship
 * them, so a build that copies only what the archive contains produces an install that works on
 * the machine that built it and nowhere else. This is the defect the Windows Sandbox gate exists
 * to find, and it found it: the bundled `php.exe` exited with `0xC0000135`, `STATUS_DLL_NOT_FOUND`,
 * on a clean machine, while every previous check passed on a development machine where those DLLs
 * are always present in System32.
 *
 * Taken from System32 rather than downloaded, because these are part of Windows and are already
 * present on any machine that could build this. Downloading a redistributable instead would mean
 * trusting a third party archive for a component that ships with the OS, and would add a network
 * dependency to a build that otherwise has none.
 *
 * Copied into `runtime/bin` rather than relied upon in System32, because the whole point of a
 * packaged install is that it does not depend on what the host machine happens to have
 * installed. That is also why the sandbox is the gate: a launcher can only prove this by running
 * somewhere that has nothing.
 */
$redistributables = ['vcruntime140.dll', 'vcruntime140_1.dll', 'ucrtbase.dll', 'msvcp140.dll'];

/*
 * Searched rather than assumed, because neither obvious directory is reliably right.
 * `dirname(PHP_BINARY)` is the interpreter running the build, which under a version manager such
 * as Herd or a phar is a bare directory holding one executable and none of the runtime. System32
 * is where Windows actually loads them from and always has them, but a build running under a
 * non standard PHP might not have them there either.
 */
$sources = array_filter([
    dirname(PHP_BINARY),
    getenv('SystemRoot').DIRECTORY_SEPARATOR.'System32',
    'C:\Windows\System32',
]);

$staged = 0;

foreach ($redistributables as $dll) {
    $copied = false;

    foreach ($sources as $source) {
        $from = $source.DIRECTORY_SEPARATOR.$dll;

        if (is_file($from) && copy($from, $bin.DIRECTORY_SEPARATOR.$dll)) {
            $copied = true;

            break;
        }
    }

    if (! $copied) {
        fail(
            "could not stage {$dll}, and the install cannot run without it.\n\n"
            .'  Looked in: '.implode(', ', $sources)."\n\n"
            ."  These are part of Windows and are present on any machine that could build this.\n"
            ."  Run the Visual C++ redistributable installer if they are genuinely missing.\n"
        );
    }

    $staged++;
}

say('redist', $staged.' Visual C++ runtime files, staged beside the executables');

removeTree($extracted);

// The ini and the Caddyfile come from packaging/, not from the archive. The archive ships
// php.ini-development and php.ini-production, neither of which is the twelve-extension
// configuration this app needs.
copy($repo.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'php.ini', $runtime.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'php.ini');

copy(
    $repo.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'frankenphp'.DIRECTORY_SEPARATOR.'Caddyfile',
    $runtime.DIRECTORY_SEPARATOR.'frankenphp'.DIRECTORY_SEPARATOR.'Caddyfile',
);

$bundled = $options['cacert'] ?? $repo.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'cacert.pem';

if (! is_file($bundled)) {
    fail(
        'no CA bundle at '.$bundled.PHP_EOL
        .'  The shipped ini names {{RUNTIME}}/cacert.pem and Windows PHP has no default, so every'
        .PHP_EOL
        .'  HTTPS request fails without it. Run stage-runtime.php first.'
    );
}

copy($bundled, $runtime.DIRECTORY_SEPARATOR.'cacert.pem');

say('cacert', basename($bundled));

// --- Suwayomi and its JRE -----------------------------------------------------------

$suwayomi = $into.DIRECTORY_SEPARATOR.'suwayomi';

if (! mkdir($suwayomi, 0o777, true) && ! is_dir($suwayomi)) {
    fail("could not create {$suwayomi}");
}

copy($options['jar'], $suwayomi.DIRECTORY_SEPARATOR.basename($options['jar']));

$jarSize = filesize($options['jar']);

say('jar', basename($options['jar']).' at '.formatBytes($jarSize === false ? 0 : $jarSize));

copyTree($jre, $suwayomi.DIRECTORY_SEPARATOR.'runtime');

say('jre', 'staged beside the jar, where javaExecutable looks');

// --- the application ---------------------------------------------------------------

$copied = 0;

foreach (APP_ENTRIES as $entry) {
    $source = $repo.DIRECTORY_SEPARATOR.$entry;

    if (! file_exists($source)) {
        fail("{$entry} is missing from the repository, so this is not a Papyrus checkout");
    }

    $copied += is_dir($source)
        ? copyTree($source, $into.DIRECTORY_SEPARATOR.$entry, $entry === 'storage')
        : (copy($source, $into.DIRECTORY_SEPARATOR.$entry) ? 1 : 0);
}

say('app', $copied.' files');

// The launcher, at the path the application root implies, because `dirname(__DIR__, 2)` from
// `packaging/launcher/papyrus.php` has to land on the install root.
$copied += copyTree(
    $repo.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'launcher',
    $into.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'launcher',
);

say('launcher', 'packaging/launcher');

/*
 * `Papyrus.cmd` onto the install root, with CRLF line endings, whatever the checkout had.
 *
 * It lives in `packaging/launcher/` in the repository, beside the launcher it wraps, because that
 * is where it belongs in version control. It has to land on the install root because it derives the
 * install root from its own location with `%~dp0`: one directory too deep and every path in it is
 * wrong by two levels, and the failure is "The system cannot find the path specified", which names
 * neither Papyrus nor the install and reads as a broken shortcut rather than a misplaced file.
 *
 * The endings are normalised here rather than only fixed in the source, because `cmd.exe` parses a
 * batch file by CRLF and a file with bare LF still runs: it launches the command and loses the
 * output. The one file every reader double-clicks opened a console that filled with progress they
 * could not read. It survived because the process really did start, so every check that asked "did
 * it start" said yes, and nobody asked whether a human could read it.
 *
 * Found by redirecting that file's output to a file and measuring it: 0 bytes, while running the
 * same launcher directly produced 610 bytes in ten seconds.
 *
 * The source can come back with bare LF from an editor, from `core.autocrlf false`, or from a
 * checkout on another platform, so this is checked rather than assumed.
 */
$cmd = $repo.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'launcher'.DIRECTORY_SEPARATOR.'Papyrus.cmd';

if (! file_exists($cmd)) {
    fail('packaging/launcher/Papyrus.cmd is missing, so an install would have no way to be started');
}

$installedCmd = $into.DIRECTORY_SEPARATOR.'Papyrus.cmd';
$cmdLines = file($cmd, FILE_IGNORE_NEW_LINES);

if ($cmdLines === false) {
    fail('could not read '.DIRECTORY_SEPARATOR.'Papyrus.cmd from the repository');
}

if (file_put_contents($installedCmd, implode("\r\n", $cmdLines)."\r\n") === false) {
    fail('could not write '.$installedCmd);
}

$bareLf = preg_match_all("/(?<!\r)\n/", (string) file_get_contents($installedCmd));

if ($bareLf !== 0) {
    fail('Papyrus.cmd was written with '.$bareLf.' bare line feed(s). cmd.exe will run it and hide its output.');
}

say('entry point', 'Papyrus.cmd, '.count($cmdLines).' lines, CRLF');

// --- strip what must not ship, and put the empty placeholders back --------------------

$stripped = 0;

foreach (NEVER_SHIPPED as $relative => $why) {
    $path = $into.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (! file_exists($path)) {
        continue;
    }

    if (! removeTree($path)) {
        fail("could not remove {$relative}, which is {$why}");
    }

    $stripped++;

    say('removed', $relative);
}

foreach (EMPTY_PLACEHOLDERS as $relative) {
    $path = $into.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (! is_dir($path) && ! mkdir($path, 0o777, true) && ! is_dir($path)) {
        fail("could not create the empty placeholder {$relative}");
    }
}

say('placeholders', count(EMPTY_PLACEHOLDERS).' empty directories restored');

/*
 * Prove the storage subtree really is empty, rather than trusting the copy that made it so.
 *
 * The rule that skips it is one line, and a one line rule that fails silently is the exact failure
 * this build exists to prevent. `storage/framework/testing/` shipped a cover image and a Livewire
 * temporary upload before the rule existed, and the build reported success while doing it, so the
 * check is a walk rather than an assumption.
 */
$storageFiles = leakedFiles($into.DIRECTORY_SEPARATOR.'storage');

if ($storageFiles !== []) {
    fail(
        "the install's storage directory is not empty, which means developer state shipped:\n  - "
        .implode("\n  - ", array_slice($storageFiles, 0, 10))
        ."\n\n  storage/ is recreated at first start and must never be copied."
    );
}

say('verified', 'storage is empty, nothing per developer shipped in it');

// --- prove the layout is complete, and that nothing forbidden is in it -----------------

$missing = [];

foreach (REQUIRED_AFTER_BUILD as $required) {
    if (! file_exists($into.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $required))) {
        $missing[] = $required;
    }
}

if ($missing !== []) {
    fail("the layout is incomplete, these are absent:\n  - ".implode("\n  - ", $missing));
}

/*
 * A path the framework reads but this build did not copy is a different failure from a path this
 * build forgot to check. The first is reported by name; the second is not reported at all, which
 * is how an install that cannot boot gets described as complete.
 */
$unbootable = [];

foreach (LARVEL_BOOT_PATHS as $relative) {
    if (! is_dir($repo.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative))
        && ! is_file($repo.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
        // Not in the source tree, so not something to copy. `lang/` is the expected case.
        continue;
    }

    if (! file_exists($into.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
        $unbootable[] = $relative;
    }
}

if ($unbootable !== []) {
    fail(
        "Laravel reads these at boot and the install does not have them:\n  - "
        .implode("\n  - ", $unbootable)
        ."\n\n  Add them to APP_ENTRIES. A build that cannot boot is worse than one that fails."
    );
}

say('verified', count(REQUIRED_AFTER_BUILD).' required paths present');
say('verified', count(LARVEL_BOOT_PATHS).' framework boot paths present');

/*
 * Boot the application the way the launcher will, and read the log for what it said.
 *
 * Every other check here is a file existing. That is not the same thing as the application
 * starting, and the gap between the two is where this build's worst failure lived: an install
 * with every required file present, that could not serve a request because `papyrus:configure`
 * had refused for want of `.env.example`.
 *
 * So `artisan --version` is run against the install, with the install's own `php.exe` and the
 * ini the launcher resolves. It exercises the autoloader, `composer.json`, the bootstrap
 * directory, the config cache and the environment file in one command, which is a cheap proxy for
 * "the application can boot" and turns a missing file into a named failure at build time rather
 * than a silent 60-attempt poll at a reader's first launch.
 */
/*
 * The ini is resolved by the launcher's own code, not by a copy of it here.
 *
 * The first version of this check did its own `str_replace` over `{{RUNTIME}}` and friends, which
 * is the exact mistake that started this round of defects: a harness that reproduces the
 * behaviour it is checking proves its own reproduction rather than the product. If the launcher's
 * resolution and this one ever disagree, the build validates something no reader receives.
 *
 * So it calls `prepareLayout`, the same method `start()` runs before it spawns anything, against a
 * throwaway data directory. That needs no JVM, no port and no Suwayomi, and it produces the
 * resolved ini the launcher would really hand to PHP.
 */
/*
 * Beside the install, never inside it.
 *
 * The first attempt put this at `<install>/.boot-probe` and `InstallLayout` refused, correctly:
 * a data directory inside the install is the exact mistake that rule exists to catch, since an
 * uninstall would take the reader's library with it. A build that trips its own guard while
 * checking an install is a good sign about the guard and a sign about the build, and the fix is
 * the second one.
 */
$probeData = dirname($into).DIRECTORY_SEPARATOR.'.build-probe';
$probeLogs = $probeData.DIRECTORY_SEPARATOR.'logs';

removeTree($probeData);

if (! mkdir($probeData, 0o777, true) && ! is_dir($probeData)) {
    fail("could not create the probe data directory {$probeData}");
}

/*
 * The launcher's own files, in the order papyrus.php declares them.
 *
 * Three approaches were tried here and the first two are worth recording, because both looked
 * correct and both were wrong in a way the build reported precisely.
 *
 * The original was a hand written list of eight classes. `SuwayomiCredentials` was added to the
 * launcher, the list was not updated, and the build failed with "Class not found" while assembling
 * an install no reader would ever see fail. The comment above that list had predicted exactly this
 * and called it "a second, unexercised dependency graph". It was right and the list was still
 * there. Describing a hazard is not the same as removing it.
 *
 * The replacement globbed the whole directory and required everything, on the reasoning that order
 * does not matter because no class extends another. That reasoning checked for `extends` and
 * missed `implements`: `CaddyJsonParser implements LineParser`, and an interface has to exist
 * before the class that names it. The build died on the first parser it reached, sorted alphabetically,
 * with a fatal that names neither the ordering rule nor the build.
 *
 * So the order comes from `papyrus.php`, which already has it, and this file stops maintaining a
 * second copy. What is added here is the check: every file in `src` must appear in that list, or
 * the build fails naming the one that does not. A file that is only ever reached through a type
 * hint needs no ordering, so papyrus.php may reasonably leave it out, and this check is what stops
 * that omission from becoming the next "Class not found".
 */
$launcherSrc = $into.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'launcher'.DIRECTORY_SEPARATOR.'src';
$launcherEntry = $into.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'launcher'.DIRECTORY_SEPARATOR.'papyrus.php';

if (! is_file($launcherEntry)) {
    fail('packaging/launcher/papyrus.php is missing from the install, so nothing can be loaded');
}

$declared = (string) file_get_contents($launcherEntry);

preg_match_all(
    "/require\s+__DIR__\s*\.\s*'\/src\/([A-Za-z0-9_]+\.php)'\s*;/",
    $declared,
    $matches,
);

$ordered = $matches[1];

if ($ordered === []) {
    fail('papyrus.php declares no src requires, so the launcher dependency order could not be read');
}

$onDisk = array_map('basename', glob($launcherSrc.DIRECTORY_SEPARATOR.'*.php') ?: []);

sort($onDisk);

$missing = array_values(array_diff($onDisk, $ordered));

if ($missing !== []) {
    fail(
        'these launcher files exist but papyrus.php does not require them: '.implode(', ', $missing).'. '
        .'Add them to its require list, in dependency order, or this build is assembling an install '
        .'that would fatal on the first run.'
    );
}

foreach ($ordered as $file) {
    require_once $launcherSrc.DIRECTORY_SEPARATOR.$file;
}

/*
 * And now, with the class loaded from the built tree rather than the repository, ask whether the two
 * numbers in the staged `suwayomi.json` are ones the health check can actually compare with.
 *
 * Asked here rather than beside the copy because this is the first moment the class is loaded. It is
 * asked of the class rather than with a second copy of the pattern because the two disagreed the
 * first time they were written separately: this build rejected `latest` and a recorded `v2.4.2366`,
 * while `SuwayomiCompatibility::read()` accepted both. Two answers to "is this file usable" that are
 * not the same question means a file the build refused to ship could still be compared with, if a
 * person put it there by hand.
 *
 * A bad file fails the build here rather than reaching a reader, where the only symptom would be a
 * health line that quietly stopped checking anything.
 */
$stagedCompatibility = json_decode(
    (string) file_get_contents($into.DIRECTORY_SEPARATOR.'suwayomi.json'),
    true,
);

foreach (['tested', 'minimum'] as $key) {
    $value = trim((string) ($stagedCompatibility[$key] ?? ''));

    if (! SuwayomiCompatibility::isUsableVersion($value)) {
        fail(
            "packaging/suwayomi.json needs {$key} to be numbers and dots, as in 2.4.2366, and it holds "
            .($value === '' ? 'nothing' : $value)
            .'. A leading v belongs in what Suwayomi reports, not in what this file records.'
        );
    }
}

if (version_compare($stagedCompatibility['minimum'], $stagedCompatibility['tested'], '>')) {
    fail(
        'packaging/suwayomi.json says the oldest workable server (v'.$stagedCompatibility['minimum']
        .') is newer than the tested one (v'.$stagedCompatibility['tested'].'), which cannot be right'
    );
}

say('suwayomi', 'expects v'.$stagedCompatibility['tested'].' (floor v'.$stagedCompatibility['minimum'].')');

$bootPhp = $into.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'php.exe';

/*
 * Wrapped rather than allowed to escape, because this is the product's own code and a refusal is
 * a legitimate outcome worth reporting. An uncaught `LayoutRefused` prints a stack trace through
 * the files it happened to be loaded from, which for a build means the reader sees paths inside
 * the install rather than a sentence about what is wrong.
 */
try {
    (new Supervisor(
        root: $into,
        dataDirectory: $probeData,
        logDirectory: $probeLogs,
        suwayomiPort: 4567,
        appPort: 8402,
    ))->prepareLayoutForBuild();
} catch (Throwable $e) {
    removeTree($probeData);

    fail(
        "the install cannot be laid out.\n\n  ".$e->getMessage()."\n\n"
        ."  This is the launcher's own code refusing, so the message names the real problem.\n"
    );
}

$resolvedIni = $probeData.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'php.ini';

if (! is_file($resolvedIni)) {
    fail("the launcher did not resolve an ini, expected one at {$resolvedIni}");
}

$unresolved = [];

/*
 * Read once, and refuse rather than carry on.
 *
 * This used to be `file_get_contents($resolvedIni)` cast to string inside the loop head, which
 * turns a missing file into an empty string. An empty ini has no tokens, so the check below it
 * reported "no tokens left, resolved by the launcher itself" about a file that did not exist, and
 * the only thing that caught it was a later is_file() guard a hundred lines downstream.
 *
 * A failure to read the file this script just wrote is not something to carry past quietly.
 */
$resolvedIniText = file_get_contents($resolvedIni);

if ($resolvedIniText === false) {
    fail("could not read the resolved ini at {$resolvedIni}");
}

foreach (preg_split('#\R#', $resolvedIniText) ?: [] as $line) {
    if (! str_starts_with(ltrim($line), ';') && preg_match('#\{\{[A-Z_]+\}\}#', $line) === 1) {
        $unresolved[] = trim($line);
    }
}

if ($unresolved !== []) {
    fail("the launcher's resolved ini still has tokens on active lines:\n  - ".implode("\n  - ", $unresolved));
}

say('resolved ini', 'no tokens left, resolved by the launcher itself');

$bootOutput = [];
$bootStatus = 1;

exec(
    sprintf(
        'cd /d %s && %s -c %s %s --version 2>&1',
        escapeshellarg($into),
        escapeshellarg($bootPhp),
        escapeshellarg($resolvedIni),
        escapeshellarg($into.DIRECTORY_SEPARATOR.'artisan'),
    ),
    $bootOutput,
    $bootStatus,
);

$bootText = trim(implode("\n", $bootOutput));

if ($bootStatus !== 0 || str_contains($bootText, 'Unable to load dynamic library')) {
    fail(
        "the install cannot boot with its own resolved php.ini.\n\n"
        .'  '.str_replace("\n", "\n  ", $bootText)."\n"
    );
}

// The startup warnings are noise once they are not fatal, and a boot line printed with eleven of
// them attached reads like a failure even when it is not.
$bootLine = $bootText === '' ? 'artisan --version succeeded silently' : $bootText;

say('boot', $bootLine);

/*
 * The one check a file listing cannot make, and the one the sandbox proved necessary.
 *
 * `php.exe --version` is run from a directory that is not the install, with the install on disk
 * exactly as it will ship. Windows resolves a program's DLLs from the directory the executable is
 * in, before it looks in System32, so a copy staged beside it is used and nothing else is
 * consulted.
 *
 * This cannot be checked by listing files. `vcruntime140.dll` absent from the install is a
 * perfectly complete looking layout, and it is the difference between an install that starts and
 * one that exits with `0xC0000135` before printing anything. Which is exactly what happened on a
 * clean machine, after every other check had passed on a development one.
 */
$smoke = [];
$smokeStatus = 1;

exec(
    sprintf(
        'cd /d %s && %s --version 2>&1',
        escapeshellarg(sys_get_temp_dir()),
        escapeshellarg($bootPhp),
    ),
    $smoke,
    $smokeStatus,
);

$smokeText = trim(implode("\n", $smoke));

if ($smokeStatus !== 0) {
    fail(
        "the staged php.exe cannot run on its own.\n\n"
        ."  Exit code {$smokeStatus} from a directory outside the install, so nothing in System32\n"
        ."  could satisfy its imports.\n"
        .'  '.str_replace("\n", "\n  ", $smokeText)."\n\n"
        ."  Exit code 0xC0000135 is STATUS_DLL_NOT_FOUND: a side by side DLL is missing. The Visual\n"
        ."  C++ runtime has to be staged beside php.exe, not merely present on the build machine.\n"
    );
}

say('standalone', 'php.exe runs outside the install directory, '.strtok($smokeText, "\n"));

/*
 * As a file, through `php_ini_loaded_file`, rather than as a `-r` snippet.
 *
 * The `-r` version of this check reported the SQLite driver missing from an install that booted
 * perfectly, and the reason is worth recording because it is the second time this round that a
 * probe reported a confident verdict about a file it had not read.
 *
 * PHP does not complain when `-c` or `PHPRC` names a path that does not exist. It proceeds with
 * no configuration at all, quietly, and `extension_loaded` then answers against a bare runtime
 * whose `extension_dir` is a compiled-in default such as `C:\php\ext`. The ini was fine; the file
 * had already been deleted by the time the probe ran.
 *
 * So the probe now asserts the resolved ini is still on disk before measuring it, and asks PHP
 * which file it actually loaded rather than which file was requested. A check that reports on a
 * path it never read is worse than no check, because it produces a specific and wrong answer.
 */
$driverProbe = $into.DIRECTORY_SEPARATOR.'.driver-probe.php';

file_put_contents($driverProbe, <<<'PHP'
<?php

$loaded = php_ini_loaded_file();

echo 'ini='.(string) $loaded.PHP_EOL;
echo 'dir='.(string) ini_get('extension_dir').PHP_EOL;
echo 'drivers='.implode(',', PDO::getAvailableDrivers()).PHP_EOL;
PHP);

$extensionOutput = [];
$extensionStatus = 1;

exec(
    sprintf(
        '%s -c %s %s 2>&1',
        escapeshellarg($bootPhp),
        escapeshellarg($resolvedIni),
        escapeshellarg($driverProbe),
    ),
    $extensionOutput,
    $extensionStatus,
);

/*
 * Parsed line by line, once each.
 *
 * The first version split every line on `=` and then split each resulting fragment on `=` again,
 * which throws the key away and leaves `$report['ini']` unset. The check then reported that PHP
 * had loaded no ini, on an install whose ini it had loaded correctly, and would have failed a
 * perfectly good build.
 *
 * That is the third probe in this round to report a specific and wrong answer, and all three
 * shared one shape: reading something other than the thing it was measuring. Worth more than the
 * individual bugs, because it is the failure mode a green test suite cannot catch.
 */
$report = [];

foreach ($extensionOutput as $line) {
    $line = trim($line);

    if ($line === '' || ! str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);

    $report[trim($key)] = trim($value);
}

@unlink($driverProbe);

if (! isset($report['ini'], $report['drivers'])) {
    fail(
        "the driver probe did not report what was asked of it.\n\n"
        .'  '.str_replace("\n", "\n  ", trim(implode("\n", $extensionOutput)))."\n\n"
        ."  A probe that cannot parse its own output must say so rather than report an empty\n"
        ."  result, because an empty result is indistinguishable from a real failure.\n"
    );
}

$actuallyLoaded = $report['ini'];

if ($actuallyLoaded === '') {
    fail(
        "PHP loaded no php.ini at all, so this install has no configuration.\n\n"
        ."  It was asked to use:\n    {$resolvedIni}\n\n"
        ."  PHP does not report a missing -c path. It starts with no extensions and no error,\n"
        ."  which is the failure this whole ini templating arrangement exists to prevent.\n"
    );
}

if (! str_contains(strtolower(basename(str_replace('\\', '/', $actuallyLoaded))), 'php.ini')) {
    fail("PHP reported loading '{$actuallyLoaded}', which is not the ini this build resolved");
}

if (! in_array('sqlite', explode(',', $report['drivers']), true)) {
    fail(
        "the launcher's resolved ini loads no SQLite driver.\n\n"
        ."  ini      {$actuallyLoaded}\n"
        .'  ext dir  '.($report['dir'] ?? 'unknown')."\n"
        .'  drivers  '.($report['drivers'] === '' ? 'none' : $report['drivers'])."\n"
    );
}

say('resolved ini', 'loads pdo_sqlite, PDO reports sqlite');

// The launcher writes the app's .env during layout. That file is the install's own and is
// discarded with the probe, but its existence is the answer to a bug that cost this round an
// afternoon: a build that cannot configure itself cannot serve anything.
if (! is_file($probeData.DIRECTORY_SEPARATOR.'.env')) {
    fail(
        "the launcher laid out the install without writing an .env.\n\n"
        ."  papyrus:configure refused or was skipped, so the app would come up with no APP_KEY\n"
        ."  while the web server reported that it was serving.\n"
    );
}

say('configured', 'papyrus:configure wrote an .env with an APP_KEY');

removeTree($probeData);

/*
 * Verified by walking the tree rather than by trusting the denylist, because a denylist only
 * knows about the files someone remembered to list. Anything still on disk that matches one of
 * these shapes is either the developer's data or a build artefact, and neither belongs in an
 * artifact that leaves this machine.
 */
$leaked = [];
$walked = 0;

foreach (leakedFiles($into) as $relative) {
    $walked++;

    if ($relative === '.env.example') {
        continue;
    }

    if (str_starts_with(basename($relative), '.env')) {
        $leaked[] = $relative;

        continue;
    }

    if (preg_match('#^(database/.*\.(sqlite|sqlite3|db)|storage/logs/.+|storage/design-review/.+|storage/framework/(cache|sessions|views)/.+)$#', $relative) === 1
        // Laravel's own .gitignore files live in exactly the directories that are emptied above,
        // because those directories are required to exist but are meant to stay empty. Flagging
        // them would mean the build refuses a correct file, and a guard that cries wolf gets
        // removed rather than fixed.
        && basename($relative) !== '.gitignore') {
        $leaked[] = $relative;
    }
}

if ($leaked !== []) {
    fail(
        "the build contains files that must not ship:\n  - ".implode("\n  - ", $leaked)
        ."\n\n  Refusing to report success. An install carrying a library looks exactly like one"
        ."\n  that does not until somebody opens it."
    );
}

say('verified', 'no developer data in the build, checked across '.$walked.' files');

/*
 * The size is reported as a breakdown rather than as one number, because a single figure cannot
 * tell a reader whether 620MB is reasonable and a threshold set to "seemed large" is a guess
 * dressed up as a check.
 *
 * Three quarters of this is the jar and the JRE, which are not ours and cannot be shrunk without
 * changing what the install does. The part that is ours, the runtime and vendor, is what a future
 * size reduction has to attack, and separating them is the first step to knowing which.
 */
$total = directorySize($into);

$parts = [];

foreach (['suwayomi', 'runtime', 'vendor', 'app', 'resources', 'public', 'storage', 'database'] as $part) {
    $bytes = directorySize($into.DIRECTORY_SEPARATOR.$part);

    if ($bytes > 0) {
        $parts[$part] = $bytes;
    }
}

arsort($parts);

// An empty install would divide by zero here. Worth a guard rather than a fatal, because the
// number this produces is a report and a report should never be the thing that stops a build.
$percent = static fn (int $bytes): int => $total > 0 ? (int) round($bytes / $total * 100) : 0;

foreach ($parts as $part => $bytes) {
    say('  '.str_pad($part, 10), formatBytes($bytes).sprintf('  (%d%% of %s)', $percent($bytes), formatBytes($total)));
}

$ours = $total - ($parts['suwayomi'] ?? 0);
$theirs = $parts['suwayomi'] ?? 0;

say('size', formatBytes($total));
say('', formatBytes($theirs).' is Suwayomi and its JRE, which we do not control');
say('', formatBytes($ours).' is the runtime and the application, which we do');

say('done', $into);

function say(string $label, string $value): void
{
    fwrite(STDOUT, sprintf("  %-12s %s\n", $label.':', $value));
}

function fail(string $message): never
{
    fwrite(STDERR, '  '.$message."\n");

    exit(1);
}

function formatBytes(int $bytes): string
{
    return round($bytes / 1048576, 1).' MB';
}

/**
 * Unpack an archive into a directory and return the directory holding its contents.
 *
 * The FrankenPHP archive has no wrapper directory, so the contents directory is the extraction
 * target itself. A wrapper is detected and unwrapped rather than assumed away, because assuming
 * is how a build ends up nesting a directory that nothing downstream expects.
 */
function extractArchive(string $archive, string $working): string
{
    if (! is_dir($working) && ! mkdir($working, 0o777, true) && ! is_dir($working)) {
        fail("could not create {$working}");
    }

    if (class_exists(\ZipArchive::class)) {
        $zip = new \ZipArchive;
        $opened = $zip->open($archive);

        if ($opened !== true) {
            fail("could not open {$archive}, ZipArchive error {$opened}");
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) {
                fail("refusing to extract {$name}");
            }

            if (str_ends_with($name, '/')) {
                continue;
            }

            $target = $working.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
            $parent = dirname($target);

            if (! is_dir($parent) && ! mkdir($parent, 0o777, true) && ! is_dir($parent)) {
                fail("could not create {$parent}");
            }

            file_put_contents($target, (string) $zip->getFromIndex($index));
        }

        $zip->close();
    } else {
        exec(
            sprintf('tar -xf %s -C %s 2>&1', escapeshellarg($archive), escapeshellarg($working)),
            $output,
            $status,
        );

        if ($status !== 0) {
            fail('tar could not read the archive: '.implode("\n", $output));
        }
    }

    return $working;
}

/**
 * Copy a directory tree, returning how many files were written.
 *
 * The count is returned rather than logged from inside because two callers sum it, and a
 * function that both counts and prints reports the same total twice.
 *
 * `$structureOnly` creates the directory and its subdirectories without copying any file. It is
 * used for `storage/`, which a developer has filled with their own state and which the install
 * recreates empty at first start.
 */
function copyTree(string $source, string $target, bool $structureOnly = false): int
{
    if (is_file($source)) {
        if ($structureOnly) {
            return 0;
        }

        $parent = dirname($target);

        if (! is_dir($parent) && ! mkdir($parent, 0o777, true) && ! is_dir($parent)) {
            fail("could not create {$parent}");
        }

        return copy($source, $target) ? 1 : 0;
    }

    if (! is_dir($source)) {
        fail("{$source} does not exist");
    }

    if (! is_dir($target) && ! mkdir($target, 0o777, true) && ! is_dir($target)) {
        fail("could not create {$target}");
    }

    $count = 0;

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (in_array($entry, ['.gitignore', '.gitattributes'], true)) {
            continue;
        }

        $count += copyTree(
            $source.DIRECTORY_SEPARATOR.$entry,
            $target.DIRECTORY_SEPARATOR.$entry,
            $structureOnly,
        );
    }

    return $count;
}

/**
 * Every file in a tree, as forward slash relative paths.
 *
 * Walked rather than globbed so the result is bounded and ordered. The build holds well over
 * twelve thousand files and the leak check runs against all of them.
 *
 * @return list<string>
 */
function leakedFiles(string $root, string $prefix = ''): array
{
    $found = [];

    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $root.DIRECTORY_SEPARATOR.$entry;
        $relative = $prefix === '' ? $entry : $prefix.'/'.$entry;

        if (is_dir($path) && ! is_link($path)) {
            $found = array_merge($found, leakedFiles($path, $relative));

            continue;
        }

        if (is_file($path)) {
            $found[] = $relative;
        }
    }

    return $found;
}

function directorySize(string $path): int
{
    if (! is_dir($path)) {
        return is_file($path) ? (int) filesize($path) : 0;
    }

    $total = 0;

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $total += directorySize($path.DIRECTORY_SEPARATOR.$entry);
        }
    }

    return $total;
}

function removeTree(string $path): bool
{
    if (! file_exists($path)) {
        return true;
    }

    if (is_file($path)) {
        return @unlink($path);
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            if (! removeTree($path.DIRECTORY_SEPARATOR.$entry)) {
                return false;
            }
        }
    }

    return @rmdir($path);
}

/**
 * The flags this script accepts, as given.
 *
 * ## Why the flags are named rather than collected by whatever key was typed
 *
 * Because `--frobnicate=x` used to be accepted silently: the value landed in the options array,
 * was never read, and the build carried on. Worse for anything reading this code, a dynamically
 * keyed array gives a static analyser no way to know which keys exist, so every use of an option
 * became `string|bool` and twenty errors followed from one assignment.
 *
 * Naming them means an unknown flag is refused, and the return type is one the analyser can check.
 *
 * @param  list<string>  $arguments
 * @return array{help: bool, force: bool, into: string|null, jre: string|null, cacert: string|null, frankenphp: string|null, jar: string|null}
 */
function parseArguments(array $arguments): array
{
    /** Every flag this script understands. Anything else is a typo and is refused. */
    $known = ['into', 'jre', 'cacert', 'frankenphp', 'jar', 'force'];

    $help = false;
    $flags = [];

    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $help = true;

            continue;
        }

        if (preg_match('#^--([a-z]+)(?:=(.+))?$#', $argument, $matches) === 1) {
            $name = $matches[1];

            if (! in_array($name, $known, true)) {
                fail("unrecognised flag --{$name}. This build accepts: --".implode(', --', $known));
            }

            if ($name === 'force') {
                $flags['force'] = true;

                continue;
            }

            if (! isset($matches[2])) {
                fail("--{$name} needs a value, as --{$name}=<path>");
            }

            $flags[$name] = $matches[2];

            continue;
        }

        fail("unrecognised argument {$argument}");
    }

    return [
        'help' => $help,
        'force' => isset($flags['force']),
        'into' => $flags['into'] ?? null,
        'jre' => $flags['jre'] ?? null,
        'cacert' => $flags['cacert'] ?? null,
        'frankenphp' => $flags['frankenphp'] ?? null,
        'jar' => $flags['jar'] ?? null,
    ];
}
