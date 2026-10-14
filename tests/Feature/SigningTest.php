<?php

namespace Tests\Feature;

use Papyrus\Signing\Signing;
use RuntimeException;
use Tests\TestCase;

/**
 * Authenticode signing, proven against a real certificate rather than a mocked tool.
 *
 * ## Why this test shells out to openssl and signtool
 *
 * Because the question the signing code answers is not "did the function return", it is "is there a
 * signature on the file afterwards". A stubbed signtool returning exit zero proves the first and says
 * nothing about the second, and the second is the only one a reader will ever experience.
 *
 * It also earns its keep immediately. The first run of this signed a copy of `Papyrus.cmd` and the
 * class reported, correctly and at length, `You cannot use the /td option twice`. That is a bug no
 * unit test with a mocked tool would ever have found, and it is the same shape of failure as the ones
 * in this project's engineering notes: correct code, wrong assumption about a tool.
 *
 * ## Why it skips rather than fails when the tooling is absent
 *
 * `signtool.exe` ships with the Windows SDK and openssl with Git. A machine without either cannot
 * prove anything here, and a test that fails for want of a tool teaches people to ignore it. It skips
 * with a reason printed, which is the difference between a check that is silent and one that is absent.
 */
class SigningTest extends TestCase
{
    private static ?string $work = null;

    protected function tearDown(): void
    {
        if (self::$work !== null && is_dir(self::$work)) {
            foreach ((array) glob(self::$work.DIRECTORY_SEPARATOR.'*') as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }

            @rmdir(self::$work);
        }

        parent::tearDown();
    }

    // The refusals, which are the important half ----------------------------

    /**
     * Signing is opt-in by configuration and never by omission, but once asked for it cannot proceed
     * quietly. This is the assertion that stops an unsigned build being published as a signed one.
     */
    public function test_asking_to_sign_without_a_certificate_refuses(): void
    {
        $this->withEnv(['PAPYRUS_SIGN' => '1'], function () {
            $this->expectException(RuntimeException::class);

            Signing::sign([$this->artefact()]);
        });
    }

    public function test_it_will_not_sign_a_file_that_is_not_there(): void
    {
        $this->withEnv(['PAPYRUS_SIGN' => '1', 'PAPYRUS_CERT_PFX' => 'unused.pfx'], function () {
            $this->expectException(RuntimeException::class);

            Signing::sign(['C:\\nowhere\\missing-installer.exe']);
        });
    }

    /**
     * An environment variable set to nothing must count as unset.
     *
     * This is not pedantry. `PAPYRUS_SIGN=` in a shell, or an empty value through a CI secret that
     * failed to populate, is the exact shape of "signing was on and then quietly wasn't", and it is
     * the one hole through which an unsigned release reaches a reader looking like a signed one.
     */
    public function test_an_empty_environment_variable_counts_as_unset(): void
    {
        $this->withEnv(['PAPYRUS_SIGN' => '   '], function () {
            $this->assertFalse(Signing::isRequested());
        });

        $this->withEnv(['PAPYRUS_SIGN' => ''], function () {
            $this->assertFalse(Signing::isRequested());
        });
    }

    public function test_signing_is_requested_only_when_asked_for(): void
    {
        $this->withEnv([], function () {
            $this->assertFalse(Signing::isRequested());
        });

        $this->withEnv(['PAPYRUS_SIGN' => '1'], function () {
            $this->assertTrue(Signing::isRequested());
        });
    }

    public function test_release_mode_is_opt_in_too(): void
    {
        $this->withEnv([], function () {
            $this->assertFalse(Signing::isRelease());
        });

        $this->withEnv(['PAPYRUS_RELEASE' => '1'], function () {
            $this->assertTrue(Signing::isRelease());
        });
    }

    // The live signature ---------------------------------------------------

    /**
     * Sign a real binary with a real self-signed certificate, and ask Windows what it thinks.
     *
     * The expected answer is `UnknownError`, and that is the whole point of the assertion. A
     * self-signed certificate chains to nothing Windows trusts, so the signature is genuinely present
     * and genuinely untrusted. The two answers that would mean the signing did nothing are `Valid`,
     * which means there was no signature to object to, and `NotSigned`.
     */
    public function test_it_actually_signs_a_binary(): void
    {
        $openssl = $this->openssl();

        if ($openssl === null) {
            $this->markTestSkipped('no openssl on this machine, so no test certificate could be made');
        }

        $pfx = $this->makeCertificate($openssl);

        if ($pfx === null) {
            $this->markTestSkipped('openssl is present but could not produce a usable certificate');
        }

        $source = $this->realBinary();

        if ($source === null) {
            $this->markTestSkipped('no PE binary of a signable size was found to sign');
        }

        $artefact = $this->work().DIRECTORY_SEPARATOR.'probe.exe';

        $this->assertTrue(copy($source, $artefact), 'could not stage a copy to sign');

        $this->withEnv([
            'PAPYRUS_SIGN' => '1',
            'PAPYRUS_CERT_PFX' => $pfx,
            'PAPYRUS_CERT_PASSWORD' => 'probe',
        ], function () use ($artefact) {
            Signing::sign([$artefact], true);
        });

        // Windows is the authority here, not signtool's exit code.
        $output = [];
        $status = 1;

        exec(sprintf(
            'powershell -NoProfile -Command "(Get-AuthenticodeSignature -LiteralPath %s).Status" 2>&1',
            escapeshellarg($artefact),
        ), $output, $status);

        $reported = trim(implode(' ', $output));

        $this->assertNotSame('NotSigned', $reported, 'signtool reported success but Windows finds no signature');
        $this->assertNotSame('Valid', $reported, 'Windows reports Valid, so nothing was actually signed');
        $this->assertNotSame('', $reported, 'PowerShell returned nothing at all');

        /*
         * The class's own description has to agree, and it has to say present rather than
         * unsigned.
         *
         * The wording changed once it was measured. unsigned invites the reader to conclude the
         * file is untampered-with in the sense of unsigned, which is not what it means: the
         * signature IS there, it is simply chained to a root this machine was never told to trust.
         * That distinction is the whole content of the message, so the test holds the wording too.
         */
        $described = Signing::describe($artefact);

        $this->assertStringContainsString('present', $described);
        $this->assertStringContainsString('does not trust', $described);
        $this->assertStringNotContainsString(
            'unsigned',
            $described,
            'the signature is present; calling it unsigned misstates what a reader is looking at',
        );
    }

    /**
     * A batch file cannot carry an Authenticode signature, and the tool says so.
     *
     * Worth a test because the build's own entry point is a `.cmd`, and an earlier version of this
     * probe signed one and reported a confusing format error. It is a real constraint, not a bug.
     */
    public function test_signing_a_non_pe_file_is_refused_rather_than_pretended(): void
    {
        if ($this->openssl() === null) {
            $this->markTestSkipped('no openssl on this machine, so no test certificate could be made');
        }

        $pfx = $this->makeCertificate($this->openssl());

        if ($pfx === null) {
            $this->markTestSkipped('openssl is present but could not produce a usable certificate');
        }

        $notABinary = $this->work().DIRECTORY_SEPARATOR.'script.exe';

        file_put_contents($notABinary, "@echo off\r\nrem not a PE image\r\n");

        $threw = false;
        $reason = '';

        /*
         * `&$threw` by reference, and that is load bearing.
         *
         * Written as `use ($threw)` the closure gets its own copy, writes to it, and the assertion
         * below reads an untouched false. The test then passes or fails on nothing: it reported
         * "signing a non-PE file must be an error" against code that was refusing correctly the whole
         * time. Which is the "a check that cannot fail is not a check" shape from this project's own
         * defect families, arrived at from the other direction: usually the assertion is unreachable,
         * here the signal never left the closure.
         */
        $this->withEnv([
            'PAPYRUS_SIGN' => '1',
            'PAPYRUS_CERT_PFX' => $pfx,
            'PAPYRUS_CERT_PASSWORD' => 'probe',
        ], function () use ($notABinary, &$threw, &$reason) {
            try {
                Signing::sign([$notABinary], true);
            } catch (RuntimeException $e) {
                $threw = true;
                $reason = $e->getMessage();
            }
        });

        $this->assertTrue($threw, 'signing a non-PE file must be an error, not a quiet success');
        $this->assertStringContainsString('exited', $reason, 'the refusal should name the tool that refused');
    }

    // Helpers --------------------------------------------------------------

    private function work(): string
    {
        self::$work ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-signing-test';

        if (! is_dir(self::$work)) {
            mkdir(self::$work, 0o777, true);
        }

        return self::$work;
    }

    /**
     * Run a closure with environment variables set and then unset.
     *
     * Restoring matters more than it looks: the suite runs in one process, and a leaked
     * `PAPYRUS_SIGN=1` would silently change what an unrelated test believes about signing.
     */
    private function withEnv(array $variables, callable $callback): void
    {
        $previous = [];

        foreach ($variables as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name.'='.$value);
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $name => $value) {
                $value === false ? putenv($name) : putenv($name.'='.$value);
            }
        }
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

        $found = [];
        $status = 1;

        exec('where openssl 2>&1', $found, $status);

        return $status === 0 && $found !== [] ? trim($found[0]) : null;
    }

    /**
     * A throwaway self-signed certificate, identified by nobody and trusted by nothing.
     */
    private function makeCertificate(string $openssl): ?string
    {
        $pfx = $this->work().DIRECTORY_SEPARATOR.'probe.pfx';
        $key = $this->work().DIRECTORY_SEPARATOR.'probe.key';
        $crt = $this->work().DIRECTORY_SEPARATOR.'probe.crt';

        $status = 1;

        exec(sprintf(
            '%s req -x509 -newkey rsa:2048 -keyout %s -out %s -days 2 -nodes -subj %s 2>&1',
            escapeshellarg($openssl),
            escapeshellarg($key),
            escapeshellarg($crt),
            escapeshellarg('/CN=Papyrus Signing Probe/O=Papyrus Test Only'),
        ), $ignored, $status);

        if ($status !== 0) {
            return null;
        }

        $status = 1;

        exec(sprintf(
            '%s pkcs12 -export -out %s -inkey %s -in %s -passout pass:probe 2>&1',
            escapeshellarg($openssl),
            escapeshellarg($pfx),
            escapeshellarg($key),
            escapeshellarg($crt),
        ), $ignored, $status);

        return $status === 0 && is_file($pfx) ? $pfx : null;
    }

    /**
     * A real executable to sign.
     *
     * The size floor is doing real work: `vendor/bin/phpunit` is a shell wrapper of a few hundred
     * bytes on Windows, and signing one produces a different error from signing nothing.
     */
    private function realBinary(): ?string
    {
        foreach ([
            base_path('build/install/runtime/bin/php.exe'),
            base_path('build/install/runtime/bin/frankenphp.exe'),
        ] as $candidate) {
            if (is_file($candidate) && filesize($candidate) > 100000) {
                return $candidate;
            }
        }

        return null;
    }

    private function artefact(): string
    {
        $path = $this->work().DIRECTORY_SEPARATOR.'refusal-check.exe';

        file_put_contents($path, 'MZ placeholder');

        return $path;
    }
}
