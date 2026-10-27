<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The install owner, and the readers who are not.
 *
 * ## Why this file exists
 *
 * Every account the web form made was a reader, including the first one. The role column
 * existed and `users:create --admin` could write it, but nothing in the browser path could,
 * so a fresh install had no account that could manage the install at all. The gate this
 * file exercises was also absent, which meant a future owner check had nothing to ask.
 *
 * The tests go through the registration route rather than calling `CreateNewUser` directly,
 * because the property under test is what a web request is able to do. A role that a posted
 * field can choose is an escalation however the action is written.
 */
class InstallOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    private function inMode(string $mode): void
    {
        config(['papyrus.deployment_mode' => $mode]);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function signUp(string $name, string $email, array $extra = []): array
    {
        return [
            'name' => $name,
            'email' => $email,
            'password' => 'Correct-Horse-Battery-42',
            'password_confirmation' => 'Correct-Horse-Battery-42',
            ...$extra,
        ];
    }

    /**
     * Sign up two accounts through the real form, with a sign-out between them.
     *
     * @return array{0: User, 1: User}
     */
    private function registerTwoAccounts(string $mode): array
    {
        $this->inMode($mode);

        $this->post(route('register.store'), $this->signUp('First Reader', 'first@example.test'))
            ->assertSessionHasNoErrors();

        $this->post(route('logout'));

        $this->post(route('register.store'), $this->signUp('Second Reader', 'second@example.test'))
            ->assertSessionHasNoErrors();

        return [
            User::query()->where('email', 'first@example.test')->firstOrFail(),
            User::query()->where('email', 'second@example.test')->firstOrFail(),
        ];
    }

    private function runOwnerBackfillMigration(): void
    {
        $migration = require database_path('migrations/2026_10_25_000000_promote_first_user_to_install_owner.php');

        $migration->up();
    }

    // The first account owns the install ---------------------------------

    public function test_the_first_web_account_is_the_install_owner(): void
    {
        $this->inMode(Deployment::SINGLE);

        $this->post(route('register.store'), $this->signUp('First Reader', 'first@example.test'))
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'first@example.test')->firstOrFail();

        $this->assertSame(
            'admin',
            $user->role,
            'the first account must own the install, or no account can ever manage it',
        );
        $this->assertTrue($user->isInstallOwner());
        $this->assertTrue(
            auth()->user()->isInstallOwner(),
            'the account the request signs in must carry the role the request just wrote',
        );
    }

    public function test_a_fresh_single_mode_install_has_exactly_one_owner(): void
    {
        $this->inMode(Deployment::SINGLE);

        $this->post(route('register.store'), $this->signUp('First Reader', 'first@example.test'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
    }

    public function test_a_second_account_is_a_reader(): void
    {
        [$owner, $reader] = $this->registerTwoAccounts(Deployment::SHARED);

        $this->assertTrue($owner->isInstallOwner());
        $this->assertFalse($reader->isInstallOwner());
        $this->assertSame('user', $reader->role);
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
    }

    public function test_a_shared_install_keeps_one_owner_when_more_readers_join(): void
    {
        [$owner] = $this->registerTwoAccounts(Deployment::SHARED);

        $this->post(route('logout'));

        $this->post(route('register.store'), $this->signUp('Third Reader', 'third@example.test'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($owner->fresh()->isInstallOwner());
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
        $this->assertSame(2, User::query()->where('role', 'user')->count());
    }

    public function test_a_public_install_makes_the_first_account_the_owner_and_the_second_a_reader(): void
    {
        [$owner, $reader] = $this->registerTwoAccounts(Deployment::PUBLIC);

        $this->assertTrue($owner->isInstallOwner());
        $this->assertFalse($reader->isInstallOwner());
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
    }

    // A request cannot choose its role -----------------------------------

    public function test_a_posted_role_field_is_ignored(): void
    {
        $this->inMode(Deployment::SHARED);

        /*
         * Both directions. A first sign-up that asks to be a reader still owns the install,
         * because the install would otherwise have no owner at all. A second sign-up that
         * asks to be an owner stays a reader, which is the escalation being refused.
         */
        $this->post(route('register.store'), $this->signUp('First Reader', 'first@example.test', ['role' => 'user']))
            ->assertSessionHasNoErrors();

        $this->post(route('logout'));

        $this->post(route('register.store'), $this->signUp('Second Reader', 'second@example.test', ['role' => 'admin']))
            ->assertSessionHasNoErrors();

        $this->assertSame('admin', User::query()->where('email', 'first@example.test')->firstOrFail()->role);
        $this->assertSame('user', User::query()->where('email', 'second@example.test')->firstOrFail()->role);
    }

    public function test_a_profile_save_keeps_the_role(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);

        $this->actingAs($owner);

        Livewire::test('pages::settings.profile')
            ->set('name', 'Renamed Owner')
            ->set('email', $owner->email)
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $owner->refresh();

        $this->assertSame('admin', $owner->role);
        $this->assertTrue($owner->isInstallOwner());
    }

    // The ability --------------------------------------------------------

    public function test_the_gate_allows_the_owner_and_denies_a_reader(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $reader = User::factory()->create();

        $this->assertFalse(Gate::allows('manage-install'), 'an install with nobody signed in has no owner to allow');

        $this->assertTrue(Gate::forUser($owner)->allows('manage-install'));
        $this->assertFalse(Gate::forUser($reader)->allows('manage-install'));
    }

    // An existing install is backfilled ----------------------------------

    public function test_the_migration_backfills_the_earliest_account_when_no_owner_exists(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertSame(0, User::query()->where('role', 'admin')->count());

        $this->runOwnerBackfillMigration();

        $this->assertSame('admin', $first->refresh()->role);
        $this->assertSame('user', $second->refresh()->role);
        $this->assertSame(2, User::query()->count(), 'promoting an account must not add or remove readers');

        /*
         * Run it again, because a migration that is not idempotent is a migration that
         * changes the install a second time when it is replayed.
         */
        $this->runOwnerBackfillMigration();

        $this->assertSame('admin', $first->refresh()->role);
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
    }

    public function test_the_migration_leaves_an_existing_owner_alone(): void
    {
        $earliest = User::factory()->create();
        $laterOwner = User::factory()->create(['role' => 'admin']);

        $this->runOwnerBackfillMigration();

        $this->assertSame(
            'user',
            $earliest->refresh()->role,
            'the migration must not move ownership to the earliest account when an owner already exists',
        );
        $this->assertSame('admin', $laterOwner->refresh()->role);
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
    }
}
