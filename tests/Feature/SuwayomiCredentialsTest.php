<?php

declare(strict_types=1);

namespace Tests\Feature;

use Papyrus\Launcher\SuwayomiCredentials;
use PHPUnit\Framework\TestCase;

/**
 * The launcher's Suwayomi credential check, tested without a Suwayomi or a network.
 *
 * ## Why this is tested at all
 *
 * Because this check is the only thing standing between a reader and an install that looks
 * healthy, serves their library, and refuses every single query behind it. Everything else in the
 * launcher reported success throughout: `suwayomiAnswers()` returned true, `/ready` answered 200,
 * `papyrus health` printed six green lines, and every card in the library read "Not on the server".
 *
 * Both directions of that decision matter, and they fail differently.
 *
 *   A false negative is the bug it was written for: a broken install that starts and says nothing.
 *   A false positive stops a working install, which costs the reader their application entirely.
 *
 * So the tests below are weighted towards the second, because that is the failure a well-meaning
 * fix introduces and the one no reader can work around.
 *
 * The launcher files are required directly rather than through the framework, for the same reason
 * the launcher itself avoids the framework: it has to work when the application does not.
 */
final class SuwayomiCredentialsTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-creds-'.getmypid();

        if (! is_dir($this->scratch)) {
            mkdir($this->scratch, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->scratch);

        parent::tearDown();
    }

    /** The `.env` a launcher writes when it has no credentials, which is the shape the bug took. */
    private function envFile(string $contents): string
    {
        $path = $this->scratch.DIRECTORY_SEPARATOR.'.env';

        file_put_contents($path, $contents);

        return $path;
    }

    /** The body Suwayomi returns for a query on a `@RequireAuth` field, signed out. */
    private const UNAUTHORIZED = '{"errors":[{"message":"Exception while fetching data (/sources) : '
        .'Unauthorized\r\n\r\nsuwayomi.tachidesk.server.user.UnauthorizedException: Unauthorized"}]}';

    /** The body when the same query succeeds. */
    private const ALLOWED = '{"data":{"sources":{"totalCount":223}}}';

    public function test_it_refuses_when_suwayomi_requires_a_login_and_this_install_has_none(): void
    {
        $refusal = (new SuwayomiCredentials(
            environmentFile: $this->envFile("SUWAYOMI_USERNAME=''\nSUWAYOMI_PASSWORD=''\n"),
            environment: [],
            probe: fn (string $query): ?string => self::UNAUTHORIZED,
        ))->refusal();

        $this->assertNotNull($refusal, 'this is the whole defect, and it must not pass');
        $this->assertStringContainsString('SUWAYOMI_USERNAME', (string) $refusal);
        $this->assertStringContainsString('SUWAYOMI_PASSWORD', (string) $refusal);
    }

    public function test_the_refusal_names_the_file_the_reader_has_to_edit(): void
    {
        $path = $this->envFile("SUWAYOMI_USERNAME=''\n");

        $refusal = (new SuwayomiCredentials(
            environmentFile: $path,
            environment: [],
            probe: fn (string $query): ?string => self::UNAUTHORIZED,
        ))->refusal();

        /*
         * The reader is looking at a library that says every series is missing. A message saying
         * "credentials are not set" with no path is a support ticket. The path is the difference.
         */
        $this->assertStringContainsString($path, (string) $refusal);
    }

    public function test_it_does_not_refuse_when_the_credentials_are_in_the_env_file(): void
    {
        $this->assertNull((new SuwayomiCredentials(
            environmentFile: $this->envFile("SUWAYOMI_USERNAME='papyrus'\nSUWAYOMI_PASSWORD='secret'\n"),
            environment: [],
            probe: fn (string $query): ?string => self::UNAUTHORIZED,
        ))->refusal(), 'credentials present, so an unauthorized answer cannot be about us');
    }

    public function test_it_does_not_refuse_when_suwayomi_needs_no_login(): void
    {
        $this->assertNull((new SuwayomiCredentials(
            environmentFile: $this->envFile("SUWAYOMI_USERNAME=''\n"),
            environment: [],
            probe: fn (string $query): ?string => self::ALLOWED,
        ))->refusal(), 'a server that answered the query is a server this install can use');
    }

    public function test_it_does_not_refuse_when_the_probe_could_not_be_made(): void
    {
        /*
         * The direction that matters most.
         *
         * A refused probe means "no idea", and treating that as "no credentials" would stop a
         * working install because a socket blipped. Null has to mean carry on.
         */
        $this->assertNull((new SuwayomiCredentials(
            environmentFile: $this->envFile("SUWAYOMI_USERNAME=''\n"),
            environment: [],
            probe: fn (string $query): ?string => null,
        ))->refusal(), 'an unreachable server is not evidence of missing credentials');
    }

    public function test_it_does_not_refuse_when_there_is_no_probe_to_run(): void
    {
        $this->assertNull((new SuwayomiCredentials(
            environmentFile: $this->envFile("SUWAYOMI_USERNAME=''\n"),
            environment: [],
        ))->refusal(), 'with nothing to probe there is nothing to conclude');
    }

    public function test_a_username_without_a_password_is_not_a_credential(): void
    {
        /*
         * The half-configured case, which is what a reader who filled in one field produces.
         *
         * Counting this as configured would refuse nothing, hand the application a username and
         * an empty password, and reproduce the original defect with extra steps.
         */
        $credentials = new SuwayomiCredentials(
            environmentFile: $this->envFile("SUWAYOMI_USERNAME='papyrus'\nSUWAYOMI_PASSWORD=''\n"),
            environment: [],
            probe: fn (string $query): ?string => self::UNAUTHORIZED,
        );

        $this->assertFalse($credentials->configured(), 'both or neither');
        $this->assertSame([], $credentials->childEnvironment(), 'and nothing is passed to the application');
        $this->assertNotNull($credentials->refusal());
    }

    public function test_quoted_empty_values_are_treated_as_absent(): void
    {
        /*
         * The exact shape the generated `.env` ships.
         *
         * `''` is a present key holding nothing. A `trim()` that leaves it as an empty string
         * makes `configured()` true, and the install reports itself fine while every query is
         * refused. This is the line the original defect turned on.
         */
        $credentials = new SuwayomiCredentials(
            environmentFile: $this->envFile("SUWAYOMI_USERNAME=''\nSUWAYOMI_PASSWORD=''\n"),
            environment: [],
        );

        $this->assertFalse($credentials->configured());
        $this->assertNull($credentials->username());
        $this->assertNull($credentials->password());
    }

    public function test_the_environment_wins_over_the_file(): void
    {
        /*
         * An unattended install sets them once and never writes them to disk, so the environment
         * has to take precedence. Reading the file first would ignore the only copy that exists.
         */
        $credentials = new SuwayomiCredentials(
            environmentFile: $this->envFile("SUWAYOMI_USERNAME='from-file'\nSUWAYOMI_PASSWORD='from-file'\n"),
            environment: ['SUWAYOMI_USERNAME' => 'from-env', 'SUWAYOMI_PASSWORD' => 'from-env'],
        );

        $this->assertTrue($credentials->configured());
        $this->assertSame('from-env', $credentials->username());
    }

    public function test_credentials_are_handed_to_the_application_so_both_halves_agree(): void
    {
        $credentials = new SuwayomiCredentials(
            environmentFile: $this->envFile(''),
            environment: ['SUWAYOMI_USERNAME' => 'papyrus', 'SUWAYOMI_PASSWORD' => 'secret'],
        );

        /*
         * The launcher and the application must read the same pair. If the launcher authenticated
         * with one set of credentials and the application with another, the launcher's probe would
         * pass and every page behind it would fail, which is the exact shape of the original bug
         * one level down.
         */
        $this->assertSame([
            'SUWAYOMI_USERNAME' => 'papyrus',
            'SUWAYOMI_PASSWORD' => 'secret',
        ], $credentials->childEnvironment());
    }

    public function test_a_missing_env_file_is_not_a_crash(): void
    {
        $credentials = new SuwayomiCredentials(
            environmentFile: $this->scratch.DIRECTORY_SEPARATOR.'nothing-here.env',
            environment: [],
        );

        $this->assertFalse($credentials->configured());
        $this->assertSame([], $credentials->childEnvironment());
    }

    public function test_the_probe_asks_for_something_that_actually_requires_a_login(): void
    {
        /*
         * The detection rests entirely on this query.
         *
         * `aboutServer` carries no `@RequireAuth`, so it answers 200 with a version string on a
         * Suwayomi that will refuse every query the application makes. Probing with it would say
         * "reachable" about an install that cannot read a single series, which is the check that
         * let this go unnoticed for a whole evening.
         */
        $asked = null;

        (new SuwayomiCredentials(
            environmentFile: $this->envFile("SUWAYOMI_USERNAME=''\n"),
            environment: [],
            probe: function (string $query) use (&$asked): ?string {
                $asked = $query;

                return self::UNAUTHORIZED;
            },
        ))->refusal();

        $this->assertNotNull($asked, 'the probe never ran');
        $this->assertStringNotContainsString('aboutServer', (string) $asked);
        $this->assertStringContainsString('sources', (string) $asked);
    }

    public function test_it_never_asks_the_reader_for_a_password(): void
    {
        /*
         * PHP on Windows cannot mask console input without a dependency, so a prompt would put the
         * password on screen and in scrollback. There is no prompt to write, and this is the test
         * that says so: the class has no method that reads standard input, and adding one should
         * break something here.
         */
        $refusal = (new SuwayomiCredentials(
            environmentFile: $this->envFile("SUWAYOMI_USERNAME=''\n"),
            environment: [],
            probe: fn (string $query): ?string => self::UNAUTHORIZED,
        ))->refusal();

        $this->assertStringNotContainsString('enter your password', strtolower((string) $refusal));
        $this->assertStringContainsString('environment', strtolower((string) $refusal));
    }
}
