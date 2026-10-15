<?php

/**
 * Package the built install as a portable zip: unpack it anywhere and run it.
 *
 * ## What this is for
 *
 * A reader who wants to try Papyrus without installing it, or run it from a USB stick, or keep it on a
 * machine they do not administer. No installer, no registry, no admin rights, no uninstaller.
 *
 * ## The one thing that cannot be done to it, and why that is not a defect
 *
 * **A zip cannot carry an Authenticode signature.** Measured, not assumed: signing one with a real
 * certificate and a real `signtool.exe` returns
 *
 *     SignTool Error: This file format cannot be signed because it is not recognized.
 *
 * Authenticode covers PE images, catalogs, MSI packages and PowerShell files. A zip is a container, not
 * an executable, and Windows has no notion of a signature on one. This is not a limitation to work
 * around and no amount of cleverness produces a signed zip.
 *
 * So the portable is distributed the way every other project distributes a tarball or a zip: with a
 * SHA-256 published beside it, and a reader who compares that hash knows the file is the one that was
 * published. The `.exe` gets the stronger mechanism because the `.exe` can.
 *
 * ## Why the leak guards are here and not in the zip step
 *
 * Because the zip is made from `build/install`, which is a build output that anybody can regenerate on a
 * machine where the developer's own library is the live one. The two things that must never travel are
 * a real `.env` with its `APP_KEY`, and a real reader database. Both are refused loudly rather than
 * filtered quietly, because a portable zip is the artefact most likely to be handed to another person.
 *
 * ## Usage
 *
 *     php packaging/installer/build-portable.php [--install=<dir>] [--out=<dir>]
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

$install = $options['install'] ?? $repo.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'install';
$out = $options['out'] ?? $repo.DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR.'dist';

if (! preg_match('#^[A-Za-z]:[\\\\/]#', $install) && ! str_starts_with($install, '\\\\')) {
    fail("the install root must be an absolute path, and {$install} is relative.");
}

if (! is_dir($install)) {
    fail("no install at {$install}. Run build-install.php first.");
}

/*
 * The output must not live inside the install.
 *
 * Measured, because it looked harmless: build once into `<install>\dist` and the archive is fine.
 * Build a second time and it contains `dist1/Papyrus-1.2.0-portable.zip`, the archive from the run
 * before. The file count goes up by two every run, and on the real install that is a 305MB file
 * eating a copy of itself each time.
 *
 * Nothing warns about it. The build reports success, the entry count looks plausible, and the archive
 * is a valid zip containing a valid previous zip, which is the most comfortable possible failure.
 *
 * Refused here, where the message can name the mistake.
 */
$normalisedInstall = rtrim(strtolower(str_replace('/', '\\', $install)), '\\');
$normalisedOut = rtrim(strtolower(str_replace('/', '\\', $out)), '\\');

if ($normalisedOut === $normalisedInstall
    || str_starts_with($normalisedOut.'\\', $normalisedInstall.'\\')) {
    fail(
        "the output directory is inside the install.\n\n"
        ."  install  {$install}\n"
        ."  output   {$out}\n\n"
        ."  A build then packages the previous build's archive, and every run after that\n"
        .'  carries another copy of it. Put the output beside the install, not inside it.'
    );
}

/*
 * The install has to be a finished install, and that is asked before anything is packaged.
 *
 * This used to read the version out of `version.txt` and fall back to `0.0.0` when it could not:
 *
 *     $version = preg_match('/\d+\.\d+\.\d+/', $version, $matches) === 1 ? $matches[0] : '0.0.0';
 *
 * A missing `version.txt` is what a half cleared install looks like, because `build-install.php`
 * writes that file last and had already emptied the directory before it failed. So the fallback did
 * not mark a broken install, it named one: it produced `Papyrus-0.0.0-portable.zip`, and one of those
 * was still sitting in `build/dist` from a run three days before this was found.
 *
 * Measured, on an install that had been cleared and then abandoned:
 *
 *     files           12143 from the install, plus the readme
 *     size            70.8 MB
 *
 * against 12672 files and 623 MB for the same version. Nothing said so, because 12143 is a number and
 * this script had no second number to compare it with. The build reported DONE and recorded a hash.
 *
 * So it is refused, in two parts. The stamp has to exist and hold a version, because that is the one
 * file whose absence means the install did not finish. And the version has to match the repository's,
 * because a tree built from last week's source packaged under today's number is the same mistake one
 * stage later, and `build-installer.php` already refuses it for the `.exe`.
 */
$stampPath = $install.DIRECTORY_SEPARATOR.'version.txt';

if (! is_file($stampPath)) {
    fail(
        "the install at {$install} has no version.txt, so it is not a finished install.\n\n"
        ."  version.txt is written near the end of build-install.php. If it is missing then the\n"
        .'  last build emptied that directory and did not finish. Run build-install.php first.'
    );
}

$stamp = (string) file_get_contents($stampPath);

if (preg_match('/\d+\.\d+\.\d+/', $stamp, $matches) !== 1) {
    fail(
        "{$stampPath} does not hold a version, so the install cannot be identified.\n\n"
        .'  It holds: '.str_replace("\n", ' | ', trim($stamp))
    );
}

$version = $matches[0];

$repositoryVersionFile = $repo.DIRECTORY_SEPARATOR.'VERSION';
$repositoryVersion = is_file($repositoryVersionFile)
    ? trim((string) file_get_contents($repositoryVersionFile))
    : '';

if ($repositoryVersion !== '' && $version !== $repositoryVersion) {
    fail(
        "the install is version {$version} but the repository says {$repositoryVersion}.\n\n"
        ."  Packaging it would ship a stale tree under today's number.\n"
        .'  Run build-install.php first.'
    );
}

say('version', $version);
say('install', $install);

// --- refuse anything that must not travel ------------------------------------------------------

/*
 * Refused, not skipped.
 *
 * A filter that quietly omits a file looks identical to a filter working, which is how the licences
 * page reported zero npm packages instead of three hundred. If something private is in the install,
 * the build stops and says what it found, because the reader who receives this zip should never be the
 * first person to find out it contained somebody's `APP_KEY`.
 */
$forbidden = [
    '.env' => 'a real environment file, which holds this machine APP_KEY',
    'database'.DIRECTORY_SEPARATOR.'database.sqlite' => 'a live reader database',
    'database'.DIRECTORY_SEPARATOR.'database.sqlite-wal' => 'reader database write-ahead log',
    'database'.DIRECTORY_SEPARATOR.'database.sqlite-shm' => 'reader database shared memory',
    '.build-probe' => 'a build probe directory',
];

$found = [];

foreach ($forbidden as $relative => $why) {
    if (file_exists($install.DIRECTORY_SEPARATOR.$relative)) {
        $found[] = $relative.' is there, and it is '.$why;
    }
}

if ($found !== []) {
    fail(
        "the install contains things a portable zip must never carry:\n  - "
        .implode("\n  - ", $found)
        ."\n\n  A portable is the artefact most likely to be handed to somebody else. If this is the\n"
        ."  developer own install rather than a build output, rebuild it first:\n\n"
        .'    php packaging/runtime/build-install.php --into=<an absolute path>'
    );
}

say('leak check', 'no .env, no live database, no probe directory');

// --- the readme that ships inside it -----------------------------------------------------------

/*
 * Written into the zip rather than beside it.
 *
 * A portable has no installer to explain itself, so a reader who unzips it onto a USB stick and double
 * clicks it has to be told where the library goes and that it is beside the application rather than in
 * it. The installer's uninstall behaviour is the opposite, so the two must not be confused.
 */
$readme = <<<'TEXT'
Papyrus, portable
==================

Unpack this anywhere and run Papyrus.cmd. There is nothing to install and nothing to uninstall.

Where your library goes
-----------------------

Beside this folder, in a directory called "Papyrus" next to wherever you put this. Your library,
your read progress, your accounts and your settings all live there, and nowhere else.

This is deliberately different from the installed version. Uninstalling Papyrus from Windows leaves
your library alone, because deleting somebody's library by accident is not a recoverable mistake.
Deleting this folder does not touch it either, but you are the one deciding now rather than later.

Things to know
--------------

  * No installer means no Start menu entry and no desktop shortcut. Papyrus.cmd is the application.
  * The first start takes longer. It generates its own keys and its own database.
  * Nothing is sent anywhere. There is no telemetry, no analytics and no server. See the Questions
    page inside the application, which is linked from the sign-in screen.
  * It listens only on this machine. Nothing else on your network can reach it.

Verifying you got this file unaltered
-------------------------------------

Compare the SHA-256 in the file published next to this one. A zip cannot carry the same signature a
Windows installer can, so the hash is how you check it.

TEXT;

// --- build the zip ------------------------------------------------------------------------------

/*
 * Compressed, with the entry order sorted.
 *
 * Sorted so two builds of identical content produce identical archives. A zip whose bytes change
 * every build has a hash that changes every build, which makes "is this the file we published"
 * unanswerable and quietly destroys the only verification this artefact has.
 */
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($install, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

$entries = [];

foreach ($iterator as $entry) {
    if (! $entry->isFile()) {
        continue;
    }

    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($install) + 1));

    // The readme is injected rather than staged on disk, so the install stays byte-identical to what
    // the installer would package. A portable build must not be able to alter the install it reads.
    $entries[$relative] = $entry->getPathname();
}

$fromInstall = count($entries);

$entries['PAPYRUS-PORTABLE.txt'] = null;

ksort($entries);

$staged = count($entries);

say('files', $fromInstall.' from the install, plus the readme');

/*
 * Checked against `$fromInstall`, not against `$staged`.
 *
 * The total always includes the injected readme, so it is provably one or more and a comparison against
 * zero can never fire. That is a check that cannot fail, which is the shape this project's notes keep
 * warning about, and it was here because the intent was "an empty install must not become an archive".
 * The intent is preserved by counting the files that actually came from the install.
 */
if ($fromInstall === 0) {
    fail("the install at {$install} has no files in it, so the archive would hold only the readme");
}

if (! is_dir($out)) {
    mkdir($out, 0o777, true);
}

$name = "Papyrus-{$version}-portable.zip";
$target = $out.DIRECTORY_SEPARATOR.$name;

// One writer. Opening the file with fopen first and then handing the same path to ZipArchive is two
// owners of one file, and the second one truncates what the first one wrote.
if (! is_file($target) && ! @unlink($target)) {
    // Not present, which is the normal case. Anything else is handled by the open below.
}

$zip = new ZipArchive;

$opened = $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);

if ($opened !== true) {
    fail("could not open {$target} as a zip, ZipArchive said ".var_export($opened, true));
}

$written = 0;

foreach ($entries as $relative => $source) {
    /*
     * No third argument to addFile.
     *
     * The obvious thing to write is `ZipArchive::CM_DEFLATE`, which is what "compress it" looks like,
     * and it is wrong: that is the compression *method* constant, and addFile's third parameter is a
     * set of `ZipArchive::FL_*` flags. Passing a method where flags belong makes addFile return false
     * for every single file, including a one byte one, and the archive then closes empty and is deleted.
     *
     * Measured, having written it and been baffled by it:
     *
     *     addFile(path, 'empty.bin', ZipArchive::CM_DEFLATE)   => false
     *     addFile(path, 'small.bin', ZipArchive::CM_DEFLATE)   => false
     *     addFile(path, 'empty.bin')                           => true
     *
     * Compression is the default anyway. `FL_OVERWRITE` is only needed when an entry already exists,
     * which cannot happen in an archive created with OVERWRITE.
     */
    $added = $source === null
        ? $zip->addFromString($relative, $readme)
        : $zip->addFile($source, $relative);

    // Compared against `true` rather than truthiness, because a falsy non-true return is exactly the
    // shape of failure that turns into a smaller zip that still looks like a successful build.
    if ($added !== true) {
        $zip->close();
        fail("could not add {$relative} to the archive");
    }

    /*
     * Pin the injected entry's timestamp, which is the only reason this loop needs a second step.
     *
     * ddFromString stamps the entry with the time of the call, so a build of byte identical
     * content produced a different archive every time. Found by the test that asserts reproducibility,
     * which failed roughly one run in fifteen. I called that a flake before checking what it was, and
     * it was not one: two builds of the same tree differed in exactly one entry, the readme, whose
     * content matched byte for byte and whose embedded timestamp was two seconds apart.
     *
     * That mattered for real rather than cosmetically. A zip is the one artefact here that cannot carry
     * an Authenticode signature, so the published SHA-256 is the only thing a reader can check it
     * against, and a hash that changes on every build of identical content answers nothing at all.
     *
     * Files added with ddFile take their timestamp from the file on disk and are stable on their
     * own. Only the injected entry needed this.
     *
     * 1980-02-01 is the earliest instant the zip format can represent, so the value is both fixed and
     * valid rather than a magic number.
     */
    if ($source === null) {
        $zip->setMtimeName($relative, 315619200);
    }
    $written++;
}

if (! $zip->close()) {
    fail('could not finish writing the archive');
}

// Counted by what we added rather than by reading numFiles back after close(), which is not reliable
// once the handle is released and which was the second of the two ways this file could have reported
// success while shipping something short.
if ($written !== $staged) {
    fail("the archive took {$written} entries and {$staged} were staged");
}

// --- report -------------------------------------------------------------------------------------

$bytes = filesize($target);
$digest = hash_file('sha256', $target);

if ($digest === false) {
    fail("could not hash {$target}, so there is no digest to publish");
}

say('portable', $target);
say('size', round($bytes / 1048576, 1).' MB');
say('entries', (string) $written);

// The hash, in the two forms a reader can actually check.
$sidecar = $out.DIRECTORY_SEPARATOR.($name.'.sha256');

// sha256sum format, so `sha256sum -c` and the certutil of a reader on Windows both accept it.
if (file_put_contents($sidecar, $digest.'  '.$name.PHP_EOL) === false) {
    fail("could not write {$sidecar}");
}

say('sha256', $digest);
say('sidecar', basename($sidecar));

say('', 'a zip cannot carry an Authenticode signature, so this hash is how it is verified');

// --- helpers ------------------------------------------------------------------------------------

function say(string $label, string $value = ''): void
{
    fwrite(STDOUT, '  '.str_pad($label, 16).$value.PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, PHP_EOL.'  portable build failed: '.$message.PHP_EOL.PHP_EOL);

    exit(1);
}
