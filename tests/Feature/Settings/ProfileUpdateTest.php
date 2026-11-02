<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Change the name and email address for this account')
            ->assertSee('Delete this account and its settings and reading activity');
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        // A second account, because the last account is kept rather than deleted.
        User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_the_only_account_cannot_be_deleted(): void
    {
        /*
         * Deleting the only account leaves zero users, and `Registration::isOpen()` reads
         * zero users on a single-reader install as an unclaimed install. That silently
         * reopened first-run sign-up and left the install with no owner. Refusing is the
         * rule: an install always keeps one account.
         */
        $user = User::factory()->create(['role' => User::ROLE_INSTALL_OWNER]);

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasErrors(['password'])
            ->assertSee('This is the only account.');

        $this->assertNotNull($user->fresh(), 'the only account has to survive the refusal');
        $this->assertTrue(auth()->check());

        // The consequence this rule exists to prevent: sign-up must stay closed.
        $this->get(route('register'))->assertNotFound();
    }

    public function test_deleting_one_of_two_accounts_leaves_registration_closed(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_INSTALL_OWNER]);
        $other = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh());
        $this->assertNotNull($other->fresh());

        // One account remains, so sign-up stays closed on this single-reader install.
        $this->get(route('register'))->assertNotFound();
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }

    public function test_account_deletion_names_what_it_removes_and_keeps(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.delete-user-modal')
            ->assertSee('This permanently removes this account and its saved settings and reading activity.')
            ->assertSee('It does not remove manga files stored by Papyrus.');
    }
}
