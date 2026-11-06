<?php

namespace Tests\Feature\Localization;

use App\Livewire\Onboarding\FirstRunTour;
use App\Models\User;
use App\Support\LocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_can_switch_and_the_session_keeps_it(): void
    {
        $response = $this->post(route('locale.update'), [
            'locale' => 'es',
            'return' => '/faq',
        ]);

        $response->assertRedirect('/faq');
        $response->assertSessionHas('locale', 'es');

        $this->assertDatabaseCount('user_settings', 0);
    }

    public function test_a_signed_in_reader_stores_the_choice(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('locale.update'), [
                'locale' => 'es',
                'return' => '/settings/appearance',
            ])
            ->assertRedirect('/settings/appearance');

        $this->assertSame('es', app(LocalePreference::class)->read($user));
        $this->assertDatabaseHas('user_settings', [
            'user_id' => $user->id,
            'key' => LocalePreference::KEY,
        ]);
    }

    public function test_an_unsupported_locale_is_rejected(): void
    {
        $this->from('/login')
            ->post(route('locale.update'), ['locale' => 'de'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('locale');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function uncataloguedLocales(): array
    {
        return [
            'French' => ['fr'],
            'Brazilian Portuguese' => ['pt_BR'],
        ];
    }

    #[DataProvider('uncataloguedLocales')]
    public function test_an_uncatalogued_locale_is_rejected(string $locale): void
    {
        $this->from('/login')
            ->post(route('locale.update'), ['locale' => $locale])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('locale');

        $this->assertDatabaseCount('user_settings', 0);
    }

    public function test_an_absolute_return_url_is_refused(): void
    {
        $this->post(route('locale.update'), [
            'locale' => 'es',
            'return' => 'https://example.test/elsewhere',
        ])->assertRedirect(route('home'));
    }

    public function test_a_protocol_relative_return_url_is_refused(): void
    {
        $this->post(route('locale.update'), [
            'locale' => 'es',
            'return' => '//example.test/elsewhere',
        ])->assertRedirect(route('home'));
    }

    public function test_an_encoded_backslash_return_url_is_refused(): void
    {
        $this->post(route('locale.update'), [
            'locale' => 'es',
            'return' => '/%5Cexample.test/elsewhere',
        ])->assertRedirect(route('home'));
    }

    public function test_logging_out_forgets_the_guest_locale(): void
    {
        $user = User::factory()->create();

        $this->withSession(['locale' => 'es']);
        $this->actingAs($user);

        Auth::logout();

        $this->assertNull(session('locale'));
    }

    public function test_the_appearance_page_and_the_shell_carry_the_switcher(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/settings/appearance')
            ->assertOk()
            ->assertSee('Choose the language Papyrus uses for you.')
            ->assertSee('aria-label="Language"', false);
    }

    public function test_the_first_tour_step_carries_the_switcher(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INSTALL_OWNER]);

        $this->actingAs($owner);

        Livewire::test(FirstRunTour::class, ['autoStart' => true, 'currentRoute' => 'library'])
            ->assertSeeHtml('aria-label="Language"')
            ->assertSeeHtml('onchange="this.form.submit()"');
    }

    public function test_a_stored_preference_beats_the_session_on_the_next_request(): void
    {
        $user = User::factory()->create();

        app(LocalePreference::class)->write($user, 'es');

        $this->actingAs($user)
            ->withSession(['locale' => 'en'])
            ->get('/settings/profile')
            ->assertOk();

        $this->assertSame('es', app()->getLocale());
    }
}
