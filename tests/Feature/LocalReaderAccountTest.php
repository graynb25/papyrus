<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\EnvironmentFile;
use App\Support\LocalReaderAccount;
use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * The local reader note in the install's own `.env`, and the atomic claim that closes sign-up behind it.
 *
 * ## What is and is not written
 *
 * The address, and nothing that could sign anybody in. That is the load-bearing assertion in this
 * file and it is asserted against the file's bytes rather than against a method's return value, since
 * "it wrote the right thing" and "it wrote nothing else" are only checkable on the file.
 *
 * The password is refused rather than declined because the alternative was considered. Laravel stores
 * it as a hash, so anything that could sign the reader in unattended would have to keep the plaintext
 * on disk, readable by anything running as that user, unrotatable. `users:create` goes out of its way
 * to avoid exactly that, generating a password and printing it once.
 *
 * ## Why these make real requests
 *
 * Because the previous version of this gate was unit tested against a method that nothing called, and
 * the method was correct the whole time while `/register` answered 200 on a single-user install. A
 * test that asserts a return value reads as coverage of behaviour and is not.
 */
class LocalReaderAccountTest extends TestCase
{
    use RefreshDatabase;

    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());

        $this->envPath = tempnam(sys_get_temp_dir(), 'papyrus-env-').'.env';

        file_put_contents($this->envPath, implode("\n", [
            'APP_KEY=base64:abc123',
            'PAPYRUS_DEPLOYMENT_MODE=single',
            "DB_DATABASE='C:\\Program Files\\Papyrus\\data\\database.sqlite'",
            '',
        ]));

        // Point the framework and the writer at this file, the way the launcher does on a real install.
        putenv('PAPYRUS_ENV='.$this->envPath);

        Config::set('papyrus.deployment_mode', 'single');
        Config::set('papyrus.local_reader', null);
    }

    protected function tearDown(): void
    {
        putenv('PAPYRUS_ENV');

        if (is_file($this->envPath)) {
            unlink($this->envPath);
        }

        parent::tearDown();
    }

    private function envText(): string
    {
        return (string) file_get_contents($this->envPath);
    }

    // The note is written -----------------------------------------------

    public function test_the_first_account_is_recorded_in_the_installs_own_env(): void
    {
        $this->post(route('register.store'), [
            'name' => 'First Reader',
            'email' => 'first@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            'PAPYRUS_LOCAL_READER=first@example.test',
            $this->envText(),
            'the address the reader chose is the one thing they should never have to retype',
        );
    }

    /**
     * The one that matters, asserted on the bytes.
     *
     * Not on a return value, because a method that returned "I did not write the password" while
     * writing it would pass. The only way to know what a file contains is to read the file.
     */
    public function test_the_password_is_never_written_to_the_env(): void
    {
        $this->post(route('register.store'), [
            'name' => 'First Reader',
            'email' => 'first@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertSessionHasNoErrors();

        $text = $this->envText();

        $this->assertStringNotContainsString('correct-horse-battery', $text);

        /*
         * And neither is the hash. A hash is not a login credential on its own, but writing it would
         * mean the install's env file is a place passwords live, which is the thing being refused.
         */
        $this->assertStringNotContainsString('$2y$', $text);
        $this->assertStringNotContainsString('$argon2', $text);
    }

    public function test_the_rest_of_the_env_survives(): void
    {
        $this->post(route('register.store'), [
            'name' => 'First Reader',
            'email' => 'first@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertSessionHasNoErrors();

        $text = $this->envText();

        $this->assertStringContainsString('APP_KEY=base64:abc123', $text);
        $this->assertStringContainsString('PAPYRUS_DEPLOYMENT_MODE=single', $text);

        /*
         * Byte for byte, and quoted. A Windows path with a space is quoted because unquoted it is a
         * parse error and the application does not boot at all, so this is how a real install has it.
         *
         * Asserted unchanged rather than re-quoted, because the writer only owns the one line it
         * writes and copies every other through exactly as found. That is the safer direction to be
         * wrong in: quietly rewriting a line nobody asked it to touch is how a value gets corrupted
         * without erroring.
         */
        $this->assertStringContainsString(
            "DB_DATABASE='C:\\Program Files\\Papyrus\\data\\database.sqlite'",
            $text,
        );
    }

    // It is read only while it is true ------------------------------------

    public function test_the_login_form_is_prefilled_while_the_install_is_unclaimed(): void
    {
        app(LocalReaderAccount::class)->remember('reader@example.test');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('reader@example.test');
    }

    /**
     * Once there is a real account, the note is not consulted.
     *
     * A record read after the thing it describes can be wrong. Wipe the database, and a stale address
     * would pre-fill a form for an account belonging to nobody. So it is read in exactly one state,
     * which is the state it is written in.
     */
    public function test_the_login_form_ignores_the_note_once_an_account_exists(): void
    {
        app(LocalReaderAccount::class)->remember('reader@example.test');

        User::factory()->create();

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('reader@example.test');
    }

    // A checkout's own env is never written -------------------------------

    public function test_a_checkouts_own_env_file_is_left_alone(): void
    {
        /*
         * The dangerous case, and the reason `isPackaged()` exists.
         *
         * On a developer's machine `base_path('.env')` is their real environment, full of their own
         * settings. A feature that remembers something must never reach into it, and the worst way
         * that could happen is a test creating a row while the suite runs on somebody's checkout. So
         * with no launcher-named file there is nothing to write to, and the write is refused rather
         * than aimed at whatever happens to be beside the code.
         *
         * The file is read to compare, never written. Reading a developer's `.env` is safe; touching
         * it is the thing being guarded against.
         */
        putenv('PAPYRUS_ENV');

        $env = app(EnvironmentFile::class);

        $this->assertFalse($env->isPackaged(), 'a checkout must not look like a packaged install');
        $this->assertSame(base_path('.env'), $env->path(), 'and must resolve to the conventional path');

        $checkout = base_path('.env');
        $before = is_file($checkout) ? (string) file_get_contents($checkout) : null;

        $this->assertFalse(
            app(LocalReaderAccount::class)->remember('someone@example.test'),
            'there is no install-owned env file to write, so nothing should have been written',
        );

        $after = is_file($checkout) ? (string) file_get_contents($checkout) : null;

        $this->assertSame(
            $before,
            $after,
            "a developer's own .env must never be written to, by a feature or by a test",
        );
    }

    // The claim ----------------------------------------------------------

    public function test_the_claim_is_refused_once_an_account_exists(): void
    {
        User::factory()->create();

        $this->assertFalse(app(Registration::class)->claim());
    }

    public function test_the_claim_is_granted_while_nobody_has_registered(): void
    {
        $this->assertTrue(app(Registration::class)->claim());
    }

    public function test_the_claim_is_refused_when_the_lock_is_already_held(): void
    {
        /*
         * The race this exists for. Two requests both read a count of zero and both proceed, and a
         * single-reader install ends up with two readers, which breaks the invariant the mode is named
         * for. Holding the lock is how the second one is turned away, so this asserts that holding it
         * actually turns it away rather than assuming the lock works.
         */
        $held = Cache::lock(Registration::LOCK, Registration::LOCK_SECONDS)->get();

        $this->assertTrue($held, 'the test did not get the lock, so it would prove nothing');
        $this->assertFalse(app(Registration::class)->claim());
    }

    public function test_the_claim_always_succeeds_in_a_mode_that_allows_registration(): void
    {
        Config::set('papyrus.deployment_mode', 'shared');

        /*
         * No lock is taken in a mode that permits registration, because the invariant being protected
         * does not exist there and serialising every sign-up would be a cost with no benefit.
         */
        $this->assertTrue(app(Registration::class)->claim());
    }
}
