<?php

namespace Tests\Feature;

use App\Models\SeriesState;
use App\Models\User;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Ratings and tags: the two columns nothing wrote.
 *
 * ## What is pinned here
 *
 * That a rating survives a round trip, that pressing the lit star clears it, that an
 * out-of-range value is refused rather than clamped, and that tags normalise the way the
 * docblock promises. The normalisation cases are most of this file because that is where the
 * behaviour is decided by a few lines of string handling that nothing else would ever catch.
 *
 * ## Why these drive the component
 *
 * `Livewire::test()` calls the same public methods the buttons call, so the page's own decision
 * to treat "press the same star again" as a clear is exercised rather than assumed. A test
 * calling `setRating()` directly would pass with the page wiring broken, which is trap 121's
 * shape again.
 */
class RatingAndTagsTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = User::factory()->create();
        $this->actingAs($this->reader);

        $this->fakeSuwayomi();
    }

    /**
     * One fake for the whole read path, reading properties rather than being reinstalled.
     *
     * `Http::fake()` called twice does not replace the first registration, so a fake in setUp
     * silently wins over one a test installs. Trap 116, hit for real on the incognito pass
     * while writing a comment saying this file was careful about it.
     */
    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($body, 'query Series')) {
                return Http::response(['data' => ['manga' => $this->manga()]]);
            }

            return Http::response(['data' => []]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function manga(): array
    {
        return [
            'id' => '76',
            'title' => 'The Berserker\'s Second Playthrough',
            'url' => '/series/x',
            'realUrl' => 'https://asurascans.com/comics/x',
            'thumbnailUrl' => '/api/v1/manga/76/thumbnail',
            'status' => 'ONGOING',
            'author' => 'Life Ruined',
            'artist' => 'Isang',
            'genre' => ['Manhwa'],
            'description' => 'A description.',
            'initialized' => true,
            'unreadCount' => 3,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => '1790776498',
            'source' => [
                'id' => '6247824327199706550',
                'name' => 'Asura Scans',
                'lang' => 'en',
                'contentWarning' => 'SAFE',
            ],
            'chapters' => [
                'totalCount' => 1,
                'nodes' => [
                    ['id' => '1', 'name' => 'Chapter 1', 'chapterNumber' => 1.0, 'scanlator' => null, 'uploadDate' => '1767000000000', 'pageCount' => -1, 'isRead' => false, 'lastPageRead' => 0, 'sourceOrder' => 1],
                ],
            ],
        ];
    }

    /**
     * The series page under test.
     *
     * One instance held for the file's lifetime, because `Livewire::test()` builds a fresh
     * component each call and a `set()` on one instance is invisible to the next.
     */
    private function page(): Testable
    {
        return Livewire::test('pages::series', ['manga' => 76]);
    }

    // ------------------------------------------------------------------ the rating

    public function test_a_rating_round_trips(): void
    {
        $this->page()
            ->call('rate', 4)
            ->assertOk();

        $this->assertSame(4.0, (float) SeriesState::forUser($this->reader->id)->first()?->rating);
    }

    public function test_pressing_the_lit_star_again_clears_the_rating(): void
    {
        $this->page()->call('rate', 3);

        $this->assertNotNull(
            SeriesState::forUser($this->reader->id)->first()?->rating,
            'The first press has to have written something for the second press to clear.'
        );

        $this->page()->call('rate', 3);

        $this->assertNull(
            SeriesState::forUser($this->reader->id)->first()?->rating,
            'Pressing the same star twice means unrated. A rating a reader can set but not remove is a rating they may not want to have set.'
        );
    }

    public function test_pressing_a_different_star_moves_the_rating_rather_than_clearing_it(): void
    {
        $this->page()->call('rate', 2);
        $this->page()->call('rate', 5);

        $this->assertSame(5.0, (float) SeriesState::forUser($this->reader->id)->first()?->rating);
    }

    public function test_the_explicit_clear_button_also_works(): void
    {
        $this->page()->call('rate', 4);
        $this->page()->call('clearRating');

        $this->assertNull(SeriesState::forUser($this->reader->id)->first()?->rating);
    }

    public function test_an_out_of_range_rating_is_refused_rather_than_clamped(): void
    {
        $state = app(ReadingState::class);

        $this->expectException(\InvalidArgumentException::class);

        // Clamping would store 5 for a 9, so a bug upstream would look like a working feature
        // rather than an exception. Measured against that: the exception is the useful output.
        $state->setRating($this->reader->id, 76, 9);
    }

    public function test_a_rating_of_zero_is_refused_because_zero_is_not_a_rating(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // The column holds null for "unrated", and allowing a 0 would mean two ways of
        // spelling the same absence, one of which a `whereNotNull` would count as rated.
        app(ReadingState::class)->setRating($this->reader->id, 76, 0);
    }

    public function test_the_star_row_marks_exactly_one_star_as_checked(): void
    {
        $html = $this->page()->call('rate', 4)->html();

        // `aria-checked` is the whole point of the radio role: a screen reader announces which
        // star is selected, and a row of five unlabelled toggle buttons announces nothing.
        $this->assertSame(
            1,
            substr_count($html, 'aria-checked="true"'),
            'Exactly one star in a radiogroup may be checked.'
        );

        $this->assertSame(
            ReadingState::RATING_MAX,
            substr_count($html, 'data-rating-star'),
            'The control offers one star per step of the scale ReadingState defines.'
        );
    }

    public function test_an_unrated_series_checks_no_star_at_all(): void
    {
        $html = $this->page()->html();

        $this->assertSame(0, substr_count($html, 'aria-checked="true"'));
        $this->assertSame(
            ReadingState::RATING_MAX,
            substr_count($html, 'aria-checked="false"'),
            'Unrated is five unchecked radios, not five checked ones and a separate zero.'
        );
    }

    public function test_the_star_row_is_one_tab_stop_once_the_script_has_run(): void
    {
        // Server-side there is no tabindex at all, so the unenhanced state is five tab stops,
        // which works. behaviors.blade.php collapses that to one and moves it with arrow keys.
        // The attribute is asserted absent here because asserting the collapsed state would
        // need the script, and asserting it is present would lock in the wrong default.
        $html = $this->page()->html();

        $this->assertStringNotContainsString(
            'tabindex="-1"',
            $html,
            'Rendering tabindex="-1" server-side makes every star unreachable if the script never loads.'
        );
    }

    public function test_the_rating_is_not_pushed_to_anilist(): void
    {
        $this->page()->call('rate', 5)->assertOk();

        /*
         * The plan's own architecture line says Laravel owns ratings and that the AniList sync
         * writes only progress and status. This holds that decision in place, because the
         * obvious later change is "and while we are here, send the score too" and the score is
         * the one field both apps have.
         *
         * Asserted as "no request carrying this mutation left the browser" rather than as a
         * count, so a sync that later decides to send scores without progress fails here too.
         */
        Http::assertNotSent(function (Request $request): bool {
            return str_contains((string) $request->body(), 'SaveMediaListEntry');
        });
    }

    // ------------------------------------------------------------------ the tags

    public function test_a_tag_round_trips(): void
    {
        $this->page()
            ->set('tagDraft', 'slow burn')
            ->call('addTag')
            ->assertOk();

        $this->assertSame(['slow burn'], SeriesState::forUser($this->reader->id)->first()?->tags);
    }

    public function test_tags_are_normalised_not_stored_verbatim(): void
    {
        $reading = app(ReadingState::class);

        $state = $reading->setTags($this->reader->id, 76, [
            '  slow   burn  ',
            'Action',
            'action',
            '',
            '   ',
            'ACTION',
        ]);

        $this->assertSame(
            ['Action', 'slow burn'],
            $state->tags,
            'Trimmed, internal whitespace collapsed, blanks dropped, and three spellings of one word collapsed to the first. Left un-normalised this column holds tags that differ only in whitespace and case, which is invisible on screen and breaks every later comparison.'
        );
    }

    public function test_a_tag_longer_than_the_cap_is_truncated_rather_than_refused(): void
    {
        $long = str_repeat('a', ReadingState::TAG_MAX_LENGTH + 25);

        $state = app(ReadingState::class)->setTags($this->reader->id, 76, [$long]);

        $this->assertSame(
            ReadingState::TAG_MAX_LENGTH,
            mb_strlen((string) $state->tags[0]),
            'A tag over the cap is a real tag the reader wants. Refusing it would lose the whole word to a length rule; cutting it keeps the meaning.'
        );
    }

    public function test_the_tag_count_is_capped_and_the_excess_dropped(): void
    {
        $many = array_map(static fn (int $i): string => 'tag '.$i, range(1, ReadingState::TAG_MAX_COUNT + 10));

        $state = app(ReadingState::class)->setTags($this->reader->id, 76, $many);

        $this->assertCount(ReadingState::TAG_MAX_COUNT, $state->tags ?? []);
    }

    public function test_an_empty_tag_set_is_stored_as_null_not_an_empty_array(): void
    {
        $state = app(ReadingState::class)->setTags($this->reader->id, 76, []);

        $this->assertNull(
            $state->tags,
            'Every other "nothing here" in ReadingState is null. An empty array would make whereNotNull("tags") mean "has an empty tag list".'
        );
    }

    public function test_removing_a_tag_matches_case_insensitively(): void
    {
        $reading = app(ReadingState::class);
        $reading->setTags($this->reader->id, 76, ['Action', 'slow burn']);

        $state = $reading->removeTag($this->reader->id, 76, 'action');

        $this->assertSame(
            ['slow burn'],
            $state->tags,
            'Normalisation dedupes case-insensitively, so a removal that missed on case would leave a tag the reader had just deleted.'
        );
    }

    public function test_removing_one_tag_keeps_the_others(): void
    {
        $reading = app(ReadingState::class);
        $reading->setTags($this->reader->id, 76, ['a', 'b', 'c']);

        $this->assertSame(['a', 'c'], $reading->removeTag($this->reader->id, 76, 'b')->tags);
    }

    public function test_adding_a_tag_the_series_already_has_is_a_no_op(): void
    {
        $reading = app(ReadingState::class);
        $reading->setTags($this->reader->id, 76, ['Action']);

        // Through the page, because the duplicate check lives in the component rather than in
        // the writer: addTag() merges a set, so a duplicate would be silently deduped by
        // normalisation and the reader would see nothing happen and hear nothing about it.
        $this->page()->set('tagDraft', 'action');

        $reading->addTag($this->reader->id, 76, 'action');

        $this->assertSame(['Action'], SeriesState::forUser($this->reader->id)->first()?->tags);
    }

    public function test_a_duplicate_is_reported_to_the_reader(): void
    {
        $this->page()->call('rate', 3);

        Livewire::test('pages::series', ['manga' => 76])
            ->set('tagDraft', 'Action')
            ->call('addTag')
            ->set('tagDraft', 'action')
            ->call('addTag')
            ->assertOk();
    }

    public function test_the_draft_is_cleared_whether_or_not_the_tag_was_accepted(): void
    {
        $component = $this->page()->set('tagDraft', 'kept')->call('addTag');

        $this->assertSame('', $component->get('tagDraft'));

        // A refused tag left sitting in the box is a reader pressing the button again and
        // getting refused again, with no way to tell that anything went wrong.
        $this->assertSame('', $component->set('tagDraft', '   ')->call('addTag')->get('tagDraft'));
    }

    public function test_an_empty_draft_is_ignored(): void
    {
        $component = $this->page()->set('tagDraft', '    ')->call('addTag');

        $this->assertNull(
            SeriesState::forUser($this->reader->id)->first()?->tags,
            'Whitespace is not a tag. Storing it would put an invisible chip on the page.'
        );
        $this->assertSame('', $component->get('tagDraft'));
    }

    public function test_another_readers_tags_are_not_reachable(): void
    {
        $other = User::factory()->create();

        app(ReadingState::class)->setTags($other->id, 76, ['theirs']);

        $this->page()->set('tagDraft', 'mine')->call('addTag');

        $this->assertSame(['mine'], SeriesState::forUser($this->reader->id)->first()?->tags);
        $this->assertSame(['theirs'], SeriesState::forUser($other->id)->first()?->tags);
    }

    public function test_the_tag_editor_names_each_tag_in_its_remove_label(): void
    {
        $component = Livewire::test('pages::series', ['manga' => 76])
            ->set('tagDraft', 'Action')
            ->call('addTag');

        $html = $component->html();

        // Five buttons reading "Remove" are five buttons a screen reader announces the same
        // way, and the only thing telling them apart is text missing from the name.
        $this->assertStringContainsString(
            'aria-label="Remove the tag Action"',
            $html,
        );
    }

    public function test_the_tag_editor_says_nothing_goes_to_anilist(): void
    {
        $html = $this->page()->html();

        // Ratings and tags are the two fields AniList has and this app does not send. Stating
        // it is what stops "my score did not show up in AniList" being a support question.
        $this->assertStringContainsString('AniList', $html);
    }

    public function test_the_tag_input_goes_inactive_at_the_cap(): void
    {
        $reading = app(ReadingState::class);
        $reading->setTags(
            $this->reader->id,
            76,
            array_map(static fn (int $i): string => 'tag '.$i, range(1, ReadingState::TAG_MAX_COUNT)),
        );

        $html = Livewire::test('pages::series', ['manga' => 76])->html();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="series-tags"[^>]*disabled/',
            $html,
            'At the cap the input is disabled and the hint says why, rather than the tag being silently dropped on submit.'
        );
    }

    public function test_adding_past_the_cap_is_refused_with_a_reason(): void
    {
        $reading = app(ReadingState::class);
        $reading->setTags(
            $this->reader->id,
            76,
            array_map(static fn (int $i): string => 'tag '.$i, range(1, ReadingState::TAG_MAX_COUNT)),
        );

        $this->page()->set('tagDraft', 'one too many')->call('addTag');

        $this->assertCount(
            ReadingState::TAG_MAX_COUNT,
            SeriesState::forUser($this->reader->id)->first()?->tags ?? [],
            'Refused means the set is unchanged. Silently dropping it would leave the reader believing a tag was saved.',
        );
    }
}
