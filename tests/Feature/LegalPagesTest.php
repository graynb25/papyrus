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
 * FAQ have to carry the same three, so the test reads both and compares them
 * rather than trusting that nobody copied the list twice.
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
            ->assertSee('This application collects nothing and reports nothing.')
            ->assertSee('What the application collects')
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
            ->assertSee('What the application collects')
            ->assertSee('Your rights, and what they mean here');

        Livewire::actingAs($user)->test('pages::settings.terms')
            ->assertOk()
            ->assertSee('What you are responsible for');
    }

    public function test_the_privacy_page_names_the_three_things_that_reach_the_internet(): void
    {
        $this->get('/privacy')
            ->assertSee('AniList, if you connect your AniList account in Settings')
            ->assertSee('The manga source, when you download or read a chapter')
            ->assertSee('The extension repository, when you install a source or a store');
    }

    public function test_the_privacy_page_and_the_faq_carry_the_same_three_things(): void
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

        $this->assertCount(3, $faq);
        $this->assertCount(3, app(Outbound::class)->routes());
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

        $this->assertStringContainsString('collects nothing', $legal->privacy()->promise);
        $this->assertStringContainsString('own machine', $legal->terms()->promise);
    }
}
