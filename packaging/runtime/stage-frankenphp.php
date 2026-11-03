<?php

/**
 * Fetch and verify the FrankenPHP archive the build needs.
 *
 * ## Why this script exists at all
 *
 * Because it did not, and the absence cost a rebuild.
 *
 * `stage-runtime.php` owns the other two build inputs: it downloads the JRE and the CA bundle,
 * checks both against pinned digests, and says what it did. The FrankenPHP archive had no such
 * script. It was a loose 57MB zip somebody had downloaded once, and it lived in a scratch
 * directory. A cleanup removed it, and the build could not be reproduced until it was fetched again
 * by hand.
 *
 * A build input that lives on one machine is not a build input, it is a habit. This makes the
 * third input as reproducible as the first two.
 *
 * ## Why this pins a digest it did not get from upstream
 *
 * FrankenPHP publishes no checksum file for this asset, unlike Adoptium, whose digest
 * `stage-runtime.php` verifies against.
 *
 * So the constant below is one this project measured on first download and then committed. That is
 * a weaker guarantee and it is worth being precise about which kind:
 *
 *   It catches corruption, a truncated download, and a changed asset.
 *   It does not independently prove the first download was the genuine one.
 *
 * The honest mitigation is that the digest is in git and visible in review, and that a second
 * machine fetching the same tag gets the same bytes. Claiming more than that would be the kind of
 * comment that makes the next person trust a number they have not checked.
 *
 * ## Why nothing is extracted here
 *
 * `build-install.php` reads the archive and decides what goes where. This script only guarantees
 * the archive is present and correct, so there is one place that knows how the runtime is laid out
 * rather than two that both think they do.
 *
 * ## Usage:
 *
 *   php packaging/runtime/stage-frankenphp.php [--cache=<dir>] [--force]
 */

declare(strict_types=1);

namespace Papyrus\Runtime\Stage;

use Papyrus\Runtime\RuntimePins;

require_once __DIR__.DIRECTORY_SEPARATOR.'RuntimePins.php';

$repo = dirname(__DIR__, 2);
$cache = $repo.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'.tools'.DIRECTORY_SEPARATOR.'build-inputs';
$force = false;

// PHP 8.5's stubs allow $argv to be absent when register_argc_argv is off.
$argv ??= [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--force' || $argument === '-f') {
        $force = true;
    } elseif (str_starts_with($argument, '--cache=')) {
        $cache = substr($argument, strlen('--cache='));
    } else {
        report('unknown argument: '.$argument, 'usage: php stage-frankenphp.php [--force] [--cache=<dir>]');

        exit(2);
    }
}

report('version', RuntimePins::FRANKENPHP_VERSION);
report('archive', RuntimePins::FRANKENPHP_ARCHIVE);

if (! is_dir($cache) && ! mkdir($cache, 0o777, true) && ! is_dir($cache)) {
    report('cache', 'could not create '.$cache);

    exit(1);
}

$archive = rtrim($cache, '\\/').DIRECTORY_SEPARATOR.RuntimePins::FRANKENPHP_ARCHIVE;

/*
 * Already here and already correct.
 *
 * Checked by digest rather than by existence, because the whole point of this script is that a
 * file being present is not evidence that it is the right file. A truncated download left by a
 * killed process is present and wrong.
 */
if (! $force && is_file($archive)) {
    $existing = hash_file('sha256', $archive);

    if ($existing === RuntimePins::FRANKENPHP_SHA256) {
        report('cached', round(filesize($archive) / 1048576).' MB, digest matches');

        exit(0);
    }

    /*
     * Two different reasons to discard, and the message has to say which.
     *
     * An unreadable cached file and a wrong one both need replacing, but only one of them tells
     * anybody anything. Reporting an unreadable file as "digest is , which is not the pinned one"
     * reads as though a digest was computed and rejected, which is the claim this script exists to
     * avoid making.
     */
    report(
        'cached copy',
        $existing === false
            ? 'could not be read, so it cannot be used and will be fetched again'
            : 'digest is '.$existing.', which is not the pinned one',
    );

    if (! unlink($archive)) {
        report('refused', 'could not delete the unusable archive at '.$archive);

        exit(1);
    }
}

report('downloading', RuntimePins::frankenphpUrl());

$handle = fopen($archive, 'wb');

if ($handle === false) {
    report('refused', 'could not open '.$archive.' for writing');

    exit(1);
}

/*
 * Streamed rather than buffered.
 *
 * The archive is 57MB. `file_get_contents` on a URL would hold it all in memory alongside the
 * unzip that follows, and the failure mode of running out is a build that dies without saying why.
 */
$source = @fopen(RuntimePins::frankenphpUrl(), 'rb');

if ($source === false) {
    fclose($handle);
    report('refused', 'could not open the URL. Check the network, or whether the tag still exists.');

    exit(1);
}

$written = 0;

while (! feof($source)) {
    $chunk = fread($source, 1 << 20);

    if ($chunk === false) {
        break;
    }

    if (fwrite($handle, $chunk) === false) {
        break;
    }

    $written += strlen($chunk);
}

fclose($source);
fclose($handle);

report('downloaded', round($written / 1048576).' MB');

/*
 * Verified before anything is done with it.
 *
 * And deleted on a mismatch, rather than left for the next person to trust by accident. An
 * archive named like the one that is wanted, sitting in the cache directory where the wanted one
 * belongs, is the most expensive kind of wrong file: it is found first.
 * "Could not read it" is kept apart from "read it and it is the wrong bytes". `hash_file` returns
 * false rather than throwing, and false is not equal to the pinned digest, so collapsing the two
 * reports an empty digest and deletes the archive for a file that may simply have been locked.
 *
 * That is not hypothetical. An antivirus holding a just written file open is an ordinary event on
 * a Windows machine, and deleting the only copy of a good download because something else had a
 * handle on it turns a recoverable retry into a 57MB re-download.
 */
$digest = hash_file('sha256', $archive);

if ($digest === false) {
    report('unreadable', 'the archive could not be read back, so it cannot be verified');
    report('kept', 'nothing was deleted, because an unreadable file is not a wrong file');

    exit(1);
}

if ($digest !== RuntimePins::FRANKENPHP_SHA256) {
    @unlink($archive);

    report('mismatch', 'expected '.RuntimePins::FRANKENPHP_SHA256);
    report('got', $digest);
    report('deleted', 'the archive, so a later run cannot pick it up by mistake');

    exit(1);
}

report('verified', 'against the digest pinned in this script');

if (! is_readable($archive)) {
    report('refused', 'the archive is there but not readable');

    exit(1);
}

/*
 * One last thing the digest cannot tell you: that it is a zip.
 *
 * A digest match on a file that is not an archive would mean the pinned constant was recorded from
 * a bad download, and that is only discoverable here rather than as a confusing error deep inside
 * the build.
 */
$handle = @fopen($archive, 'rb');

if ($handle === false || fread($handle, 2) !== 'PK') {
    report('refused', 'the archive does not start with PK, so it is not a zip file');

    exit(1);
}

fclose($handle);

report('staged', $archive);

exit(0);

// -------------------------------------------------------------------------------------------

function report(string $label, string $value): void
{
    fwrite(STDOUT, '  '.str_pad($label, 18).$value.PHP_EOL);
}
