<?php

declare(strict_types=1);
use Papyrus\Runtime\SystemTools;

/**
 * Make a self-signed code-signing certificate, and be honest about what it is.
 *
 * ## What this produces, and what it does not do
 *
 * It produces a real Authenticode signature on the installer. That signature is cryptographically
 * sound: it proves the file has not been altered since it was signed, and Windows records the signer.
 *
 * **It does not remove the Windows warning on a reader's machine.** Measured both ways on this one,
 * with the count of trusted roots read back afterwards rather than assumed:
 *
 *     root absent from every store    ->  PowerShell: UnknownError
 *                                         signtool verify /pa: exit 1, "terminated in a root
 *                                         certificate which is not trusted by the trust provider"
 *     root imported to CurrentUser\Root ->  PowerShell: Valid
 *
 * That second row is the whole of what self-signing buys, and it is per machine. Somebody who installs
 * the certificate below sees a clean signature. Somebody who downloads the installer does not, and
 * cannot, because the chain terminates in a root the operating system was never told to trust.
 *
 * It is also worth saying what the intermediate confusion was. An earlier reading of this reported
 * `Valid` with nothing trusted, which would have flipped the conclusion entirely. The root was still in
 * the store; the deletion had silently failed and reported success. Removing it through the X509Store
 * API and re-reading the count is what settled it.
 *
 * So what does it buy?
 *
 *   - **Integrity.** A reader who has the root installed, or who compares the certificate's thumbprint
 *     against one published out of band, can tell this file apart from a tampered one.
 *   - **A stable identity across builds.** Every release signed by the same certificate names the same
 *     signer, so a reader can watch for a change. That is worth something even untrusted, because the
 *     *absence* of a change is information.
 *   - **The upgrade path.** When a real certificate is bought later, the machinery is already built and
 *     tested against a real signature rather than a mock, and only the environment variables change.
 *
 * What it does not buy is the disappearance of "Windows protected your PC", and anyone reading the
 * documentation of this project should not be led to believe otherwise. `README.md` says so in the same
 * terms.
 *
 * ## Why the certificate is made with an explicit code signing EKU
 *
 * Because openssl's default self-signed certificate carries **no Extended Key Usage extension at all**,
 * and Windows will not accept that for signing code. Measured on this machine:
 *
 *     openssl req -x509, no config      ->  X509v3 Basic Constraints only, no EKU
 *     openssl req -x509, with v3_ext    ->  X509v3 Extended Key Usage: critical, Code Signing
 *
 * The first version of this file used the bare `req -x509` call, signed with signtool successfully, and
 * produced a signature Windows treated as `UnknownError` for a reason that had nothing to do with trust.
 * The config file below is the fix, and it is here rather than an `-addext` flag because the Windows Git
 * build of openssl accepts a config file reliably and the quoting of an inline extension string does not.
 *
 * ## Where the material lives
 *
 * `.signing/`, which is gitignored and which the machine-detail gate skips. Not under `build/dist/`,
 * because a clean of the output directory would take the certificate with it, and the next build would
 * silently sign under a new identity.
 *
 * The private key never leaves this machine unless somebody copies the file, and nothing in this
 * repository prints it.
 *
 * ## Usage
 *
 *     php packaging/signing/make-certificate.php [--years=10]
 *     php packaging/signing/make-certificate.php --export-instructions
 *
 * The PFX password comes from `PAPYRUS_CERT_PASSWORD`, never from the command line. A literal on a
 * command line is visible in the process list, and the PFX is the release signing identity.
 */
$repo = dirname(__DIR__, 2);

require_once __DIR__.'/../runtime/SystemTools.php';

const CERTIFICATE_SUBJECT = 'CN=Papyrus, O=Papyrus, OU=self-signed';

/** The openssl config, which is what puts the code signing EKU on the certificate. */
const OPENSSL_CONFIG = <<<'CONF'
[req]
distinguished_name = dn
x509_extensions = v3
prompt = no

[dn]
CN = Papyrus
O = Papyrus
OU = self-signed

[v3]
# A certificate that signs code must say it signs code, or Windows will not accept the signature.
# This is the extension whose absence is measured and commented on above.
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = critical,codeSigning
CONF;

// --- arguments ---------------------------------------------------------------------------------

/*
 * `--out=` exists so this can be tested without touching the real signing material.
 *
 * The default is `.signing/` in the repository, which is where it belongs, and which is gitignored.
 * A test that ran against the default would either fail because a certificate already existed or, worse,
 * replace the identity every release is signed under.
 */
$options = ['years' => '10', 'out' => null];

// PHP 8.5's stubs allow $argv to be absent when register_argc_argv is off.
$argv ??= [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--export-instructions') {
        exportInstructions();

        exit(0);
    }

    if (str_starts_with($argument, '--years=')) {
        $options['years'] = substr($argument, strlen('--years='));
    } elseif (str_starts_with($argument, '--password=')) {
        /*
         * Refused rather than accepted and ignored.
         *
         * The password is the release signing identity. A literal here is visible to any
         * same-user process, process auditing or EDR for as long as the command runs, and the
         * rest of this file already refuses to put one on a command line for a different tool.
         * Accepting it silently would leave the caller believing the safe path was used.
         */
        say('failed', '--password is not accepted because a literal on a command line is visible '
            .'in the process list. Set PAPYRUS_CERT_PASSWORD instead.');

        exit(1);
    } elseif (str_starts_with($argument, '--out=')) {
        $options['out'] = substr($argument, strlen('--out='));
    } else {
        say('failed', 'unexpected argument: '.$argument);

        exit(1);
    }
}
/*
 * Where the key material lives. Gitignored, and skipped by the machine-detail gate.
 *
 * Absolute, or refused, for the same reason `build-install.php` refuses a relative `--into`: a relative
 * home writes a key somewhere the caller did not mean, and this is the one file in the build that must
 * not end up somewhere surprising.
/*
 * Resolve where the key material goes, and refuse the answers that put a private key somewhere it
 * must not be.
 *
 * Three escapes were found by using this rather than by reading it, and every one of them wrote a
 * real key:
 *
 *   --out=      resolved to the repository root
 *   --out=.     resolved to the repository root, one spelling later
 *   --out=..    resolved to this developer's own folder, one level above the source
 *
 * The order below is the fix and the order is the point. The directory is created *before* it is
 * resolved, because realpath returns false for a path that does not exist and a scratch directory
 * that does not exist yet is a legitimate thing to hand this script. Comparing resolved paths then
 * catches `.` and `..` in the one comparison, which trimming a string does not: `repo\.` and `repo`
 * are different strings and the same directory.
 */
$default = $repo.DIRECTORY_SEPARATOR.'.signing';

$requested = $options['out'] === null || trim((string) $options['out']) === ''
    ? $default
    : (string) $options['out'];

$home = preg_match('#^[A-Za-z]:[\\\\/]#', $requested) || str_starts_with($requested, '\\\\')
    ? $requested
    : $repo.DIRECTORY_SEPARATOR.ltrim(str_replace('/', DIRECTORY_SEPARATOR, $requested), DIRECTORY_SEPARATOR);

/*
 * A cheap lexical refusal first, before anything is created.
 *
 * This is the layer that is obvious to read and cheap to run, and it names the three cases in the
 * terms a person would use. The resolved comparison below is what actually catches them; this one
 * means a dangerous value never gets as far as creating a directory.
 */
$spelling = rtrim(strtolower(str_replace('/', '\\', $home)), '\\');

if (in_array($spelling, [
    rtrim(strtolower(str_replace('/', '\\', $repo)), '\\'),
    rtrim(strtolower(str_replace('/', '\\', $repo)), '\\').'\\.',
], true)) {
    say('failed', 'the signing home cannot be the repository root');

    exit(1);
}

if (preg_match('#(^|\\\\)\.\.($|\\\\)#', $spelling) === 1) {
    say('failed', 'the signing home cannot be above the repository root');
    say('', 'this is where a private key must never be written, and .. reaches it');

    exit(1);
}

if (! is_dir($home) && ! @mkdir($home, 0o700, true) && ! is_dir($home)) {
    say('failed', 'could not create the signing home: '.$home);

    exit(1);
}

/*
 * Then the real comparison, which is the one that holds.
 *
 * realpath on both sides catches a junction and collapses a trailing separator, neither of which the
 * string check above can see. A path inside the repository but below its root is fine, because that
 * is what `.signing` is.
 */
$resolvedRoot = realpath($repo);
$resolvedHome = realpath($home);

if ($resolvedRoot === false || $resolvedHome === false) {
    say('failed', 'the signing home could not be resolved: '.$home);

    exit(1);
}

$comparable = static fn (string $path): string => rtrim(strtolower(str_replace('/', '\\', $path)), '\\');

if ($comparable($resolvedHome) === $comparable($resolvedRoot)) {
    say('failed', 'the signing home cannot be the repository root');

    exit(1);
}

/*
 * And nothing above the repository either.
 *
 * Above it as well as the root itself, because `--out=..` is the value a shell produces for the parent,
 * and the parent of this repository is somebody's own folder. A private key written there is worse than
 * a filename: it is in the one place on the machine a backup or a sync service will pick up.
 *
 * `home` is above `root` when `root` begins with `home` followed by a separator. The order matters and
 * this line had it backwards: the separator goes on the NEEDLE, and the test asks whether the root
 * starts with the home.
 *
 * The inverted version read `str_starts_with($root.'\\', $home.'\\')`, which:
 *
 *   - is FALSE for every genuine escape, because `C:\Users\<you>\...` does not start with `d:\data\`, so
 *     the one case the guard exists for was the one case it never caught, and
 *   - is TRUE when home equals root, because appending a separator to both sides makes them identical,
 *     so it fired only where the equality check two lines above had already handled the case.
 *
 * It read as working because it produced output, and it produced output for the wrong reason. That is
 * the failure AGENTS.md names: a check that reports a specific verdict about something it did not
 * really test is worse than no check, because it looks like evidence.
 */
if (str_starts_with($comparable($resolvedRoot), $comparable($resolvedHome).'\\')) {
    say('failed', 'the signing home cannot be above the repository root');
    say('', 'a private key must never be written outside the project, and .. reaches there');

    exit(1);
}

say('home', $home);

$years = (int) $options['years'];

if ($years < 1 || $years > 30) {
    say('failed', 'years must be between 1 and 30');

    exit(1);
}

/*
 * The password.
 *
 * Read from the environment, and never printed and never put on a command line. A password typed
 * on a command line ends up in shell history, in a transcript, in the process list, and in
 * anything that records the command. The export below passes a temporary file to openssl instead.
 *
 * A blank password is allowed and is the default, because the file is only as exposed as the folder
 * holding it and a blank password makes the release build a single command with nothing secret to
 * arrange. A real certificate from a CA will have a password and it should come from the environment.
 */
$password = (string) (getenv('PAPYRUS_CERT_PASSWORD') ?: '');

// --- what already exists -----------------------------------------------------------------------

if (! is_dir($home)) {
    mkdir($home, 0o700, true);
}

$pfx = $home.DIRECTORY_SEPARATOR.'papyrus.pfx';
$cert = $home.DIRECTORY_SEPARATOR.'papyrus.crt';
$key = $home.DIRECTORY_SEPARATOR.'papyrus.key';

if (is_file($pfx)) {
    say('certificate', 'already exists at '.$pfx);
    say('', 'delete it to make a new one, and note that a new identity is a new signer');
    say('', 'to a reader who trusted the old one, with no warning to explain the difference');

    exit(0);
}

$openssl = findOpenssl();

if ($openssl === null) {
    say('failed', 'openssl was not found, and it is needed to make the certificate');

    exit(1);
}

say('openssl', $openssl);
say('subject', CERTIFICATE_SUBJECT);
say('years', (string) $years);

// --- make it ------------------------------------------------------------------------------------

$config = $home.DIRECTORY_SEPARATOR.'openssl.cnf';

file_put_contents($config, OPENSSL_CONFIG."\n");

$days = $years * 365;

$output = [];
$status = 1;

exec(sprintf(
    '%s req -x509 -newkey rsa:4096 -keyout %s -out %s -days %d -nodes -config %s 2>&1',
    escapeshellarg($openssl),
    escapeshellarg($key),
    escapeshellarg($cert),
    $days,
    escapeshellarg($config),
), $output, $status);

if ($status !== 0 || ! is_file($cert)) {
    say('failed', 'openssl did not produce a certificate: '.implode(' ', $output));

    exit(1);
}

$output = [];
$status = 1;

/*
 * The password goes into a file, not onto the command line.
 *
 * Openssl reads it with `-passout file:`, so the only thing the process list carries is the path
 * to a short-lived file holding the secret. The file is restricted to the current user where the
 * platform honours that, and removed in `finally` so a failed export does not leave the password
 * behind for the next command to read.
 *
 * A blank password is the documented default and is passed as an empty `pass:` argument. It is not
 * a secret, and an empty file is not a password openssl can read: measured, it fails with "Error
 * reading password from BIO", which is why the two cases are kept apart.
 */
$passwordFile = null;

if ($password !== '') {
    $passwordFile = tempnam(sys_get_temp_dir(), 'papyrus-pfx-');

    if ($passwordFile === false || file_put_contents($passwordFile, $password) === false) {
        if (is_string($passwordFile)) {
            @unlink($passwordFile);
        }

        say('failed', 'could not write the password file openssl needs');

        exit(1);
    }

    @chmod($passwordFile, 0o600);
}

try {
    exec(sprintf(
        '%s pkcs12 -export -out %s -inkey %s -in %s -passout %s 2>&1',
        escapeshellarg($openssl),
        escapeshellarg($pfx),
        escapeshellarg($key),
        escapeshellarg($cert),
        escapeshellarg($passwordFile === null ? 'pass:' : 'file:'.$passwordFile),
    ), $output, $status);
} finally {
    if ($passwordFile !== null) {
        @unlink($passwordFile);
    }
}

if ($status !== 0 || ! is_file($pfx)) {
    say('failed', 'could not export the pfx: '.implode(' ', $output));

    exit(1);
}

/*
 * The private key is not needed once the pfx holds it, and leaving a loose PEM key beside it is one
 * more thing that can be copied by accident. The certificate is kept because it is what somebody would
 * import to trust the signer, and it names nobody.
 */
if (is_file($key)) {
    unlink($key);
}

// --- prove it, rather than assert it ---------------------------------------------------------------

/*
 * Read the EKU back off the finished certificate.
 *
 * This is the check that would have caught the bug this file's comments describe. Asserting that a
 * certificate was created is not the same as asserting it can sign code, and the difference between
 * those two is the whole subject of the first section above.
 */
$output = [];
$status = 1;

exec(sprintf('%s x509 -in %s -noout -text 2>&1', escapeshellarg($openssl), escapeshellarg($cert)), $output, $status);

$text = implode("\n", $output);

if (! str_contains($text, 'Code Signing')) {
    say('failed', 'the certificate does not carry the code signing EKU, so Windows would refuse it');
    say('', 'this should be impossible with the config above, and it is checked rather than assumed');

    exit(1);
}

say('eku', 'code signing, read back off the certificate');

$thumbprint = thumbprintFrom($openssl, $cert);

if ($thumbprint !== null) {
    say('thumbprint', $thumbprint);
}

/*
 * Install it into the certificate store.
 *
 * Without this the script reports success and produces a certificate nothing can use: every documented
 * signing route that takes a thumbprint reads `Cert:\CurrentUser\My`, and a pfx sitting on disk is not
 * in there. Found by trying it, and the failure names a path that genuinely does not exist, which reads
 * like a quoting problem and is not one.
 *
 * `-Password` is omitted rather than given an empty value, because `ConvertTo-SecureString ''` throws
 * and the resulting message says nothing about certificates. A blank password is this script's default
 * and omitting the switch is the only spelling of "no password" PowerShell accepts.
 */
/*
 * Install it into the store, but only when this is the real signing home.
 *
 * `--out=` exists so the test can exercise this without touching the real material, and a test that
 * ran the whole script against a scratch directory still installed a certificate into
 * `Cert:\CurrentUser\My` every time. Three test runs left three Papyrus identities in the store,
 * each a signing-capable certificate that nothing would ever clean up.
 *
 * A scratch home is a file on disk and nothing more, and the rule that follows from that is the
 * useful part: **anything that mutates the machine is tied to the one home a human chose.** A test
 * cannot be trusted with that even when the test is careful.
 */
$real = $comparable($home) === $comparable($default);

if (! $real) {
    say('store', 'not installed, because this is not the real signing home');
    say('', 'the test suite makes certificates here, and a test must not leave an identity behind');
} elseif (installIntoStore($pfx, $password)) {
    say('store', 'installed in Cert:\\CurrentUser\\My');
} else {
    say('store', 'NOT installed');
    say('', 'signing by thumbprint will not work until it is imported');
    say('', '  Import-PfxCertificate -FilePath <the pfx> -CertStoreLocation Cert:\\CurrentUser\\My');
}

say('pfx', $pfx);
say('', 'Windows will still show a warning for this signature. See the header of this file for why.');

// --- helpers ------------------------------------------------------------------------------------

/**
 * @return array{0: string, 1: string}
 */
function exportInstructions(): array
{
    say('certificate', 'already made and sitting in .signing/');
    say('');
    say('  Sign the installer:');
    say('    $env:PAPYRUS_SIGN = "1"');
    say('    $env:PAPYRUS_CERT_PFX = "<the .pfx path>"');
    say('    php packaging/installer/build-installer.php');
    say('');
    say('  Let a machine trust it, which is what removes the warning locally:');
    say('    Import-Certificate -FilePath .signing\papyrus.crt -CertStoreLocation Cert:\CurrentUser\Root');
    say('');
    say('  And tell readers the thumbprint, so they can check it themselves:');
    say('    .signing\papyrus.crt is what you import, and its SHA-1 is the thumbprint above');

    return ['', ''];
}

function findOpenssl(): ?string
{
    foreach ([
        'C:\\Program Files\\Git\\usr\\bin\\openssl.exe',
        'C:\\Program Files\\OpenSSL-Win64\\bin\\openssl.exe',
    ] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    $found = [];
    $status = 1;

    exec('where openssl 2>&1', $found, $status);

    return $status === 0 && $found !== [] ? trim($found[0]) : null;
}

function thumbprintFrom(string $openssl, string $cert): ?string
{
    $output = [];
    $status = 1;

    exec(sprintf('%s x509 -in %s -noout -fingerprint -sha1 2>&1', escapeshellarg($openssl), escapeshellarg($cert)), $output, $status);

    if ($status !== 0) {
        return null;
    }

    if (preg_match('/SHA1 Fingerprint=([0-9A-F:]+)/i', implode("\n", $output), $matches) !== 1) {
        return null;
    }

    // Without colons, because that is the form a certificate store shows and the form a reader would
    // compare against.
    return str_replace(':', '', strtoupper($matches[1]));
}

/**
 * Put the certificate into `Cert:\CurrentUser\My`, so a thumbprint can be used to sign with it.
 *
 * CurrentUser rather than LocalMachine on purpose: it needs no administrator, and it is the store a
 * single developer's own machine should be changed by. A release machine using an issued certificate
 * installs that certificate the way its owner instructs, not from here.
 *
 * Returns false rather than throwing, because a machine that refuses the import can still sign from the
 * pfx with `PAPYRUS_CERT_PFX`, and refusing to produce a certificate over it would be worse.
 */
function installIntoStore(string $pfx, string $password): bool
{
    /*
     * Built as an array and joined, rather than interpolated into a sprintf template.
     *
     * This repository lives at a path containing a space, and PowerShell's own argument parsing splits
     * that unless the value carries its own quotes inside the command string. AGENTS.md records the
     * same trap for a different tool; it is a property of the shell, not of one script.
     */
    $file = "'".str_replace("'", "''", $pfx)."'";

    $command = 'Import-PfxCertificate -FilePath '.$file.' -CertStoreLocation Cert:\CurrentUser\My';

    if ($password !== '') {
        // A real password needs passing securely, and a literal on a command line is visible in the
        // process list. Refuse rather than leak it, and say which switch to use instead.
        fwrite(STDERR, '  note: a non-empty password is not imported automatically.'.PHP_EOL);
        fwrite(STDERR, '  import it yourself so the password never reaches a command line:'.PHP_EOL);
        fwrite(STDERR, '    Import-PfxCertificate -FilePath <the pfx> -CertStoreLocation Cert:\CurrentUser\My'.PHP_EOL);

        return false;
    }

    $output = [];
    $status = 1;

    exec(sprintf(
        '"%s" -NoProfile -Command "%s" 2>&1',
        SystemTools::powershell(),
        $command,
    ), $output, $status);

    return $status === 0;
}
function say(string $label, string $value = ''): void
{
    fwrite(STDOUT, '  '.str_pad($label, 14).$value.PHP_EOL);
}
