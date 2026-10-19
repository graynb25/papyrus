<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Faq;
use App\Support\FaqEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public FAQ, and whether the promises on it are still true.
 *
 * ## Why this file tests prose
 *
 * Because these are the product's most consequential claims and there is no other way to hold them.
 * "Nothing leaves your computer" is a sentence a future change can quietly falsify: someone adds an
 * analytics script, or a source gets a callback, or an exception list loses an entry, and no test in
 * the suite notices because a paragraph still renders.
 *
 * The tests below assert on the claims rather than on the markup, which is the only version of this
 * that is worth writing. A test asserting that the word "AniList" appears would pass just as happily
 * against a page that has stopped mentioning it in the exception list, which is the failure that
 * matters.
 */
class FaqTest extends TestCase
{
    use RefreshDatabase;

    private function faq(): Faq
    {
        return app(Faq::class);
    }

    // The page itself ----------------------------------------------------

    public function test_the_page_is_reachable_without_an_account(): void
    {
        $this->get(route('faq'))
            ->assertOk()
            ->assertSee('Does anything leave my computer?');
    }

    /**
     * Public means public. A reader who has not installed anything yet, or has not made an account,
     * is exactly the reader who most needs to read this.
     */
    public function test_a_signed_in_reader_can_reach_it_too(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('faq'))
            ->assertOk();
    }

    /**
     * The sign-in screen links here, because that is where somebody who has just installed Papyrus
     * and does not trust it yet actually is.
     */
    public function test_the_login_page_offers_a_way_to_read_it(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('faq', escape: false);
    }

    public function test_settings_offers_it_as_well(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(route('faq'), escape: false);
    }

    // The privacy claim --------------------------------------------------

    /**
     * The headline promise, asserted as being present rather than as being worded nicely.
     */
    public function test_it_states_that_nothing_is_reported(): void
    {
        $privacy = $this->faq()->privacy();

        $this->assertTrue($privacy->isPrivacy);

        $text = implode(' ', $privacy->answer);

        foreach (['telemetry', 'analytics', 'crash reporting'] as $claim) {
            $this->assertStringContainsStringIgnoringCase(
                $claim,
                $text,
                "the page claims no {$claim}, so it should say so",
            );
        }
    }

    /**
     * The one that matters most, and the one the first draft got wrong.
     *
     * A privacy page that says nothing leaves your machine is false in three specific ways. Each of
     * them is the reader's own doing, and each of them has to be named, because a reader deciding
     * whether to trust a self-hosted app is entitled to know exactly where the trust stops.
     */
    public function test_it_names_every_thing_that_does_contact_the_internet(): void
    {
        $outbound = $this->faq()->outbound();

        $this->assertCount(3, $outbound, 'three things genuinely reach the internet, and the count is the claim');

        $privacy = $this->faq()->privacy();

        $this->assertFalse(
            $privacy->isExceptionless(),
            'a privacy answer with no exceptions is the failure this page exists to prevent',
        );

        foreach ($outbound as $route) {
            $this->assertNotSame('', trim($route['who']), 'an exception with no explanation is worse than none');
            $this->assertContains(
                $route['who'],
                $privacy->exceptions,
                'every outbound route must appear in the privacy answer, or the reader cannot see where the trust stops',
            );
        }
    }

    /**
     * Each exception has to say who started it. "Your data may be sent" is not information; "you start
     * this yourself, and here is how to stop it" is.
     */
    public function test_each_exception_says_the_reader_started_it(): void
    {
        foreach ($this->faq()->outbound() as $route) {
            $this->assertMatchesRegularExpression(
                '/you|your/i',
                $route['who'],
                "an exception must say the reader initiated it: {$route['what']}",
            );
        }
    }

    /**
     * A source is where the privacy caveat bites hardest, so the page has to explain what one is
     * rather than leave the reader to find out by installing one.
     */
    public function test_it_explains_what_a_source_is(): void
    {
        $questions = array_map(static fn (FaqEntry $e): string => $e->question, $this->faq()->entries());

        $this->assertNotEmpty(
            array_filter($questions, static fn (string $q): bool => str_contains(strtolower($q), 'source')),
            'the least Papyrus-controlled part of the stack is the one a reader is most entitled to be warned about',
        );
    }

    /**
     * A guard on the headline entry being reworded or reordered into somewhere it does not belong.
     */
    public function test_the_privacy_question_is_the_first_one(): void
    {
        $this->assertTrue(
            $this->faq()->entries()[0]->isPrivacy,
            'the privacy answer is why the page exists, so it must not sink below questions about importing',
        );
    }

    /**
     * Each question opens its own answer in place, as a disclosure. A page that renders every
     * answer at once is a wall, and one were the only link to the FAQ is a dim sentence inside
     * another sentence is what the owner described as amateurish. The count ties the markup to
     * the content, so a ninth question with no disclosure fails here rather than silently.
     */
    public function test_every_question_renders_as_a_disclosure(): void
    {
        $response = $this->get(route('faq'));
        $response->assertOk();

        $this->assertSame(
            $this->faq()->totalCount(),
            substr_count((string) $response->getContent(), '<details'),
            'every question renders as its own disclosure, or the wall of prose is back',
        );
    }

    /**
     * The bundled manga server re-reads the index of every registered store when it starts up,
     * on its own, which the server's own log carries. A future wording that narrows the third
     * exception back to "only when you install one" would state something the server falsifies,
     * so the sentence that says it is held by a test like any other claim.
     */
    public function test_the_store_exception_names_the_start_up_re_read(): void
    {
        foreach ($this->faq()->outbound() as $route) {
            if (str_contains($route['what'], 'repository')) {
                $this->assertStringContainsStringIgnoringCase(
                    'starts up',
                    $route['who'],
                    "the server re-reads store indexes at start up, so the store exception has to say so: {$route['what']}",
                );

                return;
            }
        }

        $this->fail('the extension repository is one of the three outbound routes');
    }

    // The rest of the content -------------------------------------------

    public function test_it_answers_the_questions_a_reader_actually_has(): void
    {
        $questions = implode(' ', array_map(
            static fn (FaqEntry $e): string => $e->question,
            $this->faq()->entries(),
        ));

        foreach ([
            'account',
            'library',
            'network',
            'offline',
            'open source',
            'backup',
        ] as $subject) {
            $this->assertStringContainsStringIgnoringCase(
                $subject,
                $questions,
                "a reader asks about {$subject} and the page should have an answer",
            );
        }
    }

    /**
     * Where the data actually lives, which the page states as a fact rather than implying.
     *
     * This is the claim most likely to rot silently: if the data directory moves, the sentence in the
     * view is the only thing that notices, and only if somebody re-reads it.
     */
    public function test_it_names_where_the_library_is_kept(): void
    {
        $answers = implode(' ', array_map(
            static fn (FaqEntry $e): string => implode(' ', $e->answer),
            $this->faq()->entries(),
        ));

        $this->assertStringContainsStringIgnoringCase(
            'Papyrus',
            $answers,
            'the answer about where data lives should name the folder',
        );
    }

    public function test_every_entry_has_a_question_and_an_answer(): void
    {
        foreach ($this->faq()->entries() as $entry) {
            $this->assertNotSame('', trim($entry->question));
            $this->assertNotEmpty($entry->answer, "{$entry->question} has no answer");
        }

        $this->assertGreaterThanOrEqual(6, $this->faq()->totalCount());
    }
}
