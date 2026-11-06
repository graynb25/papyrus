<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use App\Support\Locale;
use App\Support\LocalePreference;
use App\Support\UserPreference;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LocaleResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stored_user_preference_wins_over_the_session(): void
    {
        $user = User::factory()->create();

        app(LocalePreference::class)->write($user, 'es');

        $request = $this->request();
        $request->session()->put('locale', 'en');

        $this->assertSame('es', Locale::resolve($request, $user));
    }

    public function test_the_session_wins_over_the_browser_header(): void
    {
        $request = $this->request('en-US,en;q=0.9');
        $request->session()->put('locale', 'es');

        $this->assertSame('es', Locale::resolve($request));
    }

    public function test_the_browser_header_is_used_when_nothing_else_is_set(): void
    {
        $request = $this->request('es-419,es;q=0.9,en;q=0.8');

        $this->assertSame('es', Locale::resolve($request));
    }

    public function test_the_configured_locale_is_used_when_nothing_else_is_set(): void
    {
        config(['app.locale' => 'es']);

        $request = $this->request('de-DE,de;q=0.9');

        $this->assertSame('es', Locale::resolve($request));
    }

    public function test_english_is_the_final_fallback(): void
    {
        config(['app.locale' => 'de']);

        $request = $this->request('de-DE,de;q=0.9');

        $this->assertSame('en', Locale::resolve($request));
    }

    public function test_an_uncatalogued_app_locale_resolves_to_english(): void
    {
        config(['app.locale' => 'fr']);

        $request = $this->request('de-DE,de;q=0.9');

        $this->assertSame('en', Locale::resolve($request));
    }

    public function test_an_uncatalogued_fallback_resolves_to_english(): void
    {
        config([
            'app.locale' => 'fr',
            'papyrus.locales.fallback' => 'fr',
        ]);

        $request = $this->request('de-DE,de;q=0.9');

        $this->assertSame('en', Locale::resolve($request));
        $this->assertSame('en', Locale::fallback());
    }

    public function test_an_uncatalogued_stored_locale_is_ignored(): void
    {
        $user = User::factory()->create();

        app(UserPreference::class)->writeString($user, LocalePreference::KEY, 'fr');

        $request = $this->request();
        $request->session()->put('locale', 'en');

        $this->assertSame('en', Locale::resolve($request, $user));
    }

    public function test_an_uncatalogued_session_locale_falls_through(): void
    {
        $request = $this->request('es-ES,es;q=0.9');
        $request->session()->put('locale', 'fr');

        $this->assertSame('es', Locale::resolve($request));
    }

    public function test_an_unsupported_stored_value_is_ignored(): void
    {
        $user = User::factory()->create();

        app(UserPreference::class)->writeString($user, LocalePreference::KEY, 'de');

        $request = $this->request();
        $request->session()->put('locale', 'en');

        $this->assertSame('en', Locale::resolve($request, $user));
    }

    public function test_an_uncatalogued_session_locale_does_not_set_the_html_language_attribute(): void
    {
        $this->withSession(['locale' => 'pt_BR'])
            ->get('/login')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertDontSee('lang="pt-BR"', false);
    }

    public function test_the_spanish_catalog_reaches_the_login_page(): void
    {
        $this->withSession(['locale' => 'es'])
            ->get('/login')
            ->assertOk()
            ->assertSee('Idioma')
            ->assertSee('Cambiar idioma');
    }

    public function test_the_switcher_offers_only_catalogued_locales(): void
    {
        $response = $this->withSession(['locale' => 'en'])->get('/login')->assertOk();

        $response->assertSee('<option value="en"', false)
            ->assertSee('<option value="es"', false)
            ->assertDontSee('<option value="fr"', false)
            ->assertDontSee('<option value="pt_BR"', false)
            ->assertDontSee('compact="compact"', false)
            ->assertDontSee('onchange="this.form.submit()"', false);
    }

    public function test_carbon_and_number_follow_the_request_locale_and_reset_for_english(): void
    {
        $this->withSession(['locale' => 'es'])->get('/login')->assertOk();

        $this->assertSame('es', app()->getLocale());
        $this->assertSame('enero', CarbonImmutable::create(2026, 1, 1)->translatedFormat('F'));
        $this->assertSame('enero', Carbon::create(2026, 1, 1)->translatedFormat('F'));
        $this->assertSame('1,5', Number::format(1.5));

        $this->withSession(['locale' => 'en'])->get('/login')->assertOk();

        $this->assertSame('en', app()->getLocale());
        $this->assertSame('January', CarbonImmutable::create(2026, 1, 1)->translatedFormat('F'));
        $this->assertSame('January', Carbon::create(2026, 1, 1)->translatedFormat('F'));
        $this->assertSame('1.5', Number::format(1.5));
    }

    public function test_a_419_response_uses_the_session_locale(): void
    {
        $request = $this->request();
        $request->session()->put('locale', 'es');

        $this->app->instance('request', $request);

        $response = app(ExceptionHandler::class)->render($request, new HttpException(419));

        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame('es', app()->getLocale());
        $this->assertStringContainsString('lang="es"', (string) $response->getContent());
        $this->assertStringContainsString('La página expiró', (string) $response->getContent());
    }

    private function request(string $acceptLanguage = 'en-US,en;q=0.9'): Request
    {
        $request = Request::create('/login', 'GET');
        $request->headers->set('Accept-Language', $acceptLanguage);
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }
}
