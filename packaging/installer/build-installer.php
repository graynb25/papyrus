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
 *   php packaging/installer/build-installer.php [--install=<dir>] [--out=<dir>] [--unsigned] [--no-record]
 *
 * `--no-record` is for a candidate build: it writes the manifest beside the installer but does not
 * replace the committed `releases/<version>.json`. Release builds never pass it.
 *
 * ## Signing
 *
 * Sign the finished `.exe` with Authenticode by setting `PAPYRUS_SIGN=1` and giving it a certificate,
 * either `PAPYRUS_CERT_THUMBPRINT` for one already in the Windows store or `PAPYRUS_CERT_PFX` for a
 * file. `--unsigned` is the only way to build without signing, and it says so out loud, because an
 * unsigned installer is indistinguishable from a signed one on disk.
 *
 * See `packaging/signing/Signing.php` for the configuration and for why skipping has to be a decision.
 */

declare(strict_types=1);

$repo = dirname(__DIR__, 2);

require_once __DIR__.'/ReleaseRecord.php';
require_once __DIR__.'/../runtime/SystemTools.php';

/*
 * Paths and the flag are kept apart, deliberately.
 *
 * The first version put `--unsigned` into the same `$options` bag as the two paths. PHPStan correctly
 * reported that `$out` was then `string|true`, because the bag held a boolean, and the inference
 * travelled four hundred lines to a `mkdir` and a `countFiles`.
 *
 * A flag is not a path, so it gets its own variable. That is cleaner code as well as correct typing,
 * and the alternative was a cast, which is the thing the analysis guidance exists to prevent.
 */
$unsigned = false;
$record = true;
$options = [];

// PHP 8.5's stubs allow $argv to be absent when register_argc_argv is off.
$argv ??= [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--install=')) {
        $options['install'] = substr($argument, strlen('--install='));
    } elseif (str_starts_with($argument, '--out=')) {
        $options['out'] = substr($argument, strlen('--out='));
    } elseif ($argument === '--unsigned') {
        $unsigned = true;
    } elseif ($argument === '--no-record') {
        $record = false;
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
$compilerVersion = compilerVersion($iscc);

if ($compilerVersion === null) {
    fail(
        'could not read the Inno Setup version from the registry. The manifest records the '
        .'compiler so a build can be reproduced; install Inno Setup 6 and try again.'
    );
}

if (version_compare($compilerVersion, '6.0', '<')) {
    fail('Inno Setup 6 or newer is required, and '.$compilerVersion.' is installed');
}

say('compiler', 'Inno Setup '.$compilerVersion.' at '.$iscc);

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
    /*
     * `/O` overrides the script's `OutputDir`.
     *
     * The script names `build\dist` because that is where a release lands, but `--out` is a
     * documented option and the check below reads the artifact from `$out`. Without this
     * argument Inno compiled successfully into `build\dist` and the script then failed because
     * `$out` was empty, which is a false failure that also overwrote the release directory.
     */
    '/O'.$out,
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

// --- signing ----------------------------------------------------------------------------------

/*
 * Signing happens after the compiler and before the manifest, so the manifest can record what was
 * actually done rather than what was intended.
 */
require_once __DIR__.'/../signing/Signing.php';

use Papyrus\Runtime\SystemTools;
use Papyrus\Signing\Signing;

$skipSigning = $unsigned;

if ($skipSigning && Signing::isRequested()) {
    fail(
        "--unsigned was passed and PAPYRUS_SIGN is set. Those disagree.\n\n"
        ."  Signing was requested by the environment and waived on the command line, which is\n"
        .'  exactly the state where the wrong one is believed to have happened. Pass one.'
    );
}

if ($skipSigning) {
    say('signature', 'NOT SIGNED, because --unsigned was passed');
    say('', 'an unsigned installer looks identical to a signed one on disk');
} elseif (Signing::isRequested()) {
    try {
        // A local build may lose the timestamp server without that being fatal. A release may not:
        // an untimestamped signature stops being valid the day the certificate expires, which is
        // four years away and entirely somebody else's problem by then.
        Signing::sign([$expected], ! Signing::isRelease());

        say('signature', Signing::describe($expected));
    } catch (RuntimeException $e) {
        fail("signing failed.\n\n  ".str_replace("\n", "\n  ", $e->getMessage()));
    }

    /*
     * Copy the signing certificate beside the installer.
     *
     * Because README tells a reader that a self-signed signature can be trusted locally by importing
     * `papyrus.crt`, and a file that is not published cannot be imported. That sentence was written
     * before this existed, which is a habit worth naming: a README claim about a build artefact is
     * still a claim, and writing it is not the same as making it true.
     *
     * The .crt is the public half of the key pair by definition, so copying it is not a leak. The
     * private key never comes near this line, and the export reads the certificate store rather than
     * the pfx precisely because the pfx holds both halves.
     *
     * A certificate from a real authority is not published this way, because its root is already in
     * every Windows install and publishing it would be noise. Only a self-signed one needs it, which is
     * exactly the case a reader cannot otherwise resolve on their own.
     */
    $published = publishCertificate($out);

    if ($published !== null) {
        say('certificate', $published);
        say('', 'published beside the installer, so a reader can compare it or import it');
    }
} else {
    /*
     * The default, and the reason `--unsigned` exists at all.
     *
     * Refusing here rather than proceeding quietly means the only way to get an unsigned artefact is
     * to ask for one. The alternative is a build that produces unsigned installers whenever nobody
     * happens to have a certificate configured, and calls that normal.
     */
    fail(
        "no signing configuration, so refusing to produce an unsigned installer silently.\n\n"
        ."  To sign, set PAPYRUS_SIGN=1 and either\n"
        ."    PAPYRUS_CERT_THUMBPRINT  a code-signing certificate already in the Windows store, or\n"
        ."    PAPYRUS_CERT_PFX         path to a .pfx holding one\n\n"
        .'  To build without signing on purpose, pass --unsigned.'
    );
}

// --- the manifest ------------------------------------------------------------------------------

/*
 * A machine readable account of what this build produced.
 *
 * ## Why it is written twice
 *
 * Once into the output directory beside the artifact, so it travels with the download, and once into
 * `releases/<version>.json`, which is committed. The committed copy is the one that matters for audit:
 * a reader who distrusts our download page can clone the repository, read the manifest for the version
 * they have, and check the hash themselves without trusting anything we serve them.
 *
 * That is why it is committed rather than generated on demand. A hash published only beside the file
 * it describes proves the file arrived intact and says nothing about whether it is the file we meant
 * to publish. A hash in the repository, in history, at a version anybody can check, is the one that
 * answers "is this really Papyrus 1.2.0".
 *
 * ## Why the digest is computed here rather than by whatever published the file
 *
 * Because the moment the number stops being computed from the artifact is the moment it stops being
 * evidence. A manifest assembled from state captured before the compile would carry the hash of the
 * wrong file and still look perfect.
 *
 * ## Why an artifact can name its own commit
 *
 * Because a portable rebuilt after a first-launch fix can be newer than the already-proven
 * installer. The top-level commit is the installer's, and an artifact that came from a different
 * tree carries the commit it actually came from. A reader checking the portable hash can then see
 * which tree produced it without assuming it matches the installer.
 *
 * ## Why `requires` is in it at all
 *
 * Because the Suwayomi floor is a real contract that has been a sentence in a document until now. The
 * same two numbers in `packaging/suwayomi.json` are read here, so the manifest, the launcher's health
 * check and the About page cannot disagree about what Papyrus needs, and whoever writes the updater
 * has it in a form they can parse rather than a sentence they have to read.
 */
$compatibilityFile = $install.DIRECTORY_SEPARATOR.'suwayomi.json';

foreach ([['version.txt', $install.DIRECTORY_SEPARATOR.'version.txt'], ['suwayomi.json', $compatibilityFile]] as [$label, $required]) {
    if (! is_file($required)) {
        fail('cannot write a manifest: '.$required.' is not there, and '.$label.' is required for it');
    }
}

$stamped = [];
foreach (file($install.DIRECTORY_SEPARATOR.'version.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (preg_match('/^([a-z]+)\s+(\S+)$/i', $line, $matches) === 1) {
        $stamped[strtolower($matches[1])] = $matches[2];
    }
}

$compatibility = json_decode((string) file_get_contents($compatibilityFile), true);

if (! is_string($stamped['version'] ?? null) || ! is_array($compatibility) || ! is_string($compatibility['minimum'] ?? null)) {
    fail('cannot write a manifest: version.txt or suwayomi.json is not readable, and the manifest needs both');
}

/*
 * The JVM floor, read from the JRE that was actually staged.
 *
 * Read rather than written down, because a hardcoded 21 would be a claim about a JRE rather than a
 * measurement of it, and would go stale silently the day the pinned JRE moved. If the release file is
 * missing the build still succeeds and the field says so, because an unknown floor is a smaller problem
 * than a build refused over a file nobody reads.
 */
$jreRelease = $install.DIRECTORY_SEPARATOR.'suwayomi'.DIRECTORY_SEPARATOR.'runtime'.DIRECTORY_SEPARATOR.'release';
$jvm = null;

if (is_file($jreRelease) && preg_match('/JAVA_VERSION="([^"]+)"/', (string) file_get_contents($jreRelease), $matches) === 1) {
    $jvm = '>='.$matches[1];
}

$manifest = [
    'version' => $stamped['version'],
    /*
     * The full instant, offset included, rather than the first ten characters of it.
     *
     * `version.txt` writes `built 2026-10-15 00:01:32+00:00` and the `+00:00` is in there on
     * purpose, because an instant with no zone is not an instant. Truncating to a bare date throws
     * away the only part that says which date it is, and the reader of this manifest does not
     * share the builder's clock. Measured on the machine that built 1.2.0: local time was the 14th
     * at 19:09 in SA Pacific Standard Time, PHP's default timezone is UTC, and the manifest
     * recorded the 15th, which is a day that had not arrived for the person who built it.
     *
     * `date('c')` is the fallback and carries the offset as well, so this is an instant on both
     * paths and the two cannot be told apart afterwards. A consumer that wants a plain date can
     * take the first ten characters itself, which is a decision it gets to make rather than one
     * this file makes for it.
     */
    'released' => (string) ($stamped['built'] ?? date('c')),
    'commit' => $stamped['commit'] ?? null,
    'requires' => [
        'suwayomi' => '>='.ltrim((string) $compatibility['minimum'], 'vV'),
        'jvm' => $jvm,
    ],
    /*
     * The signing state, recorded rather than left in the build log.
     *
     * A log is pasted into an issue and then lost. The manifest is the artefact that gets published
     * beside the installer, so it is the only place a reader, or a mirror, or a later audit can learn
     * whether this build was signed. `signed` is a boolean rather than a certificate name so that it
     * cannot be mistaken for an endorsement by whoever holds the certificate.
     */
    'signed' => ! $skipSigning,
    'signature' => $skipSigning ? null : Signing::describe($expected),
    /*
     * The compiler that produced the payload, not its path.
     *
     * The path is per-machine and can carry a user folder, and this manifest is published. The
     * version is the part that makes a build reproducible; `findCompiler()` accepts any ISCC.exe,
     * so without this field two builds of one commit could come from different Inno Setup releases
     * and nothing would say so.
     */
    'toolchain' => [
        'inno_setup' => $compilerVersion,
    ],
    'artifacts' => [[
        'file' => basename($expected),
        'sha256' => hash_file('sha256', $expected),
        'size' => filesize($expected),
        'commit' => $stamped['commit'] ?? null,
    ]],
];

$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

$beside = $out.DIRECTORY_SEPARATOR.'manifest.json';

if (file_put_contents($beside, $encoded) === false) {
    fail('could not write '.$beside);
}

say('manifest', $beside);
say('', 'sha256 '.$manifest['artifacts'][0]['sha256']);

if ($record) {
    $committed = ReleaseRecord::merge($repo, $manifest);
    say('', 'also written to '.$committed.', which is committed');
} else {
    say('', 'release record not written, because --no-record was passed');
}

say('', 'requires suwayomi '.$manifest['requires']['suwayomi'].', jvm '.($jvm ?? 'unknown'));

// --- and the one property that makes it an installer rather than an archive -----------------

/*
 * This counts the install tree the compiler was given. It proves the source tree is whole; it
 * does not prove the compiled payload contains every file. Nothing here reads the setup executable
 * back.
 *
 * The real payload check is the manual sandbox run, which installs through the setup executable
 * and compares the installed tree with this one. The earlier version of this comment claimed that
 * check existed here, and the count was the only thing behind the claim.
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
 * Read the Inno Setup version for the compiler this build will actually run.
 *
 * `ISCC.exe /?` names the major version only, and its file version resource is 0.0.0.0 on the
 * installed build, so neither can distinguish 6.7.3 from 6.4. The uninstall entry carries the
 * version the installer wrote, but it must be matched to the compiler path because `ISCC` can point
 * at a different installation. The path is compared and never published; only the version is.
 *
 * Returns null rather than guessing, so the caller can refuse a build whose compiler it cannot
 * name.
 */
function compilerVersion(string $compiler): ?string
{
    $directory = rtrim(str_replace('/', '\\', dirname($compiler)), '\\');

    $keys = [
        'HKCU\Software\Microsoft\Windows\CurrentVersion\Uninstall\Inno Setup 6_is1',
        'HKLM\Software\Microsoft\Windows\CurrentVersion\Uninstall\Inno Setup 6_is1',
        'HKLM\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\Inno Setup 6_is1',
    ];

    foreach ($keys as $key) {
        $location = @shell_exec('reg query '.escapeshellarg($key).' /v InstallLocation 2>&1');

        if (! is_string($location) || preg_match('/InstallLocation\s+REG_SZ\s+(.+)/i', $location, $found) !== 1) {
            continue;
        }

        $installed = rtrim(str_replace('/', '\\', trim($found[1])), '\\');

        if (strcasecmp($installed, $directory) !== 0) {
            continue;
        }

        $version = @shell_exec('reg query '.escapeshellarg($key).' /v DisplayVersion 2>&1');

        if (is_string($version) && preg_match('/DisplayVersion\s+REG_SZ\s+([0-9]+(?:\.[0-9]+)+)/i', $version, $matches) === 1) {
            return $matches[1];
        }
    }

    return null;
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

/**
 * Copy the public certificate beside the artefact and return its SHA-1 thumbprint.
 *
 * Read from the certificate store rather than from the pfx, because the pfx is the private key and
 * this is the one place in the build where a file is deliberately copied next to the installer.
 *
 * Returns null when there is nothing to publish, which is the normal case for a certificate from a
 * real authority: its root is already trusted everywhere, so a copy beside the download is noise.
 *
 * A failure here is reported and not fatal. A release built from a pfx rather than the store has no
 * certificate to export, and refusing to produce an installer over that would be worse than saying so.
 */
function publishCertificate(string $out): ?string
{
    $thumbprint = getenv('PAPYRUS_CERT_THUMBPRINT');

    if ($thumbprint === false || trim($thumbprint) === '') {
        return null;
    }

    $target = $out.DIRECTORY_SEPARATOR.'papyrus.crt';

    $command = sprintf(
        '"%s" -NoProfile -Command "Export-Certificate -Cert %s -FilePath %s -Type CERT" 2>&1',
        SystemTools::powershell(),
        escapeshellarg('Cert:\\CurrentUser\\My\\'.trim($thumbprint)),
        escapeshellarg($target),
    );

    $output = [];
    $status = 1;

    exec($command, $output, $status);

    if ($status !== 0 || ! is_file($target)) {
        fwrite(STDERR, '  note: could not export the certificate: '.trim(implode(' ', $output)).PHP_EOL);

        return null;
    }

    $digests = [];

    exec(sprintf('certutil -hashfile %s SHA1 2>&1', escapeshellarg($target)), $digests);

    foreach ($digests as $line) {
        if (preg_match('/^[0-9a-f]{40}$/i', trim($line), $matches) === 1) {
            return strtolower($matches[0]);
        }
    }

    // The file was written, which is what a reader needs. Not knowing the digest is a lesser problem
    // than not having the certificate, so it is reported rather than turned into a failed build.
    fwrite(STDERR, '  note: wrote papyrus.crt but could not read its thumbprint back'.PHP_EOL);

    return 'written, thumbprint not read back';
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
