<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Faq;
use App\Support\FaqEntry;
use App\Support\SourceNotices;
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

    /**
     * The answer to one question, as one string.
     *
     * By the question's own wording rather than by its position, because a position moves
     * whenever a question is added in the middle and a test that reads the third entry is a test
     * about the order rather than about the answer.
     */
    private function answerTo(string $question): string
    {
        foreach ($this->faq()->entries() as $entry) {
            if ($entry->question === $question) {
                return implode(' ', $entry->answer);
            }
        }

        $this->fail("the FAQ has no question reading \"{$question}\"");
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

    /**
     * The settings page offers it as well, and now at a seat inside the app.
     *
     * The public page is still public and still there. The Settings navlist's item used to point
     * at it, which threw the reader out of the app shell to read a paragraph, so the item points
     * at the in-shell route now. **The assertion is an exact href rather than a path, because
     * "/settings/faq" contains "/faq"** and a substring check would pass for the wrong reason,
     * which is the F16 family: a check that cannot fail is not a check.
     */
    public function test_settings_offers_it_as_well(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'));

        $response->assertOk();

        $content = (string) $response->getContent();

        $this->assertStringContainsString('href="'.route('settings.faq').'"', $content);
        $this->assertStringNotContainsString('href="'.route('faq').'"', $content);
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

    public function test_the_first_trust_answers_use_reader_words(): void
    {
        $answers = array_map(
            static fn (FaqEntry $entry): string => implode(' ', $entry->answer),
            array_slice($this->faq()->entries(), 0, 5),
        );
        $text = implode(' ', $answers);

        $this->assertStringContainsStringIgnoringCase('nothing leaves this computer', $text);
        $this->assertStringContainsString('lives only on this computer', $text);
        $this->assertStringContainsString('accepts connections only from this computer', $text);
        $this->assertStringContainsString('chapters you have already downloaded', $text);
        $this->assertStringNotContainsString('loopback address', $text);
        $this->assertStringNotContainsString('phone-home check', $text);
    }

    /**
     * The one that matters most, and the one the first draft got wrong.
     *
     * A privacy page that says nothing leaves your machine is false in five specific ways. Four of
     * them are the reader's own doing, and the fifth belongs to the operator of a shared install.
     * Each has to be named, because a reader deciding whether to trust a self-hosted app is
     * entitled to know exactly where the trust stops.
     */
    public function test_it_names_every_thing_that_does_contact_the_internet(): void
    {
        $outbound = $this->faq()->outbound();

        $this->assertCount(5, $outbound, 'five connections can leave the machine, and the count is the claim');

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
     * this yourself, and here is how to stop it" is. Mail is the exception: its actor is the operator
     * of a shared or public install, and the entry has to say so rather than imply the reader chose it.
     */
    public function test_each_exception_says_who_started_it(): void
    {
        foreach ($this->faq()->outbound() as $route) {
            $this->assertMatchesRegularExpression(
                '/\b(you|your|operator)\b/i',
                $route['who'],
                "an exception must say who started it: {$route['what']}",
            );
        }
    }

    /**
     * The two AniList paths are not the same path.
     *
     * Title matching is a search-only lookup that needs no account and stops when the reader turns
     * matching off. Tracking needs a connection. The old list described only tracking, which made
     * the lookup invisible to a reader who had never connected an account.
     */
    public function test_the_title_matching_exception_is_not_described_as_tracking(): void
    {
        foreach ($this->faq()->outbound() as $route) {
            if (str_contains($route['what'], 'title matching')) {
                $this->assertStringContainsStringIgnoringCase('does not need an AniList account', $route['who']);
                $this->assertStringContainsStringIgnoringCase('stops when you turn matching off', $route['who']);
                $this->assertStringNotContainsStringIgnoringCase('connect', $route['who']);

                return;
            }
        }

        $this->fail('AniList title matching is one of the five outbound routes');
    }

    /**
     * Mail belongs to the operator, not the reader.
     */
    public function test_the_mail_exception_names_the_operator(): void
    {
        foreach ($this->faq()->outbound() as $route) {
            if (str_contains($route['what'], 'mail server')) {
                $this->assertStringContainsStringIgnoringCase('default install sends no mail', $route['who']);
                $this->assertStringContainsStringIgnoringCase('operator', $route['who']);

                return;
            }
        }

        $this->fail('the mail server is one of the five outbound routes');
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

        $this->fail('the extension repository is one of the five outbound routes');
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

    // The questions this phase added -----------------------------------

    /**
     * The six questions the phase added, by their own wording.
     *
     * Named rather than counted, because a count is a number a later edit satisfies by adding a
     * different question. These are the six the plan names: what a search asks, what a block
     * does, the empty listing, moving a series, what a backup covers, and where the licences are
     * acknowledged.
     */
    public function test_it_answers_the_six_mechanisms_the_phase_added(): void
    {
        $questions = array_map(
            static fn (FaqEntry $e): string => $e->question,
            $this->faq()->entries(),
        );

        foreach ([
            'Can I limit what a search asks?',
            'What happens when a site blocks me?',
            'What if a series has no chapters?',
            'How do I move a series to another source?',
            'Where do I acknowledge the licences?',
            'What does a backup cover, and what does it not?',
        ] as $question) {
            $this->assertContains(
                $question,
                $questions,
                "the page answers this question: {$question}",
            );
        }
    }

    /**
     * The subjects a reader arrives with, checked against the question and the answer together.
     *
     * The privacy answer's own test above checks the questions only, because every subject it
     * lists is the subject of a question. These are subjects a reader would look for in the
     * answer as well: "pin" and "ban" are in the wording of the answers and not of the questions,
     * and a page that a reader cannot find the word on is a page that does not answer them.
     */
    public function test_the_later_subjects_are_on_the_page(): void
    {
        $text = implode(' ', array_map(
            static fn (FaqEntry $e): string => $e->question.' '.implode(' ', $e->answer),
            $this->faq()->entries(),
        ));

        foreach (['pin', 'ban', 'chapter', 'move', 'licence', 'backup'] as $subject) {
            $this->assertStringContainsStringIgnoringCase(
                $subject,
                $text,
                "a reader asks about {$subject} and the page should have an answer",
            );
        }
    }

    /**
     * The backup answer, held against what the app does now.
     *
     * The old answer said "There is no cloud copy, no sync service and nothing to export" and
     * "Copy it while Papyrus is closed". The first was made false by the outward export and the
     * second by the in-app backup, and this is the copy a reader reads before deciding to trust
     * the app with their library, so it has to match the Backups page.
     */
    public function test_the_backup_answer_says_what_the_app_now_does(): void
    {
        $answer = $this->answerTo('How do I make a backup?');

        $this->assertStringNotContainsString(
            'nothing to export',
            $answer,
            'the app exports a .tachibk for another reader app now, so this sentence is false',
        );

        $this->assertStringNotContainsString(
            'Copy it while Papyrus is closed',
            $answer,
            'the app takes a pair while it keeps running, so this is no longer the rule',
        );

        foreach ([
            'Back up now',
            'Restore',
            'Export for another reader app',
            'no cloud copy',
            'no sync service',
        ] as $claim) {
            $this->assertStringContainsString(
                $claim,
                $answer,
                "the answer has to name what the Backups page offers: {$claim}",
            );
        }
    }

    public function test_the_backup_answer_names_the_install_owner(): void
    {
        $answer = $this->answerTo('How do I make a backup?');

        $this->assertStringContainsString(
            'install owner',
            $answer,
            'only the install owner can open the Backups page, so the answer must not send every reader there',
        );
    }

    /**
     * The ban sites the page names are the sites the app warns about at the install press.
     *
     * Read out of the config rather than out of a copy of it, so a fifth ban site added there
     * fails this test and the page has to be updated with it. A page that names a site the app
     * no longer warns about, or that omits one it does, is a page that has drifted from the
     * behaviour it describes.
     */
    public function test_the_ban_sites_it_names_are_the_ones_the_app_warns_about(): void
    {
        $sites = config('source-notices.sites');

        $this->assertIsArray($sites);

        $warned = array_keys(array_filter(
            $sites,
            static fn (mixed $kind): bool => $kind === SourceNotices::BAN,
        ));

        $this->assertNotEmpty($warned, 'the app warns about some sites, so the page has to name them');

        $answer = $this->answerTo('What happens when a site blocks me?');

        foreach ($warned as $site) {
            $this->assertStringContainsString(
                (string) $site,
                $answer,
                "the app warns about {$site} at the install press, so the page has to name it",
            );
        }
    }

    /**
     * The adult content answer has to agree with where the switch actually is.
     *
     * The gate is a permission and the rating chips are a scope, and the two are
     * deliberately not the same control: the Sources page puts the gate in a row of
     * its own, because the Installed slice has no Content filter at all, and the
     * Search page puts it below the filter in its own block. The answer read against
     * the page rather than against a copy of the page, so a future change to where
     * the switch sits has to come here too.
     */
    public function test_the_adult_content_answer_says_where_the_switch_actually_sits(): void
    {
        $answer = $this->answerTo('How do I turn on adult content?');

        $this->assertStringNotContainsString(
            'in the same row as the rating filter',
            $answer,
            'the gate renders in a row of its own, so the answer must not send a reader looking among the rating chips',
        );

        $this->assertStringContainsString(
            'row of its own',
            $answer,
            'the answer has to say where the switch is, because that is the question a reader asks',
        );

        // Named exactly rather than globbed: a wildcard before "sources" also
        // matches "migrate-sources", and that page sorts first, so the glob
        // read the wrong file and every assertion below was about the wrong page.
        $pages = [
            'sources' => '⚡sources.blade.php',
            'search' => '⚡search.blade.php',
        ];

        foreach ($pages as $page => $file) {
            $blade = __DIR__.'/../../resources/views/pages/'.$file;

            $this->assertFileExists($blade, "the {$page} page file is missing: {$file}");

            $contents = (string) file_get_contents($blade);

            $this->assertStringContainsString(
                '<x-adult-content.gate',
                $contents,
                "the {$page} page has to render the gate, or the answer names a control that is not there",
            );

            $this->assertStringNotContainsString(
                '<x-adult-content.gate',
                $this->ratingGroupOf($contents),
                "the {$page} page's rating group must not hold the gate, or the answer's row of its own is untrue",
            );
        }
    }

    /**
     * The part of a page that holds the rating chips, which is what the gate must not be inside.
     *
     * Read out of the rendered markup rather than guessed at: the group opens at the
     * first `flux:checkbox.group` and closes at its matching close, and anything
     * between them is what a reader sees as the rating row.
     */
    private function ratingGroupOf(string $contents): string
    {
        $open = strpos($contents, '<flux:checkbox.group');

        if ($open === false) {
            return '';
        }

        $close = strpos($contents, '</flux:checkbox.group>', $open);

        return $close === false ? '' : substr($contents, $open, $close - $open);
    }

    /**
     * The move answer has to agree with the dialog the migration page renders.
     *
     * The dialog asks three things about what to carry, and it asks a fourth about
     * AniList only when the reader has connected an account, because a checkbox
     * wired to nothing would be a promise the page could not keep. The old answer
     * said four things flatly, which is wrong for every reader who has not
     * connected AniList.
     */
    public function test_the_move_answer_says_when_the_anilist_row_is_asked(): void
    {
        $answer = $this->answerTo('How do I move a series to another source?');

        $this->assertStringNotContainsString(
            'you are asked four things',
            $answer,
            'the AniList row is only asked of a reader with an account, so the answer must not promise four questions flatly',
        );

        $this->assertStringContainsString(
            'If you have connected an AniList account',
            $answer,
            'the answer has to say when the AniList question is asked, because it is not asked of everybody',
        );

        $blade = collect(glob(__DIR__.'/../../resources/views/pages/*migrate.blade.php'))->first();
        $contents = (string) file_get_contents($blade);

        $this->assertStringContainsString(
            '@if ($this->trackConnected)',
            $contents,
            'the AniList row has to be behind the account check, or the answer describes a row the page does not show',
        );
    }

    /**
     * The public copy carries no em or en dash.
     *
     * The rule the repository already enforces everywhere, held here for the copy that ships to
     * readers. A dash in a comment is a dash in a sentence somebody reads, and this file is the
     * one that carries the sentences.
     */
    public function test_the_public_copy_carries_no_em_or_en_dash(): void
    {
        foreach ($this->faq()->entries() as $entry) {
            foreach ([$entry->question, ...$entry->answer] as $text) {
                $this->assertDoesNotMatchRegularExpression(
                    '/[\x{2014}\x{2013}\x{2015}\x{2012}]/u',
                    $text,
                    "the page ships this to readers: {$text}",
                );
            }
        }
    }

    // The same questions, inside the app --------------------------------

    /**
     * The in-shell seat of the same list.
     *
     * The Settings navlist's FAQ item used to point at the public page, which sits on the
     * marketing layout, so answering a question meant leaving the app. The list is in one place
     * and the markup is in one component, so the two pages cannot answer different questions.
     */
    public function test_the_questions_are_reachable_inside_the_app(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('settings.faq'))
            ->assertOk()
            ->assertSee('Does anything leave my computer?')
            ->assertSee('How do I make a backup?');
    }

    /**
     * The in-shell seat is behind the login, because the app is.
     *
     * The public page is the one that answers for a reader who has not signed in yet, and this
     * one answers for a reader who has.
     */
    public function test_the_in_shell_questions_are_not_public(): void
    {
        $this->get(route('settings.faq'))
            ->assertRedirect(route('login'));
    }

    /**
     * The public page is still the public page for a reader who is signed in.
     *
     * Deliberately not redirected: it is the page somebody else can be sent to, and a redirect
     * would remove it from the reader who can reach it most easily.
     */
    public function test_the_public_page_still_renders_for_a_signed_in_reader(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('faq'))
            ->assertOk()
            ->assertSee('Does anything leave my computer?');
    }

    /**
     * The two pages render one list.
     *
     * The count is the check, because a page that answered a different set of questions on the
     * other side of the shell would still pass every assertion above.
     */
    public function test_both_pages_answer_the_same_questions(): void
    {
        $reader = User::factory()->create();

        $public = (string) $this->actingAs($reader)->get(route('faq'))->getContent();
        $inside = (string) $this->actingAs($reader)->get(route('settings.faq'))->getContent();

        $this->assertSame(
            $this->faq()->totalCount(),
            substr_count($public, '<details'),
            'the public page renders every question, one disclosure each',
        );

        $this->assertSame(
            substr_count($public, '<details'),
            substr_count($inside, '<details'),
            'the two pages render one list, so they cannot answer different questions',
        );
    }
}
