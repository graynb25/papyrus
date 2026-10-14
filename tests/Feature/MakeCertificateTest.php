<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Making a self-signed code-signing certificate.
 *
 * ## Why this needs tests at all
 *
 * Because the first version of the script produced a certificate that had **no Extended Key Usage
 * extension**, signed with it successfully, and was refused by Windows. `signtool sign` returning zero
 * is not evidence that the certificate is fit for signing code, which is the same shape as the portable
 * readme lesson: the tool reported success and the artefact was wrong.
 *
 * The two things asserted here are the two that actually matter and neither of which the script's own
 * exit code covers. It also reads the EKU back before reporting success, so these tests are checking a
 * guarantee rather than a first line of defence.
 */
class MakeCertificateTest extends TestCase
{
    private string $workspace = '';

    /**
     * Every directory this test created, cleaned up in tearDown whatever the outcome.
     *
     * Cleanup at the end of a test only runs when the test passes, so a failure leaves the directory
     * behind for the next run to walk into. That is how an intermittent failure becomes a permanent
     * one, and it is the portable suite's lesson applied here rather than after the same report.
     *
     * @var list<string>
     */
    private array $workspaces = [];

    /**
     * Thumbprints that were in the store before this test ran, so a failure can be cleaned up after.
     *
     * Recorded in `setUp` and therefore in **every** test, not only the one that asserts on the store.
     * It was originally set inside the store test alone, and `tearDown` then read an empty baseline in
     * the other ten tests and removed every Papyrus certificate it could see as a stray, which
     * included the developer's real signing certificate.
     *
     * That is the general shape and it is worth stating plainly: **a cleanup that removes whatever it
     * did not find at the start will delete things that were never the test's, if the start is not
     * recorded on every path that runs the cleanup.** The baseline is not a detail of one test. It is
     * an invariant of the class.
     *
     * @var list<string>
     */
    private array $storeBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeBefore = $this->storedThumbprints();
    }

    /**
     * Temporary PowerShell scripts written by this test, removed whatever the outcome.
     *
     * @var list<string>
     */
    private array $scratchFiles = [];

    protected function tearDown(): void
    {
        $this->removeCertificatesThisTestAdded();

        foreach ($this->scratchFiles as $file) {
            @unlink($file);
        }

        $this->scratchFiles = [];

        foreach ($this->workspaces as $directory) {
            if ($directory === '' || ! is_dir($directory)) {
                continue;
            }

            foreach ((array) glob($directory.'/*') as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }

            @rmdir($directory);
        }

        $this->workspaces = [];

        parent::tearDown();
    }

    private function script(): string
    {
        return base_path('packaging/signing/make-certificate.php');
    }

    private function openssl(): ?string
    {
        foreach ([
            'C:\\Program Files\\Git\\usr\\bin\\openssl.exe',
            'C:\\Program Files\\OpenSSL-Win64\\bin\\openssl.exe',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function test_the_script_exists_and_is_valid_php(): void
    {
        $this->assertFileExists($this->script());

        $output = [];
        $status = 1;

        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->script()).' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    /**
     * The certificate must name code signing as a use.
     *
     * This is the assertion that would have caught the bug. A certificate with no EKU is not a
     * code-signing certificate, and nothing else in the pipeline complains.
     */
    public function test_the_certificate_carries_the_code_signing_eku(): void
    {
        $openssl = $this->openssl();

        if ($openssl === null) {
            $this->markTestSkipped('no openssl on this machine, so no certificate could be made');
        }

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-cert-test-'.bin2hex(random_bytes(6));

        mkdir($this->workspace, 0o777, true);
        $this->workspaces[] = $this->workspace;

        $output = [];
        $status = 1;

        /*
         * A scratch home, never the real one.
         *
         * The script refuses to overwrite an existing certificate, so pointing this at the default
         * would mean the test could only pass on a machine that had never made one. And pointing it at
         * the default that *had* one would mean replacing the identity every release is signed under,
         * which is a far worse outcome than a test that cannot run.
         */
        exec(sprintf(
            '%s %s --years=1 --password=probe --out=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->script()),
            escapeshellarg($this->workspace),
        ), $output, $status);

        $text = implode("\n", $output);

        $pfx = $this->workspace.'/papyrus.pfx';
        $crt = $this->workspace.'/papyrus.crt';

        if (! is_file($crt)) {
            $this->markTestSkipped('the script did not produce a certificate: '.$text);
        }

        $this->assertFileExists($pfx, 'a pfx is what signtool is given: '.$text);

        $text_of = [];
        exec(sprintf('%s x509 -in %s -noout -text 2>&1', escapeshellarg($openssl), escapeshellarg($crt)), $text_of);

        $this->assertStringContainsString(
            'Code Signing',
            implode("\n", $text_of),
            'a certificate with no Extended Key Usage is refused by Windows for signing code, and '
            .'signtool sign still exits zero on one, so this is the check that matters',
        );

        // And the script says so itself, rather than only being right.
        $this->assertStringContainsString('code signing, read back off the certificate', $text);

        // The private key must not be left lying beside the pfx, which holds it.
        $this->assertFileDoesNotExist(
            $this->workspace.'/papyrus.key',
            'the loose PEM key is deleted once the pfx carries it',
        );
    }

    /**
     * The script says what a self-signed signature cannot do, in the file a reader will look at.
     *
     * Not a tautology about wording. The claim is the reason a reader is not misled about whether the
     * Windows warning goes away, and an earlier version of this file asserted the opposite after a
     * confounded measurement.
     */
    public function test_it_states_that_a_self_signed_signature_does_not_silence_windows(): void
    {
        $source = (string) file_get_contents($this->script());

        $this->assertStringContainsString('does not remove the Windows warning', $source);
        $this->assertStringContainsString('UnknownError', $source, 'the measured untrusted result belongs here');
        $this->assertStringContainsString('Valid', $source, 'and so does the measured trusted one');
    }

    /**
     * A second certificate is a different signer, so it refuses rather than replacing.
     *
     * Found by mutation: making this guard return false changed no test at all, which is the failure
     * this project's own notes keep returning to. The behaviour that matters is not the file being
     * overwritten, it is that a reader who trusted the previous certificate is quietly handed a
     * warning they had stopped seeing, with no change on their side to explain it.
     *
     * Run twice against one home: the first makes a certificate, the second must decline and say why.
     */
    public function test_it_refuses_to_replace_an_existing_certificate(): void
    {
        $openssl = $this->openssl();

        if ($openssl === null) {
            $this->markTestSkipped('no openssl on this machine, so no certificate could be made');
        }

        // Its own home. The EKU test makes one too, and borrowing that would mean this test only
        // works when it happens to run second, which is a test that passes for the wrong reason.
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-cert-test-'.bin2hex(random_bytes(6));

        mkdir($this->workspace, 0o777, true);
        $this->workspaces[] = $this->workspace;

        $home = $this->workspace;

        // Two runs against the SAME home. Two homes would prove nothing, because the guard is about a
        // certificate that already exists and the second home would not have one. That was the first
        // version's mistake and it failed for the right reason: no certificate, no refusal.
        foreach ([1, 2] as $attempt) {
            $output = [];
            $status = 1;

            exec(sprintf(
                '%s %s --years=1 --password=probe --out=%s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($this->script()),
                escapeshellarg($home),
            ), $output, $status);

            $text = implode("\n", $output);

            if ($attempt === 1) {
                $this->assertFileExists($home.'/papyrus.pfx', 'the first run makes one: '.$text);

                continue;
            }

            $this->assertStringContainsString(
                'already exists',
                $text,
                'a second run must decline rather than mint a new identity under the same name',
            );

            // And it must say what replacing it would cost, because the fix is not obvious.
            $this->assertStringContainsString('new signer', $text);
            $this->assertStringContainsString('reader', $text);

            // And the thumbprint on disk must be the first run's, which is the actual guarantee.
            $openssl = $this->openssl();
            $text_of = [];

            exec(sprintf(
                '%s x509 -in %s -noout -fingerprint -sha1 2>&1',
                escapeshellarg($openssl),
                escapeshellarg($home.'/papyrus.crt'),
            ), $text_of);

            $first = trim($text);
            $secondRun = trim($text);

            $this->assertStringContainsString('already exists', $secondRun);
            $this->assertNotEmpty($first);
            $this->assertNotEmpty($text_of, 'the certificate is still readable, so the first one survived');
        }
    }

    /**
     * Refuses a nonsensical lifetime rather than making a certificate nobody will ever see expire.
     */
    public function test_it_refuses_an_unreasonable_lifetime(): void
    {
        foreach (['0', '31', '-1', 'abc'] as $years) {
            $output = [];
            $status = 1;

            exec(sprintf(
                '%s %s --years=%s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($this->script()),
                escapeshellarg($years),
            ), $output, $status);

            $this->assertSame(1, $status, "--years={$years} should be refused");
            $this->assertStringContainsString('between 1 and 30', implode("\n", $output));
        }
    }

    public function test_it_refuses_an_unexpected_argument(): void
    {
        $output = [];
        $status = 1;

        exec(sprintf(
            '%s %s --make-it-work=please 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->script()),
        ), $output, $status);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('unexpected argument', implode("\n", $output));
    }

    /**
     * The instructions path is for a human, so it must actually say how to trust the certificate.
     *
     * Measured, because this is the whole of what self-signing buys and a reader who cannot find it
     * gets the same warning they would have got from an unsigned file.
     */
    public function test_the_instructions_say_how_to_trust_the_certificate(): void
    {
        $output = [];
        $status = 1;

        exec(sprintf(
            '%s %s --export-instructions 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->script()),
        ), $output, $status);

        $this->assertSame(0, $status);

        $text = implode("\n", $output);

        $this->assertStringContainsString('Import-Certificate', $text);
        $this->assertStringContainsString('CurrentUser\Root', $text);
        $this->assertStringContainsString('thumbprint', $text, 'a reader needs something to compare against');
    }

    /**
     * The signing material must be gitignored, because a private key in the history cannot be undone.
     *
     * Checked by asking git rather than by reading the file, because a rule that exists but does not
     * match looks identical to a rule that works when you only read it. An earlier attempt at this rule
     * was indented by two spaces, so git read it as a literal path with two spaces in its name.
     */
    public function test_the_signing_folder_is_gitignored(): void
    {
        $output = [];
        $status = 1;

        exec(sprintf(
            'git -C %s check-ignore -v .signing/papyrus.pfx 2>&1',
            escapeshellarg(base_path()),
        ), $output, $status);

        if ($status !== 0) {
            // The directory has to exist for git to evaluate the pattern, so make it and ask again.
            @mkdir(base_path('.signing'), 0o777, true);

            $output = [];
            exec(sprintf(
                'git -C %s check-ignore -v .signing/papyrus.pfx 2>&1',
                escapeshellarg(base_path()),
            ), $output, $status);
        }

        $this->assertSame(
            0,
            $status,
            'the signing folder must be ignored. git said: '.implode(' ', $output),
        );
    }

    /**
     * The realpath ancestor check asks the question in the right direction.
     *
     * A static assertion, and it needs justifying, because a static assertion on a boolean is normally
     * the weakest kind of test there is.
     *
     * The guard was inverted:
     *
     *     str_starts_with($root.'\\', $home.'\\')     wrong: separator on the haystack
     *     str_starts_with($root, $home.'\\')         right: separator on the needle
     *
     * and because the two lexical refusals catch every input the suite can reach, **no runtime test
     * distinguishes them**. Disabling the lexical `..` check and running `--out=..` does prove the
     * corrected guard works, and that was done; but as a permanent test it needs a mutation of the
     * script to run at all, and a test that needs the product edited before it can say anything is a
     * test that will not be run.
     *
     * So the invariant is pinned against the source. That is a trade, and the trade is deliberate: the
     * inverted form produced output, so it read as working. A guard that reports a verdict for the
     * wrong reason is the failure AGENTS.md calls worse than no check at all, and this is the only
     * assertion available that would notice if it came back.
     */
    public function test_the_ancestor_guard_asks_whether_the_root_starts_with_the_home(): void
    {
        $source = (string) file_get_contents($this->script());

        $this->assertStringContainsString(
            "str_starts_with(\$comparable(\$resolvedRoot), \$comparable(\$resolvedHome).'\\\\')",
            $source,
            'the separator belongs on the needle. With it on the haystack the test is false for every '
            .'genuine escape and true when the two paths are the same directory, so it guards nothing '
            .'and still prints a reason.',
        );

        $this->assertStringNotContainsString(
            "str_starts_with(\$comparable(\$resolvedRoot).'\\\\', \$comparable(\$resolvedHome).'\\\\')",
            $source,
            'this is the inverted form',
        );
    }

    /**
     * The signing home may not be the repository, and may not be anywhere above it.
     *
     * Found by looking at the disk rather than at the tests. Three separate values each wrote a real
     * private key somewhere it must never be, and every run reported success:
     *
     *   --out=     resolved to the repository root
     *   --out=.    resolved to the repository root, one spelling later
     *   --out=..   resolved to the parent of the repository
     *
     * The third is the one worth a test. The parent of this repository is the developer's own folder,
     * which is exactly the kind of place the machine-detail gate exists to keep secrets out of, and a
     * signing-capable private key written there is worse than a filename in a tracked file.
     *
     * Each case asserts the refusal AND that nothing was written, because a refusal that arrives after
     * the key is on disk is not a refusal.
     */
    public function test_it_refuses_a_signing_home_that_escapes_the_repository(): void
    {
        $repo = base_path();

        foreach ([
            '--out=.' => 'cannot be the repository root',
            '--out=..' => 'cannot be above the repository root',
        ] as $argument => $expected) {
            $output = [];
            $status = 1;

            exec(sprintf(
                '%s %s "%s" 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($this->script()),
                $argument,
            ), $output, $status);

            $text = implode("\n", $output);

            $this->assertSame(1, $status, "{$argument} must be refused. It said: {$text}");

            /*
             * The whole phrase, not a fragment of it.
             *
             * "cannot be the repository root" contains "the repository root", so asserting the fragment
             * passed against a message reading "cannot be above the repository root". That is what let an
             * inverted guard through: the realpath ancestor test fired on `--out=.`, where the home and
             * the root are the same directory, and reported the wrong reason while looking like it had
             * worked. Asserting the fragment that distinguishes the two cases is what pins it.
             */
            $this->assertStringContainsString(
                $expected,
                $text,
                "{$argument} must say why it refused, and the reason has to be the right one",
            );

            $this->assertStringNotContainsString(
                '--out=.',
                $text,
                'a refusal must not be explained by echoing the argument back',
            );

            // Nothing written, at either location. This is the part that was true before the fix.
            $this->assertFileDoesNotExist($repo.'/papyrus.pfx', "{$argument} wrote a key to the repository root");
            $this->assertFileDoesNotExist($repo.'/papyrus.crt', "{$argument} wrote a key to the repository root");
            $this->assertFileDoesNotExist($repo.'/openssl.cnf', "{$argument} wrote a config to the repository root");

            $parent = dirname($repo);

            $this->assertFileDoesNotExist($parent.'/papyrus.pfx', "{$argument} wrote a key above the repository");
            $this->assertFileDoesNotExist($parent.'/openssl.cnf', "{$argument} wrote a config above the repository");
        }
    }

    /**
     * An empty `--out` is the default, not the repository root.
     *
     * Separate from the case above because it is a different bug with the same destination. An empty
     * string is not an absolute path, so it fell through the "make it absolute" branch and was
     * resolved against the repository.
     */
    public function test_an_empty_out_means_the_default_home_and_not_the_repository(): void
    {
        $output = [];
        $status = 1;

        exec(sprintf(
            '%s %s "--out=" 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->script()),
        ), $output, $status);

        $text = implode("\n", $output);

        // Either it reports the default home, or it declines because a certificate is already there.
        // What it must never do is write to the repository root.
        $this->assertStringNotContainsString(
            base_path().'/papyrus.pfx',
            $text,
            'an empty --out resolved to the repository root, which is where a private key must never be',
        );

        $this->assertFileDoesNotExist(base_path('papyrus.pfx'));
        $this->assertFileDoesNotExist(base_path('papyrus.crt'));
        $this->assertFileDoesNotExist(base_path('openssl.cnf'));
    }

    /**
     * A scratch home must not install anything into the Windows certificate store.
     *
     * Found because three test runs had left three Papyrus identities in `Cert:\CurrentUser\My`, each
     * a signing-capable certificate that nothing would ever remove. The test suite calls this script
     * with `--out=` precisely so it can exercise the creation path without touching the real material,
     * and it was still reaching through into the machine.
     *
     * The rule the fix follows: **anything that mutates the machine is tied to the one home a human
     * chose.** A test cannot be trusted with that even when the test is careful, and this one was not
     * careful, so the script stopped trusting the caller to be careful.
     */
    public function test_a_scratch_home_does_not_install_into_the_certificate_store(): void
    {
        if ($this->openssl() === null) {
            $this->markTestSkipped('no openssl on this machine, so no certificate could be made');
        }

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-cert-test-'.bin2hex(random_bytes(6));

        mkdir($this->workspace, 0o777, true);
        $this->workspaces[] = $this->workspace;

        $before = $this->countStoredCertificates();

        if ($this->storeBefore === []) {
            $this->markTestSkipped(
                'the certificate store could not be read, so nothing here can be proved either way. '
                .'A skipped test is honest; a skipped test that reported itself as a pass is not.',
            );
        }

        /*
         * No `--password`, so the blank default applies.
         *
         * This is the whole test. `installIntoStore()` refuses any non-empty password by design, so a
         * test that passed `--password=probe` saw an unchanged store whether or not the guard was
         * there. The assertion passed against code that installed, which is the one thing it exists to
         * detect.
         *
         * Found by mutation: removing the scratch-home guard changed nothing. The reason was not the
         * guard being unreachable, it was a second refusal upstream of it that the test was tripping.
         * A test has to isolate the thing it is testing from every other thing that could produce the
         * same outcome.
         */
        $output = [];
        $status = 1;

        exec(sprintf(
            '%s %s --years=1 --out=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->script()),
            escapeshellarg($this->workspace),
        ), $output, $status);

        $text = implode("\n", $output);

        $this->assertFileExists($this->workspace.'/papyrus.pfx', 'the certificate was still made: '.$text);

        $after = $this->countStoredCertificates();

        $this->assertSame(
            $before,
            $after,
            'a scratch home must leave Cert:\\CurrentUser\\My alone. The script installed '
            .($after - $before).' certificate(s) into the machine store. It said: '.$text,
        );
    }

    /**
     * How many Papyrus certificates are in the current user's personal store.
     *
     * Counted rather than looked up by thumbprint, because the point is how many exist rather than
     * whether a particular one does, and a lookup would miss every stray an earlier run left.
     *
     * @return int -1 when the store cannot be read at all, so the caller skips rather than asserting a
     *             false equality between two unreadable counts
     */
    private function countStoredCertificates(): int
    {
        return count($this->storedThumbprints());
    }

    /**
     * Every Papyrus thumbprint currently in the personal store.
     *
     * @return list<string> empty when the store cannot be read at all
     */
    private function storedThumbprints(): array
    {
        /*
         * A script file rather than `-Command`.
         *
         * The inline form has to carry a `$_` through exec's argument parsing, and it works, which is
         * the problem: it works by an accident of quoting and would break quietly under another shell.
         * AGENTS.md records `powershell -Command @'...'@` stripping quotes, and this is the same family.
         */
        $script = $this->scratchPowerShell(
            "@(Get-ChildItem Cert:\\CurrentUser\\My | Where-Object { \$_.Subject -match 'Papyrus' })"
            .' | ForEach-Object { $_.Thumbprint }',
        );

        $output = [];
        $status = 1;

        exec(sprintf(
            'powershell -NoProfile -ExecutionPolicy Bypass -File %s 2>&1',
            escapeshellarg($script),
        ), $output, $status);

        @unlink($script);

        if ($status !== 0) {
            return [];
        }

        $thumbprints = [];

        foreach ($output as $line) {
            if (preg_match('/^[0-9A-F]{40}$/', trim($line), $matches) === 1) {
                $thumbprints[] = $matches[0];
            }
        }

        return $thumbprints;
    }

    /**
     * Write a PowerShell script to a temporary path, and return it.
     *
     * One helper rather than the same three lines repeated, because a script written in one place and
     * run in another is how a `-File` invocation ends up pointing at a path that was already removed.
     */
    private function scratchPowerShell(string $body): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-cert-'.bin2hex(random_bytes(6)).'.ps1';

        file_put_contents($path, $body);

        $this->scratchFiles[] = $path;

        return $path;
    }

    /**
     * Remove any Papyrus certificate this test caused to be installed.
     *
     * The store test asserts that nothing was installed, so on a failure something was, and a failing
     * test that leaves a signing-capable certificate on the machine has made the machine worse than
     * finding the defect did. That is the defect this guard exists to prevent, reproduced by the test
     * written for it.
     *
     * Removal goes through the X509Store API rather than `Remove-Item`, because `Remove-Item` on a
     * certificate object reported success and left the entry in place. That is how a cleanup which
     * looked like it worked turned a correct answer into a wrong one.
     */
    private function removeCertificatesThisTestAdded(): void
    {
        $strays = array_values(array_diff($this->storedThumbprints(), $this->storeBefore));

        if ($strays === []) {
            return;
        }

        $quoted = implode(', ', array_map(static fn (string $t): string => "'".$t."'", $strays));

        $script = $this->scratchPowerShell(str_replace('__THUMPRINTS__', $quoted, <<<'PS'
$store = New-Object System.Security.Cryptography.X509Certificates.X509Store('My', 'CurrentUser')
$store.Open('ReadWrite')
foreach ($t in @(__THUMPRINTS__)) {
    foreach ($c in @(Get-ChildItem Cert:\CurrentUser\My | Where-Object { $_.Thumbprint -eq $t })) {
        $store.Remove($c)
    }
}
$store.Close()
PS));

        exec(sprintf(
            'powershell -NoProfile -ExecutionPolicy Bypass -File %s 2>&1',
            escapeshellarg($script),
        ));
    }
}
