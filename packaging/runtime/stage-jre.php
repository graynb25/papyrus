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

/** Where the launcher looks. Changing this means changing Supervisor::javaExecutable too. */
const DEFAULT_DESTINATION = 'suwayomi'.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'jre';

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
    $root = getenv('PAPYRUS_ROOT') ?: dirname(__DIR__, 2);

    return $root.DIRECTORY_SEPARATOR.DEFAULT_DESTINATION;
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
