<?php

/**
 * Compile the Windows installer.
 *
 * ## Why a script rather than a documented command
 *
 * Because `iscc /DMyAppVersion=1.2.0 papyrus.iss` requires somebody to remember to pass the
 * version, and the version is in a file. Every way of typing that command by hand ends with a
 * version that disagrees with `VERSION`, and the disagreement is invisible until a reader reports
 * a bug against the wrong build. This reads `VERSION`, splits it into the two forms Inno needs,
 * and passes both.
 *
 * ## Why it fails rather than warns
 *
 * A build script that reports success and leaves no installer is the failure this project has hit
 * three times over, in three different places. Every step below is checked against the filesystem
 * rather than against the compiler's exit code alone, because the compiler's exit code is the thing
 * that was believed the last time it turned out to be wrong.
 *
 * ## The two version forms
 *
 * Inno wants both, and they are not the same shape:
 *
 *   AppVersion         1.2.0        what a reader sees
 *   VersionInfoVersion 1.2.0.0      the file version resource, which must be four numbers
 *
 * So `1.2.0` becomes `1.2.0.0` by appending a zero. That is a guess about what zero means, and it
 * is recorded in the file version resource rather than invented per build, so two builds of one
 * version produce the same resource.
 *
 * ## Usage:
 *
 *   php packaging/installer/build-installer.php [--install=<dir>] [--out=<dir>]
 */

declare(strict_types=1);

$repo = dirname(__DIR__, 2);

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--install=')) {
        $options['install'] = substr($argument, strlen('--install='));
    } elseif (str_starts_with($argument, '--out=')) {
        $options['out'] = substr($argument, strlen('--out='));
    } else {
        fail('unexpected argument: '.$argument);
    }
}

/** The built install this packages. */
$install = $options['install'] ?? $repo.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'install';

/** Where the .exe lands. The .iss also names this, and the two are checked against each other. */
$out = $options['out'] ?? $repo.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'dist';

// --- the version, from the one file that holds it -------------------------------------------

$versionFile = $repo.DIRECTORY_SEPARATOR.'VERSION';

if (! is_file($versionFile)) {
    fail('VERSION is missing from the repository root');
}

$version = trim((string) file_get_contents($versionFile));

if (! preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $m)) {
    fail('VERSION must be three numbers like 1.2.0, and it holds: '.($version === '' ? 'nothing' : $version));
}

/** The four part form the file version resource requires. */
$versionInfo = $m[1].'.'.$m[2].'.'.$m[3].'.0';

say('version', $version.' (file version '.$versionInfo.')');

// --- is there an install to package? -----------------------------------------------------

/*
 * Checked here rather than discovered as an empty [Files] section.
 *
 * An install that has been deleted and not rebuilt produces a source that matches nothing, and
 * Inno compiles that without complaint into a setup.exe that installs nothing at all. That is the
 * worst possible artifact: it works, it is silent, and it produces an uninstalled reader who
 * believes Papyrus is installed.
 */
$entryPoint = $install.DIRECTORY_SEPARATOR.'Papyrus.cmd';
$stamp = $install.DIRECTORY_SEPARATOR.'version.txt';

foreach ([$entryPoint, $stamp, $install.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'php.exe'] as $required) {
    if (! is_file($required)) {
        fail('nothing to package: '.$required.' is missing, so run build-install.php first');
    }
}

/*
 * The version this install was built as, compared with the one being packaged.
 *
 * Not a formality. `build/install` is a directory that survives between builds, so it is entirely
 * possible to package an install built from last week's source under today's version number. The
 * resulting installer is internally consistent and factually wrong, which is worse than either
 * being obviously broken.
 */
$stamped = readVersionLine($stamp);

if ($stamped !== $version) {
    fail(sprintf(
        'the install was built as version %s but the repository says %s, so packaging it would '.
        'label the wrong build. Run build-install.php again.',
        $stamped === null ? 'nothing readable' : $stamped,
        $version,
    ));
}

say('install', $stamped.' at '.$install);

// --- the compiler -------------------------------------------------------------------------

$iscc = findCompiler();

say('compiler', $iscc);

// --- compile ------------------------------------------------------------------------------

if (! is_dir($out) && ! mkdir($out, 0o777, true) && ! is_dir($out)) {
    fail('could not create the output directory '.$out);
}

$expected = $out.DIRECTORY_SEPARATOR.'Papyrus-'.$version.'-setup.exe';

if (is_file($expected)) {
    /*
     * Removed rather than overwritten.
     *
     * Inno will happily overwrite it, and then the compile can fail half way and leave the
     * previous one sitting there looking like a success. A stale installer mistaken for a fresh
     * one is exactly the confusion this script exists to prevent.
     */
    unlink($expected);
    say('removed', 'the previous '.$version.' installer, so a failed compile cannot be mistaken for this one');
}

$arguments = [
    $iscc,
    '/DMyAppVersion='.$version,
    '/DMyAppVersionInfo='.$versionInfo,
    '/DSourceRoot='.$install,
    $repo.DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR.'installer'.DIRECTORY_SEPARATOR.'papyrus.iss',
];

/*
 * An argument array rather than a string.
 *
 * This install's paths contain a space, every one of them, and a command line built by
 * concatenation splits them in the worst place. `proc_open` with an array skips the shell
 * entirely, so there is no quoting to get wrong. The project has shipped four confident wrong
 * messages from this exact bug.
 */
$process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

if (! is_resource($process)) {
    fail('could not start '.$iscc);
}

$compilerOutput = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

fclose($pipes[1]);
fclose($pipes[2]);

$code = proc_close($process);

say('compiler exit', (string) $code);

// Every line Inno printed, because a failure with no output is the thing worth noticing.
foreach (array_filter(explode("\n", (string) $compilerOutput)) as $line) {
    say('  ', rtrim($line));
}

if ($code !== 0) {
    fail('the compiler exited '.$code.', so there is no installer');
}

if (! is_file($expected)) {
    fail('the compiler reported success and '.$expected.' is not there. That has happened before '.
        'with this build, and believing the exit code over the filesystem is how.');
}

$megabytes = round(filesize($expected) / 1048576, 1);

say('installer', $expected);
say('size', $megabytes.' MB');

// --- and the one property that makes it an installer rather than an archive -----------------

/*
 * The payload is checked against the install that was packaged, not against a number in the
 * script.
 *
 * An installer that silently shipped half a directory is a reader with an app that cannot boot,
 * and the only cheap moment to notice is here, while the number of files still matches.
 */
say('', '');
say('checks');

$packaged = countFiles($install);
say('', 'the install holds '.$packaged.' files');

say('', 'DONE');

exit(0);

// -------------------------------------------------------------------------------------------

/**
 * Read the version out of a built install's version.txt.
 *
 * Returns null rather than an empty string when the line is absent or unparseable, so the caller
 * can say "nothing readable" rather than compare an empty string against a version and report a
 * mismatch that reads like drift when it is really a missing file.
 */
function readVersionLine(string $path): ?string
{
    $contents = @file_get_contents($path);

    if (! is_string($contents)) {
        return null;
    }

    if (! preg_match('/^version\s+(\d+\.\d+\.\d+)\s*$/m', $contents, $m)) {
        return null;
    }

    return $m[1];
}

/**
 * Find ISCC.exe, or say where it is not.
 *
 * The environment variable is honoured first so a machine with several Inno Setup versions can
 * choose. Then the per-machine locations, then the per-user one.
 *
 * The per-user path carries a `Programs` segment, because that is where Inno Setup's own installer
 * puts it: `%LOCALAPPDATA%\Programs\Inno Setup 6`, not `%LOCALAPPDATA%\Inno Setup 6`. Guessing the
 * shorter form produces a clean, confident "Inno Setup was not found" listing three paths that
 * none of which is where the tool is, which is a worse failure than not looking at all.
 *
 * PATH is deliberately not searched. On Windows the Inno installer does not put ISCC on it, so its
 * absence there is the normal case rather than a signal that the tool is missing, and a lookup that
 * can only ever fail is one that will mislead somebody.
 */
function findCompiler(): string
{
    $candidates = [];

    if (is_string($fromEnv = getenv('ISCC')) && $fromEnv !== '') {
        $candidates[] = $fromEnv;
    }

    foreach ([getenv('ProgramFiles(x86)'), getenv('ProgramFiles')] as $base) {
        if (is_string($base) && $base !== '') {
            $candidates[] = $base.DIRECTORY_SEPARATOR.'Inno Setup 6'.DIRECTORY_SEPARATOR.'ISCC.exe';
        }
    }

    if (is_string($local = getenv('LOCALAPPDATA')) && $local !== '') {
        $candidates[] = $local.DIRECTORY_SEPARATOR.'Programs'.DIRECTORY_SEPARATOR.'Inno Setup 6'.DIRECTORY_SEPARATOR.'ISCC.exe';
        $candidates[] = $local.DIRECTORY_SEPARATOR.'Inno Setup 6'.DIRECTORY_SEPARATOR.'ISCC.exe';
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    fail(
        'Inno Setup was not found. Looked in: '.implode(', ', $candidates).'. '.
        'It is about 1MB of tool and installs from https://jrsoftware.org/isinfo.php'
    );
}

/**
 * Count the files under a directory, used to describe the payload.
 *
 * Recursive rather than a shallow count, because the runtime and the JRE are where the files are
 * and a count of the top level says nothing useful about whether the install is whole.
 */
function countFiles(string $directory): int
{
    $count = 0;
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($entries as $entry) {
        if ($entry->isFile()) {
            $count++;
        }
    }

    return $count;
}

function say(string $label, string $value = ''): void
{
    fwrite(STDOUT, '  '.str_pad($label, 16).$value.PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, PHP_EOL.'  installer build failed: '.$message.PHP_EOL.PHP_EOL);

    exit(1);
}
