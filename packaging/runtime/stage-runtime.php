<?php

/**
 * Stage the JRE the packaged install runs Suwayomi on.
 *
 * This is a build step, not part of the install. It runs once, on the machine building the
 * installer, and leaves a JRE in the layout that the launcher later finds by absolute path.
 *
 * ## Why the version is pinned rather than discovered
 *
 * Suwayomi ships its own runtime, and the archive says what it is: the `jre/release` entry
 * inside their Windows zip reads `JAVA_VERSION="25.0.4"`. Reading that one entry out of a
 * 328MB download costs a few kilobytes of range requests, so the version is a measurement
 * rather than an assumption about a "recent LTS".
 *
 * Matching that version is the point. A packaged reader that runs Suwayomi on whatever JVM
 * the machine happens to have is not self contained at all, it is a reader with an extra
 * prerequisite and a support question attached to it.
 *
 * ## Why Temurin specifically
 *
 * It is the distribution Suwayomi's own build is based on, and its redistribution terms are
 * ours to reason about. Pulling a JRE out of Suwayomi's zip instead would be smaller work and
 * would make the licence question theirs, inside an artifact that also carries 85MB of Electron
 * we have no use for.
 *
 * ## Why the checksum is the point rather than the download
 *
 * The download is the easy half. Verifying it against a published digest before anything is
 * extracted is what makes the staging step trustworthy, and it is why the mismatch path deletes
 * the archive instead of leaving it for the next person to trust by accident.
 *
 * Usage:
 *
 *   php packaging/runtime/stage-jre.php [--force] [--into=<dir>]
 */

declare(strict_types=1);

namespace Papyrus\Runtime;

// Pinned deliberately. A floating "latest 25" makes two builds of the same commit differ, and
// an update to the patch would silently change what ships.
const JRE_VERSION = '25.0.4.1+1';
const JRE_ARCHIVE = 'OpenJDK25U-jre_x64_windows_hotspot_25.0.4.1_1.zip';
const JRE_SHA256 = '4c95451cea98556def2c54f7782933f52a26d4a36bd85e1d59f0364464828b07';

const JRE_URL = 'https://github.com/adoptium/temurin25-binaries/releases/download/'
    .'jdk-25.0.4.1%2B1/'.JRE_ARCHIVE;

// The CA bundle, pinned the same way and for the same reason.
//
// It has to exist because Windows PHP has no default. libcurl on Linux finds a bundle in a
// standard place; on Windows there is no such place, so an install without this file fails every
// HTTPS request with "error adding trust anchors from file". That is the whole failure, and it
// reads like a network problem rather than a missing file.
//
// Pinned rather than fetched fresh, and this one is more of a liability than the JRE is: the
// bundle changes whenever a certificate authority is added or removed, so this digest will
// eventually be stale. That is the correct trade. A bundle that updates itself during a build
// means two builds of one commit ship different trust decisions, and a silently rotating trust
// store is worse than one that needs a deliberate commit to move.
const CACERT_SHA256 = 'a41b5d356aea97a529fe27e0f7316d2f9d946d75927476cf9cf1b90637d00505';

const CACERT_URL = 'https://curl.se/ca/cacert.pem';

/**
 * Where the launcher looks.
 *
 * This is Supervisor::javaExecutable's default, `suwayomi/runtime/bin/java.exe`, deliberately.
 * The alternative of nesting the runtime one level deeper, at `suwayomi/runtime/jre/bin/java.exe`,
 * is tidier to read in a file listing and it is what the staging script wrote at first. Nothing
 * reads it: the launcher's default is the shallower path and it has tests behind it, so the
 * script was the thing that was wrong.
 *
 * The cost of that is invisible until the day someone runs the install without passing
 * `javaExecutable`, which is every real reader. A staged runtime at a path nothing looks in is
 * an install that works on the machine that built it and nowhere else.
 */
const DEFAULT_DESTINATION = 'suwayomi'.DIRECTORY_SEPARATOR.'runtime';

$options = parseArguments($argv);

if ($options['help']) {
    fwrite(STDOUT, "Usage: php stage-jre.php [--force] [--into=<dir>]\n");

    exit(0);
}

$destination = $options['into'] ?? defaultDestination();

report('destination', $destination);

if (is_dir($destination) && is_file($destination.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'java.exe')) {
    if (! $options['force']) {
        report('already staged', 'pass --force to replace it');

        exit(0);
    }

    report('replacing', 'the existing JRE at that path');

    if (! removeTree($destination)) {
        fwrite(STDERR, "  could not remove the existing JRE, refusing to stage over it\n");

        exit(1);
    }
}

$archive = downloadArchive($destination);
$digest = hash_file('sha256', $archive);

report('downloaded', formatBytes(filesize($archive)));
report('sha256', (string) $digest);

if (! hash_equals(JRE_SHA256, $digest)) {
    // Deleted rather than kept. A mismatched archive left on disk is something the next run,
    // or the next person, can mistake for something already checked.
    @unlink($archive);

    fwrite(STDERR, '  expected '.JRE_SHA256."\n");
    fwrite(STDERR, "  this does not match, so it has been deleted and nothing was staged\n");

    exit(1);
}

report('verified', 'against the published digest');

extract($archive, $destination);

@unlink($archive);

verifyStagedJre($destination);

report('staged', 'version '.JRE_VERSION);

stageCaBundle();

/**
 * Put the CA bundle where `curl.cainfo` in the shipped ini points.
 *
 * That setting says `{{RUNTIME}}/cacert.pem`, so this writes to the runtime root, beside `bin`
 * and `ext`. It is the only file the ini names inside the install, which is why it belongs here
 * rather than in the data directory: the ini is a template that is resolved against the install,
 * and writing the bundle anywhere else would mean resolving that one token somewhere else too.
 *
 * The download is verified by digest like the JRE. The content check is the part that matters
 * here, because a truncated or HTML error page served with a 200 would otherwise be written
 * straight into the install as a trust store.
 */
function stageCaBundle(): void
{
    // `<root>/runtime`, which is where `{{RUNTIME}}` resolves to and therefore exactly where
    // `curl.cainfo` is looking. Not the Suwayomi runtime one level down, which is a different
    // directory that happens to share the word: putting the bundle there produces an install that
    // stages cleanly and then fails every HTTPS request.
    $runtime = phpRuntimeDirectory();
    $target = $runtime.DIRECTORY_SEPARATOR.'cacert.pem';

    if (! is_dir($runtime) && ! mkdir($runtime, 0o777, true) && ! is_dir($runtime)) {
        fwrite(STDERR, "  could not create {$runtime}\n");

        exit(1);
    }

    if (is_file($target)) {
        report('ca bundle', 'already staged');

        return;
    }

    report('ca bundle', 'downloading');

    $temporary = $target.'.part';

    $source = @fopen(CACERT_URL, 'rb');
    $sink = @fopen($temporary, 'wb');

    if ($source === false || $sink === false) {
        @unlink($temporary);

        fwrite(STDERR, '  could not reach '.CACERT_URL."\n");

        exit(1);
    }

    while (! feof($source)) {
        fwrite($sink, (string) fread($source, 1048576));
    }

    fclose($source);
    fclose($sink);

    $digest = hash_file('sha256', $temporary);

    if (! hash_equals(CACERT_SHA256, (string) $digest)) {
        @unlink($temporary);

        fwrite(STDERR, '  expected '.CACERT_SHA256."\n");
        fwrite(STDERR, "  the bundle does not match, so it has been deleted and nothing was staged\n");

        exit(1);
    }

    $contents = (string) file_get_contents($temporary);

    // A digest match already implies this, so it cannot fail on the pinned download. It is here
    // because the pin is a constant someone will eventually bump to whatever curl.se served that
    // day, and this is the check that tells them whether they bumped it to a CA bundle.
    if (! str_contains($contents, 'BEGIN CERTIFICATE')) {
        @unlink($temporary);

        fwrite(STDERR, "  the download parsed, but it is not a certificate bundle\n");

        exit(1);
    }

    $certificates = substr_count($contents, 'BEGIN CERTIFICATE');

    if (! rename($temporary, $target)) {
        @unlink($temporary);

        fwrite(STDERR, "  could not move the bundle into place\n");

        exit(1);
    }

    report('ca bundle', $certificates.' certificates, digest verified');
}

/**
 * Report one labelled line of progress. The output is read by whoever is watching a build run,
 * so a step that is skipped says so rather than printing nothing.
 */
function report(string $label, string $value): void
{
    fwrite(STDOUT, sprintf("  %-16s %s\n", $label.':', $value));
}

function formatBytes(int $bytes): string
{
    return round($bytes / 1048576, 1).' MB';
}

/**
 * Confirm the staged tree is a runtime and not a full development kit.
 *
 * `javac.exe` is the tell. Its absence is what keeps the packaged install roughly 130MB smaller
 * than a JDK would be, and its presence would mean the wrong archive was staged, so this is
 * checked rather than assumed from the download URL.
 */
function verifyStagedJre(string $destination): void
{
    $java = $destination.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'java.exe';

    if (! is_file($java)) {
        fwrite(STDERR, "  no java.exe in the staged runtime, so the archive was not what it claimed\n");

        exit(1);
    }

    if (is_file($destination.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'javac.exe')) {
        fwrite(STDERR, "  the staged runtime contains javac.exe, which means a JDK was staged\n");

        exit(1);
    }

    report('javac', 'absent, so it is a JRE');

    $reported = trim((string) shell_exec(escapeshellarg($java).' -version 2>&1'));

    foreach (array_filter(explode("\n", $reported)) as $line) {
        report('java -version', trim($line));
    }
}

/**
 * Download to a temporary file beside the destination.
 *
 * Not to the system temp directory: the archive is 55MB, and the destination's filesystem is
 * the one with space and the one we are about to write the extracted tree onto anyway.
 */
function downloadArchive(string $destination): string
{
    $parent = dirname($destination).DIRECTORY_SEPARATOR;

    if (! is_dir($parent) && ! mkdir($parent, 0o777, true) && ! is_dir($parent)) {
        fwrite(STDERR, "  could not create {$parent}\n");

        exit(1);
    }

    $target = $parent.'.'.JRE_ARCHIVE.'.part';

    report('downloading', JRE_VERSION.' (about 56 MB)');

    $source = @fopen(JRE_URL, 'rb');
    $sink = @fopen($target, 'wb');

    if ($source === false || $sink === false) {
        fwrite(STDERR, '  could not reach '.JRE_URL."\n");

        exit(1);
    }

    // Streamed rather than slurped, so peak memory stays flat regardless of the archive size.
    while (! feof($source)) {
        if (fwrite($sink, (string) fread($source, 1048576)) === false) {
            fwrite(STDERR, "  the download stopped part way, nothing staged\n");

            exit(1);
        }
    }

    fclose($source);
    fclose($sink);

    return $target;
}

/**
 * Extract the single top level directory the archive contains into the destination.
 *
 * The archive has exactly one folder inside it, and the destination is the JRE itself rather
 * than that folder's parent, so the wrapper is stripped here instead of leaving the launcher
 * pointing at `jre/jdk-25.0.4.1+1-jre/bin/java.exe` and failing at launch.
 */
function extract(string $archive, string $destination): void
{
    report('extracting', basename($destination));

    if (class_exists(\ZipArchive::class)) {
        extractWithZipArchive($archive, $destination);

        return;
    }

    extractWithTar($archive, $destination);
}

function extractWithZipArchive(string $archive, string $destination): void
{
    $zip = new \ZipArchive;
    $opened = $zip->open($archive);

    if ($opened !== true) {
        fwrite(STDERR, "  the downloaded archive would not open, error {$opened}\n");

        exit(1);
    }

    // Nothing here is a symlink and nothing here should escape the destination, but a build
    // step that unpacks an unverified archive is exactly the thing to be careful with.
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if ($name === '' || str_contains($name, '..') || str_starts_with($name, '/')) {
            fwrite(STDERR, "  refusing to extract {$name}\n");

            exit(1);
        }

        if (str_ends_with($name, '/')) {
            continue;
        }

        $relative = stripWrapper($name);

        if ($relative === null) {
            continue;
        }

        $path = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($path);

        if (! is_dir($parent) && ! mkdir($parent, 0o777, true) && ! is_dir($parent)) {
            fwrite(STDERR, "  could not create {$parent}\n");

            exit(1);
        }

        $contents = $zip->getFromIndex($index);

        if ($contents === false || file_put_contents($path, $contents) === false) {
            fwrite(STDERR, "  could not write {$path}\n");

            exit(1);
        }
    }

    $zip->close();
}

/**
 * Fallback for a runtime without ext-zip, which is most packaged PHP builds.
 *
 * `tar` ships with Windows 10 and later and reads zip archives, so this does not add a
 * dependency the build machine might not have.
 */
function extractWithTar(string $archive, string $destination): void
{
    $staging = $destination.'.unpacked';

    if (is_dir($staging) && ! removeTree($staging)) {
        fwrite(STDERR, "  could not clear the staging directory at {$staging}\n");

        exit(1);
    }

    if (! mkdir($staging, 0o777, true)) {
        fwrite(STDERR, "  could not create {$staging}\n");

        exit(1);
    }

    $command = sprintf(
        'tar -xf %s -C %s 2>&1',
        escapeshellarg($archive),
        escapeshellarg($staging),
    );

    exec($command, $output, $status);

    if ($status !== 0) {
        fwrite(STDERR, '  tar refused the archive: '.implode("\n", $output)."\n");

        exit(1);
    }

    // The archive's single wrapper directory is stripped so the launcher sees bin/java.exe
    // directly under the destination.
    $wrapper = null;

    foreach (scandir($staging) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $wrapper = $staging.DIRECTORY_SEPARATOR.$entry;

            break;
        }
    }

    if ($wrapper === null || ! is_dir($wrapper)) {
        fwrite(STDERR, "  the archive did not contain the expected folder\n");

        exit(1);
    }

    if (! rename($wrapper, $destination)) {
        fwrite(STDERR, "  could not move the unpacked runtime into place\n");

        exit(1);
    }

    @rmdir($staging);
}

/**
 * Remove the archive's single wrapper directory from a path, or return null to skip the entry.
 *
 * A Temurin archive has one top level folder. An entry outside it would be a flat archive,
 * which is not what is pinned, so it is skipped rather than silently unpacked to the top level
 * where it could overwrite something.
 */
function stripWrapper(string $name): ?string
{
    $parts = explode('/', $name);

    if (count($parts) < 2) {
        return null;
    }

    return implode('/', array_slice($parts, 1));
}

function removeTree(string $path): bool
{
    if (! is_dir($path)) {
        return true;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path.DIRECTORY_SEPARATOR.$entry;

        if (is_dir($child) && ! is_link($child)) {
            if (! removeTree($child)) {
                return false;
            }
        } elseif (! @unlink($child)) {
            return false;
        }
    }

    return @rmdir($path);
}

function defaultDestination(): string
{
    return installRoot().DIRECTORY_SEPARATOR.DEFAULT_DESTINATION;
}

function installRoot(): string
{
    return getenv('PAPYRUS_ROOT') ?: dirname(__DIR__, 2);
}

/**
 * The PHP runtime directory, which is `{{RUNTIME}}` in the shipped ini.
 *
 * Mirrors Supervisor's own `path('runtime')` deliberately. These two have to agree and nothing
 * enforces it, which is exactly the kind of pair that stays right until someone tidies one of
 * them.
 */
function phpRuntimeDirectory(): string
{
    return installRoot().DIRECTORY_SEPARATOR.'runtime';
}

/**
 * @return array{force: bool, into: ?string, help: bool}
 */
function parseArguments(array $arguments): array
{
    $parsed = ['force' => false, 'into' => null, 'help' => false];

    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--force' || $argument === '-f') {
            $parsed['force'] = true;
        } elseif ($argument === '--help' || $argument === '-h') {
            $parsed['help'] = true;
        } elseif (str_starts_with($argument, '--into=')) {
            $parsed['into'] = substr($argument, strlen('--into='));
        } else {
            fwrite(STDERR, "  unrecognised argument {$argument}\n");

            exit(1);
        }
    }

    return $parsed;
}
