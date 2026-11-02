<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Deployment;
use App\Support\EnvironmentFile;
use App\Support\LocalReaderAccount;
use App\Support\Registration;
use Illuminate\Contracts\Cache\LockProvider;
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

        $this->envPath = $this->temporaryFile('papyrus-env-', 'env');

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
            'password' => 'Correct-Horse-Battery-42',
            'password_confirmation' => 'Correct-Horse-Battery-42',
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
            'password' => 'Correct-Horse-Battery-42',
            'password_confirmation' => 'Correct-Horse-Battery-42',
        ])->assertSessionHasNoErrors();

        $text = $this->envText();

        $this->assertStringNotContainsString('Correct-Horse-Battery-42', $text);

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
            'password' => 'Correct-Horse-Battery-42',
            'password_confirmation' => 'Correct-Horse-Battery-42',
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

    // Is the account usable at all? -----------------------------------------

    /**
     * The one that was broken, measured rather than assumed.
     *
     * Signing up through the browser used to produce an account that was authenticated and then
     * redirected to `/email/verify` from every route: the library, the dashboard, every settings page
     * except the two deliberately exempt ones. The reader was signed in and could do nothing, on an
     * install with no mail transport, and nothing on screen said why.
     */
    public function test_a_reader_who_signs_up_can_actually_use_the_app(): void
    {
        $this->post(route('register.store'), [
            'name' => 'First Reader',
            'email' => 'first@example.test',
            'password' => 'Correct-Horse-Battery-42',
            'password_confirmation' => 'Correct-Horse-Battery-42',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();

        // The library is behind auth AND verified, so this is the route that matters.
        $this->get('/library')->assertOk();
    }

    public function test_a_first_run_account_is_verified_without_a_mail_transport(): void
    {
        $this->post(route('register.store'), [
            'name' => 'First Reader',
            'email' => 'first@example.test',
            'password' => 'Correct-Horse-Battery-42',
            'password_confirmation' => 'Correct-Horse-Battery-42',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(
            User::query()->where('email', 'first@example.test')->firstOrFail()->email_verified_at,
            'verification on a loopback install has nothing to verify against and no way to complete, '
                .'and leaving it pending locks the reader out of the whole app',
        );
    }

    /**
     * And the other direction, which is the reason the first one is allowed at all.
     *
     * In a mode other people can reach, an address nobody controls is exactly what you do not want on
     * your instance. Auto-verifying there would hand every stranger a confirmed account, so this
     * asserts it does not happen rather than leaving it to the reader of the code to notice.
     */
    public function test_an_account_in_a_reachable_mode_is_still_sent_for_verification(): void
    {
        Config::set('papyrus.deployment_mode', Deployment::SHARED);

        $this->post(route('register.store'), [
            'name' => 'Second Reader',
            'email' => 'second@example.test',
            'password' => 'Correct-Horse-Battery-42',
            'password_confirmation' => 'Correct-Horse-Battery-42',
        ])->assertSessionHasNoErrors();

        $this->assertNull(
            User::query()->where('email', 'second@example.test')->firstOrFail()->email_verified_at,
            'shared and public installs must not auto verify, or sign-up is an open door with a '
                .'confirmation stamp on it',
        );

        $this->get('/library')->assertRedirect(route('verification.notice'));
    }

    /**
     * The other half of the parity, and it is a separate test on purpose.
     *
     * The first version of this ran the command and then the browser in one test and failed, because
     * the command had already claimed the install and the browser sign-up was correctly refused. The
     * failure was the sign-up window doing its job; the test was wrong to assume both could run at
     * once.
     *
     * `users:create` has always marked its accounts verified, for the same reason the browser path now
     * does: the address was just invented, an unverified account cannot sign in, and this box may have
     * no mail transport. Two ways of making one account disagreed about whether it worked.
     */
    public function test_the_command_also_makes_a_verified_account(): void
    {
        $this->artisan('users:create', [
            '--email' => 'made@example.test',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'made@example.test')->firstOrFail();

        $this->assertNotNull($user->email_verified_at);
    }

    /**
     * And the command claiming the install closes the browser sign-up, which is the window working.
     */
    public function test_the_command_claiming_the_install_closes_the_browser_sign_up(): void
    {
        $this->artisan('users:create', [
            '--email' => 'made@example.test',
        ])->assertSuccessful();

        $this->get(route('register'))->assertNotFound();
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

    public function test_a_second_claim_cannot_win_while_the_first_request_holds_it(): void
    {
        /*
         * The other half of the race above. `claim()` used to release the lock as soon as it saw zero
         * users, so a second request could take the same claim before the first had written its
         * account, and both would create one. The claim now stays held until the request that took it
         * releases it after the account exists.
         *
         * A second request is modelled with a second instance, because `Registration` is scoped and
         * `app()` hands the same instance back inside one request.
         */
        $first = app(Registration::class);

        $this->assertTrue($first->claim(), 'the first request wins the claim');

        $second = new Registration(app(Deployment::class), app(LockProvider::class));

        $this->assertFalse(
            $second->claim(),
            'a second request must not win a claim the first is still holding',
        );

        $first->releaseClaim();

        $this->assertTrue(
            $second->claim(),
            'a claim released without an account leaves the install claimable',
        );

        $second->releaseClaim();
    }

    public function test_the_claim_is_released_when_the_registration_request_finishes_without_an_account(): void
    {
        /*
         * The lock is held across the whole POST, so a refused sign-up has to give it back. Without
         * the release, one mistyped password would leave the install unable to register until the
         * fifteen second lock expired, and a crash would look like an install nobody can claim.
         */
        $this->post(route('register.store'), [
            'name' => 'First Reader',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors();

        $this->assertSame(0, User::query()->count());

        $claim = new Registration(app(Deployment::class), app(LockProvider::class));

        $this->assertTrue($claim->claim(), 'a refused sign-up must not leave the install unclaimable');

        $claim->releaseClaim();
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
