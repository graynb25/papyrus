<?php

namespace Tests\Feature;

use App\AniList\AniListRefusedException;
use App\AniList\Preference;
use App\AniList\TitleResolver;
use App\Jobs\ResolveAniListTitles;
use App\Models\AniListMedia;
use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\Source;
use App\Models\User;
use App\Search\Deduplicator;
use App\Search\TitleNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * AniList matching for the search deduplicator.
 *
 * Measured facts the tests are written against, so they would fail if any of them
 * stopped being true:
 *
 *  - The rate limit is **30 requests a minute**, from `x-ratelimit-limit`. A fan-out
 *    produces 1,080 raw hits and 91 distinct titles, so resolving one run is minutes
 *    of work and cannot happen in a request.
 *  - A title's display names are not the names sources use. Searching
 *    "The Berserker's Second Playthrough" returns media whose romaji is
 *    "Game Sok 2-hoecha Gwangjeonsa" and whose native is "게임 속 2회차 광전사";
 *    the English title is in `synonyms` only.
 *  - Cross-script identity is the whole point: "チェンソーマン" and "Chainsaw Man" both
 *    resolve to 105778, and "ワンピース" and "One Piece" both to 30013.
 *  - Most titles have no AniList entry. Measured, 71 of 91 in a fan-out, mostly
 *    doujinshi and anthologies. So the "asked and told no" state has to be
 *    distinguishable from "not asked", and it must never become a bucket key, or
 *    every unknown title would collapse into one group.
 *  - A 429 is a refusal, not an absence. Confirmed the hard way: a probe that treated
 *    non-200 as "no match" put a 125-member group into the unmatched bucket.
 *
 * Every way the API can behave is a property rather than a second `Http::fake()`.
 * Trap 23.
 */
class AniListTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /** Titles the fake will answer, as title to media. */
    private array $media = [];

    /** When set, every call answers with this status instead. */
    private ?int $refusalStatus = null;

    /** @var array<int, array<string, mixed>> every request sent, in order */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->media = [];
        $this->refusalStatus = null;
        $this->requests = [];

        $this->fakeAniList();
    }

    private function fakeAniList(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            $this->requests[] = ['title' => (string) ($request->data()['variables']['search'] ?? '')];

            if ($this->refusalStatus !== null) {
                return Http::response(['errors' => [['message' => 'Too many requests']]], $this->refusalStatus);
            }

            $title = (string) ($request->data()['variables']['search'] ?? '');
            $found = $this->media[$title] ?? null;

            return Http::response(['data' => ['Media' => $found]]);
        });
    }

    /**
     * A media payload in AniList's shape.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mediaPayload(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'idMal' => null,
            'format' => 'MANGA',
            'countryOfOrigin' => 'KR',
            'chapters' => 41,
            'status' => 'RELEASING',
            'isAdult' => false,
            'title' => ['romaji' => 'Some Romaji', 'english' => null, 'native' => null],
            'synonyms' => [],
            'staff' => ['edges' => []],
        ], $overrides);
    }

    /** Put a media row in the cache directly, as a previous run would have. */
    private function cached(int $anilistId, string $title, array $overrides = []): AniListMedia
    {
        return AniListMedia::query()->create(array_merge([
            'anilist_id' => $anilistId,
            'normalized_title' => app(TitleNormalizer::class)->normalise($title),
            'title_romaji' => $title,
            'is_adult' => false,
            'looked_up_at' => now(),
        ], $overrides));
    }

    // The opt-in -----------------------------------------------------------

    public function test_matching_is_off_by_default(): void
    {
        $this->assertFalse(app(Preference::class)->enabled($this->user));
    }

    public function test_a_reader_can_turn_it_on(): void
    {
        app(Preference::class)->set($this->user, true);

        $this->assertTrue(app(Preference::class)->enabled($this->user));
    }

    public function test_the_preference_is_per_reader(): void
    {
        $other = User::factory()->create();

        app(Preference::class)->set($this->user, true);

        $this->assertTrue(app(Preference::class)->enabled($this->user));
        $this->assertFalse(app(Preference::class)->enabled($other), 'One reader opting in is not consent for another.');
    }

    public function test_turning_it_off_works(): void
    {
        app(Preference::class)->set($this->user, true);
        app(Preference::class)->set($this->user, false);

        $this->assertFalse(app(Preference::class)->enabled($this->user));
    }

    public function test_the_setting_survives_a_re_read(): void
    {
        app(Preference::class)->set($this->user, true);

        // A fresh instance, because a cached read would prove nothing.
        $this->assertTrue((new Preference)->enabled($this->user));
    }

    // Resolution and caching ----------------------------------------------

    public function test_a_lookup_is_recorded_with_every_name_anilist_claims(): void
    {
        $this->media["The Berserker's Second Playthrough"] = $this->mediaPayload(205085, [
            'title' => [
                'romaji' => 'Game Sok 2-hoecha Gwangjeonsa',
                'english' => null,
                'native' => '게임 속 2회차 광전사',
            ],
            'synonyms' => ["The Berserker's Second Playthrough"],
        ]);

        $resolver = app(TitleResolver::class);
        $row = $resolver->resolve("The Berserker's Second Playthrough");

        $this->assertNotNull($row);
        $this->assertSame(205085, $row->anilist_id);
        $this->assertSame('Game Sok 2-hoecha Gwangjeonsa', $row->title_romaji);
        $this->assertSame("The Berserker's Second Playthrough", $row->synonyms[0]);

        // The names are what let a human judge the match, and the display title is
        // not the one a source uses.
        $this->assertContains("The Berserker's Second Playthrough", $row->allNames());
        $this->assertSame('Game Sok 2-hoecha Gwangjeonsa', $row->displayTitle());
    }

    public function test_a_second_lookup_of_the_same_title_makes_no_request(): void
    {
        $this->media['Chainsaw Man'] = $this->mediaPayload(105778);
        $resolver = app(TitleResolver::class);

        $resolver->resolve('Chainsaw Man');
        $before = count($this->requests);

        $resolver->resolve('Chainsaw Man');

        $this->assertCount($before, $this->requests, 'The cache is the whole reason this is affordable.');
    }

    public function test_reading_an_id_never_makes_a_request(): void
    {
        $this->cached(30013, 'One Piece');

        $id = app(TitleResolver::class)->idFor('One Piece');

        $this->assertSame(30013, $id);
        $this->assertSame([], $this->requests, 'The render path must be a table read and nothing else.');
    }

    public function test_a_title_anilist_does_not_have_is_recorded_as_asked(): void
    {
        // The fake answers a null Media for a title it has no entry for.
        $resolver = app(TitleResolver::class);

        $this->assertNull($resolver->resolve('A Doujin Nobody Has Heard Of'));

        $this->assertSame(
            1,
            (int) AniListMedia::query()->where('anilist_id', 0)->count(),
            'A row is written, with id 0 as a sentinel rather than an identity.',
        );

        $this->assertNull($resolver->idFor('A Doujin Nobody Has Heard Of'));
    }

    public function test_the_sentinel_is_not_reported_as_an_id(): void
    {
        $resolver = app(TitleResolver::class);
        $resolver->resolve('A Doujin Nobody Has Heard Of');

        // Null rather than 0, so a caller cannot mistake the sentinel for an
        // identity and bucket every unknown title together.
        $this->assertNull($resolver->idFor('A Doujin Nobody Has Heard Of'));
    }

    public function test_a_refusal_records_nothing(): void
    {
        $this->refusalStatus = 429;

        $resolver = app(TitleResolver::class);

        $this->expectException(AniListRefusedException::class);

        try {
            $resolver->resolve('Chainsaw Man');
        } finally {
            $this->assertSame(0, AniListMedia::query()->count(), 'A 429 must never be cached as an answer.');
        }
    }

    public function test_a_refusal_is_distinguishable_from_an_absence(): void
    {
        $resolver = app(TitleResolver::class);

        // A clean 200 with no Media is an answer.
        $this->assertNull($resolver->resolve('Nothing Here'));

        // A 429 throws, and is not the same thing.
        $this->refusalStatus = 429;

        $threw = false;

        try {
            $resolver->resolve('Something Else');
        } catch (AniListRefusedException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A rate limit is not an answer about the title.');
    }

    public function test_missing_lists_only_what_nobody_has_looked_up(): void
    {
        $resolver = app(TitleResolver::class);
        $this->cached(30013, 'One Piece');
        $resolver->resolve('A Doujin Nobody Has Heard Of');

        $missing = $resolver->missing(['One Piece', 'A Doujin Nobody Has Heard Of', 'Brand New']);

        $this->assertCount(1, $missing);
        $this->assertSame('Brand New', reset($missing));
    }

    public function test_progress_counts_answered_titles(): void
    {
        $resolver = app(TitleResolver::class);
        $this->cached(30013, 'One Piece');

        $progress = $resolver->progress(['One Piece', 'Two Piece', 'Three Piece']);

        $this->assertSame(3, $progress['total']);
        $this->assertSame(1, $progress['answered']);
    }

    public function test_staff_is_recorded(): void
    {
        $this->media['Berserker'] = $this->mediaPayload(30002, [
            'staff' => ['edges' => [
                ['role' => 'Story', 'node' => ['name' => ['full' => 'Kentaro Miura']]],
                ['role' => 'Art', 'node' => ['name' => ['full' => 'Kentaro Miura']]],
            ]],
        ]);

        $row = app(TitleResolver::class)->resolve('Berserker');

        $this->assertCount(2, $row->staff);
        $this->assertSame('Kentaro Miura', $row->staff[0]['name']);
    }

    public function test_is_adult_is_recorded_and_never_applied(): void
    {
        $this->media['Some Adult Thing'] = $this->mediaPayload(999, ['isAdult' => true]);

        $row = app(TitleResolver::class)->resolve('Some Adult Thing');

        $this->assertTrue($row->is_adult, 'Recorded.');

        // And nothing in the application acts on it. The source rating is the one
        // the deduplicator reports, and nothing here overwrote it.
        $this->assertSame('SAFE', (new Source(['content_warning' => Source::SAFE]))->content_warning);
    }

    // The deduplicator -----------------------------------------------------

    /** @return array<int, SearchResult> */
    private function resultsFor(array $titles, int $mangaIdBase = 1000): array
    {
        $run = SearchRun::createFor($this->user->id, ['query' => 'test']);
        $results = [];

        foreach (array_values($titles) as $index => $title) {
            $results[] = SearchResult::createFor($this->user->id, [
                'search_run_id' => $run->id,
                'suwayomi_manga_id' => $mangaIdBase + $index,
                'title' => $title,
                'normalized_title' => app(TitleNormalizer::class)->normalise($title),
                'url' => '/series/'.($mangaIdBase + $index),
                'suwayomi_source_id' => 1,
                'source_name' => 'Test Source',
                'content_warning' => 'SAFE',
                'status' => 'ONGOING',
            ]);
        }

        return $results;
    }

    private function dedupe(array $titles, bool $withResolver): Collection
    {
        $normalizer = app(TitleNormalizer::class);

        return (new Deduplicator(
            $normalizer,
            0.95,
            $withResolver ? app(TitleResolver::class) : null,
        ))->group(new Collection($this->resultsFor($titles)));
    }

    public function test_titles_alone_do_not_merge_translations(): void
    {
        // The measured shape: eight buckets that are all One Piece.
        $groups = $this->dedupe(['One Piece', '海賊王', 'ワンピース', 'ون بيس'], withResolver: false);

        $this->assertCount(4, $groups, 'There is no shared text to compare, so the titles cannot join them.');
    }

    public function test_one_anilist_id_merges_translations_the_titles_cannot(): void
    {
        $this->cached(30013, 'One Piece');
        $this->cached(30013, '海賊王');
        $this->cached(30013, 'ワンピース');
        $this->cached(30013, 'ون بيس');

        $groups = $this->dedupe(['One Piece', '海賊王', 'ワンピース', 'ون بيس'], withResolver: true);

        $this->assertCount(1, $groups);
        $this->assertSame(4, $groups->first()->sourceCount());
    }

    public function test_it_merges_renamed_variants_the_titles_miss(): void
    {
        $this->cached(110715, 'One Piece Gakuen');
        $this->cached(110715, 'One Piece Academy');

        $groups = $this->dedupe(['One Piece Gakuen', 'One Piece Academy'], withResolver: true);

        $this->assertCount(1, $groups, 'Two names for one series, which measured as separate before.');
    }

    public function test_it_does_not_merge_different_series(): void
    {
        // The over-merge risk, and the reason this is worth testing. Measured:
        // Fischer's x ONE PIECE, Cursed One Piece and the doujinshi all keep their
        // own AniList ids.
        $this->cached(110409, "Fischer's×ONE PIECE");
        $this->cached(47152, 'ONE PIECE: STRONG WORLD');
        $this->cached(30013, 'One Piece');

        $groups = $this->dedupe(["Fischer's×ONE PIECE", 'ONE PIECE: STRONG WORLD', 'One Piece'], withResolver: true);

        $this->assertCount(3, $groups);
    }

    public function test_titles_anilist_does_not_know_stay_separate(): void
    {
        // 71 of 91 measured titles have no entry. If the sentinel became a bucket key
        // they would all collapse into one group, which would be catastrophic.
        $resolver = app(TitleResolver::class);
        $resolver->resolve('Unknown One');
        $resolver->resolve('Unknown Two');

        $groups = $this->dedupe(['Unknown One', 'Unknown Two'], withResolver: true);

        $this->assertCount(2, $groups);
    }

    public function test_a_mixed_run_merges_only_what_it_knows(): void
    {
        $this->cached(30013, 'One Piece');
        $this->cached(30013, '海賊王');
        $resolver = app(TitleResolver::class);
        $resolver->resolve('Unknown Thing');

        $groups = $this->dedupe(['One Piece', '海賊王', 'Unknown Thing'], withResolver: true);

        $this->assertCount(2, $groups, 'The known pair merges, the unknown stands alone.');
        $this->assertSame(2, $groups->first()->sourceCount());
    }

    public function test_no_resolver_means_the_pre_anilist_behaviour(): void
    {
        $this->cached(30013, 'One Piece');
        $this->cached(30013, '海賊王');

        $groups = $this->dedupe(['One Piece', '海賊王'], withResolver: false);

        $this->assertCount(2, $groups, 'An opted-out reader gets exactly the old behaviour.');
    }

    public function test_grouping_asks_anilist_for_nothing(): void
    {
        $this->cached(30013, 'One Piece');

        $this->dedupe(['One Piece'], withResolver: true);

        $this->assertSame([], $this->requests, 'Grouping is a render path and must stay free.');
    }

    // The job --------------------------------------------------------------

    private function runWithResults(int $count): SearchRun
    {
        $run = SearchRun::createFor($this->user->id, ['query' => 'one piece']);

        for ($i = 0; $i < $count; $i++) {
            SearchResult::createFor($this->user->id, [
                'search_run_id' => $run->id,
                'suwayomi_manga_id' => 2000 + $i,
                'title' => 'Title '.$i,
                'normalized_title' => 'title'.$i,
                'url' => '/series/'.(2000 + $i),
                'suwayomi_source_id' => 1,
                'source_name' => 'Test Source',
                'content_warning' => 'SAFE',
                'status' => 'ONGOING',
            ]);
        }

        return $run;
    }

    public function test_the_job_resolves_the_titles_it_has_not_looked_up(): void
    {
        Queue::fake();

        foreach (range(0, 2) as $i) {
            $this->media['Title '.$i] = $this->mediaPayload(100 + $i);
        }

        $this->cached(999, 'Title 0');
        $run = $this->runWithResults(3);

        $this->app->call([new ResolveAniListTitles($this->user->id, $run->id), 'handle']);

        // Title 0 was cached, so only the other two were asked about.
        $this->assertSame(['Title 1', 'Title 2'], array_column($this->requests, 'title'));
    }

    public function test_the_job_asks_about_distinct_titles_only(): void
    {
        Queue::fake();

        $this->media['One Piece'] = $this->mediaPayload(30013);

        $run = SearchRun::createFor($this->user->id, ['query' => 'one piece']);

        // Twenty hits, all the same title, which is the measured shape of a fan-out
        // where one series dominates.
        for ($i = 0; $i < 20; $i++) {
            SearchResult::createFor($this->user->id, [
                'search_run_id' => $run->id,
                'suwayomi_manga_id' => 3000 + $i,
                'title' => 'One Piece',
                'normalized_title' => 'onepiece',
                'url' => '/series/'.(3000 + $i),
                'suwayomi_source_id' => 1,
                'source_name' => 'Test Source',
                'content_warning' => 'SAFE',
                'status' => 'ONGOING',
            ]);
        }

        $this->app->call([new ResolveAniListTitles($this->user->id, $run->id), 'handle']);

        $this->assertSame(['One Piece'], array_column($this->requests, 'title'), 'Twenty hits, one lookup.');
    }

    public function test_the_job_rebooks_itself_while_titles_remain(): void
    {
        Queue::fake();

        foreach (range(0, 29) as $i) {
            $this->media['Title '.$i] = $this->mediaPayload(100 + $i);
        }

        $run = $this->runWithResults(30);

        $this->app->call([new ResolveAniListTitles($this->user->id, $run->id), 'handle']);

        Queue::assertPushed(ResolveAniListTitles::class);
    }

    public function test_the_job_books_nothing_once_every_title_is_answered(): void
    {
        Queue::fake();

        $this->media['Title 0'] = $this->mediaPayload(100);
        $run = $this->runWithResults(1);

        $this->app->call([new ResolveAniListTitles($this->user->id, $run->id), 'handle']);

        Queue::assertNotPushed(ResolveAniListTitles::class);
    }

    public function test_a_refusal_stops_the_pass_and_books_nothing(): void
    {
        Queue::fake();

        foreach (range(0, 5) as $i) {
            $this->media['Title '.$i] = $this->mediaPayload(100 + $i);
        }

        $run = $this->runWithResults(6);

        // Refuse from the second request onwards, so one title lands and then the
        // limiter speaks.
        $this->refusalStatus = 429;

        $this->app->call([new ResolveAniListTitles($this->user->id, $run->id), 'handle']);

        Queue::assertNotPushed(
            ResolveAniListTitles::class,
            'A refusal is not a reason to schedule more work this pass; the next tick is.',
        );
    }

    public function test_the_job_does_nothing_for_another_readers_run(): void
    {
        Queue::fake();

        $bob = User::factory()->create();
        $this->media['Title 0'] = $this->mediaPayload(100);

        $run = SearchRun::createFor($bob->id, ['query' => 'x']);
        SearchResult::createFor($bob->id, [
            'search_run_id' => $run->id,
            'suwayomi_manga_id' => 4000,
            'title' => 'Title 0',
            'normalized_title' => 'title0',
            'url' => '/series/4000',
            'suwayomi_source_id' => 1,
            'source_name' => 'Test Source',
            'content_warning' => 'SAFE',
            'status' => 'ONGOING',
        ]);

        $this->app->call([new ResolveAniListTitles($this->user->id, $run->id), 'handle']);

        $this->assertSame([], $this->requests);
    }

    // The page -------------------------------------------------------------

    public function test_the_page_shows_the_switch_and_it_is_off_by_default(): void
    {
        Livewire::test('pages::search')
            ->assertOk()
            ->assertSee('Match titles against AniList')
            ->assertSet('anilistOn', false);
    }

    public function test_turning_the_switch_on_persists_it(): void
    {
        Livewire::test('pages::search')
            ->set('anilistOn', true)
            ->assertOk();

        $this->assertTrue(app(Preference::class)->enabled($this->user));
    }

    public function test_turning_the_switch_off_persists_it(): void
    {
        app(Preference::class)->set($this->user, true);

        Livewire::test('pages::search')
            ->assertSet('anilistOn', true)
            ->set('anilistOn', false);

        $this->assertFalse(app(Preference::class)->enabled($this->user));
    }

    public function test_the_switch_explains_that_titles_are_sent(): void
    {
        Livewire::test('pages::search')
            ->assertOk()
            ->assertSee('Sends the titles you search to a third party');
    }

    public function test_no_resolution_is_queued_for_a_reader_who_did_not_opt_in(): void
    {
        Queue::fake();

        Livewire::test('pages::search')->set('anilistOn', false);

        Queue::assertNotPushed(ResolveAniListTitles::class);
    }

    public function test_the_adult_badge_does_not_appear_for_an_opted_out_reader(): void
    {
        $this->cached(999, 'Some Adult Thing', ['is_adult' => true]);
        $run = $this->runWithResults(1);
        SearchResult::query()->where('search_run_id', $run->id)->update([
            'title' => 'Some Adult Thing',
            'normalized_title' => 'someadultthing',
        ]);

        Livewire::test('pages::search')
            ->set('runId', $run->id)
            ->assertOk()
            ->assertDontSee('AniList: adult');
    }

    public function test_both_ratings_are_shown_and_neither_overrides_the_other(): void
    {
        app(Preference::class)->set($this->user, true);
        $this->cached(999, 'Some Adult Thing', ['is_adult' => true]);

        $run = $this->runWithResults(1);
        SearchResult::query()->where('search_run_id', $run->id)->update([
            'title' => 'Some Adult Thing',
            'normalized_title' => 'someadultthing',
        ]);

        Livewire::test('pages::search')
            ->set('runId', $run->id)
            ->assertOk()
            ->assertSee('AniList: adult')
            ->assertSee('the source rates it SAFE');
    }
}
