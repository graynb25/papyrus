<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `users:create`, the only way to make a first account on a single-user install.
 *
 * Registration is closed there by design, which left a fresh install with no way to make
 * its first account at all. These hold the command honest about the two things it has to get
 * right: the credentials it writes have to work, and it has to follow the same owner rule as
 * the browser while still honouring an explicit `--admin`.
 */
class UsersCreateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_account_that_can_sign_in(): void
    {
        $this->artisan('users:create', [
            '--email' => 'first@example.test',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'first@example.test')->firstOrFail();

        /*
         * The password comes back off the command's own output, because it is generated
         * and shown once. Reading it out of the test output rather than reaching into the
         * command keeps the test honest about where the reader gets it.
         */
        $this->artisan('users:create', [
            '--email' => 'second@example.test',
            '--password' => 'correct-horse-battery',
        ])->assertSuccessful();

        $second = User::query()->where('email', 'second@example.test')->firstOrFail();

        $this->assertTrue(Hash::check('correct-horse-battery', $second->password));
    }

    public function test_an_account_is_marked_verified(): void
    {
        /*
         * The address was just invented, and an unverified account cannot sign in, so
         * leaving verification pending would hand back an account that looks created and
         * is not usable.
         */
        $this->artisan('users:create', [
            '--email' => 'first@example.test',
        ])->assertSuccessful();

        $this->assertNotNull(User::query()->where('email', 'first@example.test')->firstOrFail()->email_verified_at);
    }

    public function test_the_name_defaults_to_the_address_local_part(): void
    {
        // The whole address wraps in the header, so the local part is the better default.
        $this->artisan('users:create', [
            '--email' => 'someone@example.test',
        ])->assertSuccessful();

        $this->assertSame('someone', User::query()->where('email', 'someone@example.test')->firstOrFail()->name);
    }

    public function test_an_explicit_name_wins(): void
    {
        $this->artisan('users:create', [
            '--email' => 'someone@example.test',
            '--name' => 'Actual Name',
        ])->assertSuccessful();

        $this->assertSame('Actual Name', User::query()->where('email', 'someone@example.test')->firstOrFail()->name);
    }

    public function test_the_password_is_hashed_exactly_once(): void
    {
        // The model casts with 'hashed', so hashing in the command as well would produce a
        // hash of a hash and lock the account out permanently.
        $this->artisan('users:create', [
            '--email' => 'first@example.test',
            '--password' => 'correct-horse-battery',
        ])->assertSuccessful();

        $stored = User::query()->where('email', 'first@example.test')->firstOrFail()->password;

        $this->assertTrue(Hash::check('correct-horse-battery', $stored));
        $this->assertFalse(Hash::check($stored, $stored), 'A double hash would verify against itself.');
    }

    public function test_the_role_is_set_explicitly_rather_than_mass_assigned(): void
    {
        /*
         * `role` is absent from the model's fillable list on purpose, so passing it to
         * create() is dropped and the column default applies. The first version of this
         * command did exactly that and `--admin` silently produced a reader.
         */
        $this->artisan('users:create', [
            '--email' => 'boss@example.test',
            '--admin' => true,
        ])->assertSuccessful();

        $this->assertSame('admin', User::query()->where('email', 'boss@example.test')->firstOrFail()->role);
    }

    public function test_the_admin_flag_creates_an_owner_even_when_one_exists(): void
    {
        $this->artisan('users:create', ['--email' => 'first@example.test'])->assertSuccessful();
        $this->artisan('users:create', [
            '--email' => 'second@example.test',
            '--admin' => true,
        ])->assertSuccessful();

        $this->assertSame('admin', User::query()->where('email', 'first@example.test')->firstOrFail()->role);
        $this->assertSame('admin', User::query()->where('email', 'second@example.test')->firstOrFail()->role);
    }

    public function test_the_first_account_is_the_owner_without_the_admin_flag(): void
    {
        /*
         * The documented install path does not pass `--admin`. If that path leaves the
         * install with no owner, the owner-only surfaces have no account that can open them.
         */
        $this->artisan('users:create', ['--email' => 'first@example.test'])->assertSuccessful();

        $user = User::query()->where('email', 'first@example.test')->firstOrFail();

        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->isInstallOwner());
    }

    public function test_a_later_account_is_a_reader_without_the_admin_flag(): void
    {
        $this->artisan('users:create', ['--email' => 'first@example.test'])->assertSuccessful();
        $this->artisan('users:create', ['--email' => 'second@example.test'])->assertSuccessful();

        $this->assertSame('admin', User::query()->where('email', 'first@example.test')->firstOrFail()->role);
        $this->assertSame('user', User::query()->where('email', 'second@example.test')->firstOrFail()->role);
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
    }

    public function test_the_command_promotes_the_earliest_account_when_the_install_has_no_owner(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertSame(0, User::query()->where('role', 'admin')->count());

        $this->artisan('users:create', ['--email' => 'third@example.test'])->assertSuccessful();

        $this->assertSame('admin', $first->refresh()->role);
        $this->assertSame('user', $second->refresh()->role);
        $this->assertSame('user', User::query()->where('email', 'third@example.test')->firstOrFail()->role);
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
    }

    // It refuses rather than half-succeeding ------------------------------

    public function test_it_refuses_without_an_email(): void
    {
        $this->artisan('users:create')->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_refuses_something_that_is_not_an_email(): void
    {
        /*
         * This is the exact mistake the command exists to prevent. `papyrus` is the
         * Suwayomi username and it is not an address, so it has to be refused here rather
         * than becoming an account nobody can reach.
         */
        $this->artisan('users:create', ['--email' => 'papyrus'])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_refuses_a_duplicate_address(): void
    {
        $this->artisan('users:create', ['--email' => 'first@example.test'])->assertSuccessful();
        $this->artisan('users:create', ['--email' => 'first@example.test'])->assertFailed();

        $this->assertSame(1, User::query()->where('email', 'first@example.test')->count());
    }

    public function test_it_refuses_a_password_the_sign_up_form_would_reject(): void
    {
        /*
         * A command that accepts a password the app would refuse is a way to lock yourself
         * out of your own install, so it runs the same validator the form does.
         */
        $this->artisan('users:create', [
            '--email' => 'first@example.test',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_warns_when_the_password_came_from_the_command_line(): void
    {
        $this->artisan('users:create', [
            '--email' => 'first@example.test',
            '--password' => 'correct-horse-battery',
        ])
            ->expectsOutputToContain('shell history')
            ->assertSuccessful();
    }

    public function test_the_generated_password_is_shown_once_and_long_enough(): void
    {
        $this->artisan('users:create', ['--email' => 'first@example.test'])
            ->expectsOutputToContain('shown once')
            ->assertSuccessful();
    }
}
