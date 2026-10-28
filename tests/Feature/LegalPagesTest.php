<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Faq;
use App\Support\Legal;
use App\Support\Outbound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The privacy page and the terms of service page, in both their seats.
 *
 * ## What is held here
 *
 * Three things, and the third is the one that matters. The pages have to
 * render in the two seats, because a page that renders in one and not the
 * other is the failure the FAQ's public page records.
 *
 * The claims have to be the ones the code supports, because a privacy
 * statement is the easiest thing in a program to let rot. The list of what
 * reaches the internet is the sharpest of them, and the privacy page and the
 * FAQ have to carry the same five connections, so the test reads both and
 * compares them rather than trusting that nobody copied the list twice.
 *
 * And the copy has to carry no em or en dashes, because this copy ships to
 * readers, the same rule the FAQ's own dash check holds.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_privacy_page_renders_for_a_reader_who_has_not_signed_in(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Privacy')
            ->assertSee('Papyrus keeps what it knows in files on this computer and reports nothing to anyone.')
            ->assertSee('What the application keeps')
            ->assertSee('What reaches the internet')
            ->assertSee('Cookies')
            ->assertSee('Your rights, and what they mean here');
    }

    public function test_the_public_terms_page_renders_for_a_reader_who_has_not_signed_in(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertSee('Terms of service')
            ->assertSee('You run this on your own machine.')
            ->assertSee('What you are responsible for')
            ->assertSee('What the software does not promise');
    }

    public function test_the_in_shell_seats_render_for_a_signed_in_reader(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::settings.privacy')
            ->assertOk()
            ->assertSee('What the application keeps')
            ->assertSee('Cookies')
            ->assertSee('Your rights, and what they mean here');

        Livewire::actingAs($user)->test('pages::settings.terms')
            ->assertOk()
            ->assertSee('What you are responsible for');
    }

    public function test_the_privacy_page_names_the_five_connections_that_can_leave(): void
    {
        $this->get('/privacy')
            ->assertSee('AniList title matching, when you turn it on in Search')
            ->assertSee('AniList, if you connect your AniList account in Settings')
            ->assertSee('The manga source, when you download or read a chapter')
            ->assertSee('The extension repository, when you install a source or a store')
            ->assertSee('The mail server, when you sign up or ask for a password reset on a shared or public install');
    }

    public function test_the_privacy_page_and_the_faq_carry_the_same_five_connections(): void
    {
        /*
         * The one list, read from both places.
         *
         * A second copy of the list of what leaves the machine is a copy
         * that can drift from the machine, and a privacy page that disagrees
         * with the FAQ about it is the worst place for that to happen. So
         * the test reads both and compares them, rather than trusting that
         * nobody pasted the list twice.
         */
        $privacy = $this->get('/privacy')->getContent();
        $faq = app(Faq::class)->outbound();

        foreach ($faq as $route) {
            $this->assertStringContainsString($route['what'], $privacy);
        }

        $this->assertCount(5, $faq);
        $this->assertCount(5, app(Outbound::class)->routes());
    }

    public function test_the_privacy_page_says_the_application_reports_nothing(): void
    {
        $this->get('/privacy')
            ->assertSee('There is no telemetry, no analytics, no crash reporting and no error reporting service in it')
            ->assertSee('no phone-home check');
    }

    public function test_the_privacy_page_names_the_laws_without_claiming_a_legal_instrument(): void
    {
        $privacy = $this->get('/privacy')->getContent();

        $this->assertStringContainsString('GDPR', $privacy);
        $this->assertStringContainsString('CCPA', $privacy);

        /*
         * No article numbers.
         *
         * The owner's answer on 2026-10-21 was the plain language shape: the
         * laws are named so a reader who is looking for them finds them, and
         * no article or section is mapped out, because a page that cannot
         * determine where its reader lives cannot honestly map itself onto
         * an instrument written for somebody who can.
         */
        $this->assertDoesNotMatchRegularExpression('/Article\s+\d+/i', $privacy);
        $this->assertDoesNotMatchRegularExpression('/Section\s+\d+/i', $privacy);
        $this->assertDoesNotMatchRegularExpression('/§\s*\d+/', $privacy);
    }

    public function test_the_privacy_page_is_honest_about_mail(): void
    {
        $this->get('/privacy')
            ->assertSee('Mail, and when it is sent')
            ->assertSee('A default install sends no mail')
            ->assertSee('There is no welcome message')
            ->assertSee('Papyrus itself runs no mail service')
            ->assertDontSee('a welcome note if your account was made from the command line');
    }

    /**
     * The cookies a guest actually receives, against the cookies the page names.
     *
     * The page used to say no cookie existed. The fix is not a sentence that
     * admits cookies exist; it is the names and flags of the cookies a reader
     * will find in their browser. A guest response carries both the session
     * and the form token, so it is the one that can be measured without
     * signing in.
     */
    public function test_the_privacy_page_names_the_cookies_a_guest_actually_receives(): void
    {
        $response = $this->get('/privacy');

        $response->assertOk()
            ->assertSee('Cookies')
            ->assertSee('papyrus-session')
            ->assertSee('XSRF-TOKEN')
            ->assertSee('remember_web_')
            ->assertSee('HTTP-only')
            ->assertSee('SameSite=Lax')
            ->assertSee('120 minutes')
            ->assertSee('400 days')
            ->assertSee('None of these is marked Secure on the default install');

        $this->assertSame('papyrus-session', config('session.cookie'));

        $cookies = [];

        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        $this->assertEqualsCanonicalizing(
            ['papyrus-session', 'XSRF-TOKEN'],
            array_keys($cookies),
            'a guest page sets the two cookies the page names, and no others',
        );

        $this->assertArrayHasKey('papyrus-session', $cookies, 'a guest page starts a session, so the session cookie must be on the response');
        $this->assertTrue($cookies['papyrus-session']->isHttpOnly());
        $this->assertSame('lax', $cookies['papyrus-session']->getSameSite());
        $this->assertFalse($cookies['papyrus-session']->isSecure());

        $this->assertArrayHasKey('XSRF-TOKEN', $cookies, 'the form token cookie is what makes the CSRF check work');
        $this->assertFalse($cookies['XSRF-TOKEN']->isHttpOnly(), 'the page script has to read this one');
        $this->assertSame('lax', $cookies['XSRF-TOKEN']->getSameSite());
        $this->assertFalse($cookies['XSRF-TOKEN']->isSecure());

        foreach (array_keys($cookies) as $name) {
            $this->assertStringStartsNotWith('remember_web_', $name, 'a guest has not ticked Remember me, so the recaller cookie must not be set');
        }
    }

    /**
     * The optional recaller cookie, measured on the response that sets it.
     *
     * The privacy page names it as optional and gives its flags. A page that
     * names a cookie the sign in does not set, or gives it flags it does not
     * have, would be describing a browser state that never happens.
     */
    public function test_the_privacy_page_describes_the_remember_cookie_the_sign_in_sets(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));

        $recaller = null;

        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'remember_web_')) {
                $recaller = $cookie;
            }
        }

        $this->assertNotNull($recaller, 'ticking Remember me has to set the recaller cookie the privacy page names');
        $this->assertTrue($recaller->isHttpOnly());
        $this->assertSame('lax', $recaller->getSameSite());
        $this->assertFalse($recaller->isSecure());
    }

    public function test_the_public_pages_carry_no_dashes(): void
    {
        foreach (['/privacy', '/terms'] as $url) {
            $content = $this->get($url)->getContent();

            foreach (["\u{2014}", "\u{2013}"] as $dash) {
                $this->assertStringNotContainsString(
                    $dash,
                    $content,
                    "The page at {$url} carries a dash.",
                );
            }
        }
    }

    public function test_the_settings_navlist_offers_both_pages(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('pages::settings.profile')
            ->assertSee('Privacy')
            ->assertSee('Terms');
    }

    public function test_the_auth_footer_offers_both_pages(): void
    {
        $this->get('/login')
            ->assertSee(route('privacy'))
            ->assertSee(route('terms'));
    }

    public function test_the_two_documents_carry_their_own_promise(): void
    {
        $legal = app(Legal::class);

        $this->assertStringContainsString('reports nothing to anyone', $legal->privacy()->promise);
        $this->assertStringContainsString('files on this computer', $legal->privacy()->promise);
        $this->assertStringContainsString('own machine', $legal->terms()->promise);
    }
}
