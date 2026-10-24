<?php

namespace Tests\Feature;

use App\Models\ChapterState;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\SourceHealth;
use App\Models\User;
use App\Models\UserSource;
use App\Support\Incognito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Incognito: a source that records nothing when the reader reads it.
 *
 * ## What is pinned here
 *
 * That opening a chapter and posting progress write nothing, while the chapter still renders
 * and the reader still reads it. Also that turning it back off restores recording, and that the
 * library is untouched throughout, because "read state only" is the scope and a preference
 * that quietly empties a library would be a much worse feature than the one asked for.
 *
 * ## Why these go through routes rather than calling ReadingState
 *
 * A test that calls `$reading->openChapter(...)` and asserts the row is absent is testing the
 * predicate, not the feature. The gate could be wired into nothing at all and every such test
 * would stay green, which is trap 121's shape exactly. So every assertion here arrives the way
 * a reader's browser arrives: a GET on the reader, then a POST on the progress endpoint.
 *
 * ## The fake is copied from the server, not tidied
 *
 * Source ids run past 2^53 and arrive as strings, measured on this box at
 * 6,247,824,327,199,706,550 for Asura Scans. A fake using a small integer would exercise a
 * different path than production, which is trap 131.
 */
class IncognitoTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private const ASURA = '6247824327199706550';

    private const MANGADEX = '6084907896154116083';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        Source::query()->create([
            'suwayomi_source_id' => self::ASURA,
            'name' => 'Asura Scans (EN)',
            'display_name' => 'Asura Scans (EN)',
            // Not nullable, and named after the Keiyoushi package rather than the site, which
            // is why a source row cannot be written without it even in a test.
            'extension_pkg_name' => 'tachiyomi-en.asurascans',
            'home_url' => 'https://asurascans.com',
            'lang' => 'en',
            'is_installed' => true,
        ]);

        Source::query()->create([
            'suwayomi_source_id' => self::MANGADEX,
            'name' => 'MangaDex (EN)',
            'display_name' => 'MangaDex (EN)',
            'extension_pkg_name' => 'tachiyomi-all.mangadex',
            'home_url' => 'https://mangadex.org',
            'lang' => 'en',
            'is_installed' => true,
        ]);

        // The manga-to-source cache is shared state, and a test that leaves a mapping behind
        // decides what the next test believes about which source a series is on.
        Cache::flush();

        $this->knownSeries = $this->manga();
        $this->knownMany = [
            ['id' => '76', 'title' => 'A Series', 'source' => ['id' => self::ASURA]],
        ];
        $this->manyFails = false;

        /*
         * A ledger row per source, because the health page's site rollup joins on
         * `source_healths` and reports "No sites match" without one. That is the page working
         * correctly on an unobserved catalogue, and it means the incognito control lives inside
         * the drill-down and is never rendered until a source has been asked about. Worth
         * knowing about the control's placement: it is not on the sources page or the settings,
         * and a reader who has never searched sees no sign of it.
         */
        foreach ([self::ASURA, self::MANGADEX] as $sourceId) {
            SourceHealth::query()->create([
                'source_id' => $sourceId,
                'name' => 'A Source',
                'lang' => 'en',
                'last_success_at' => now(),
                'total_successes' => 1,
                'total_failures' => 0,
                'avg_latency_ms' => 300,
                'is_dead' => false,
            ]);
        }

        $this->fakeSuwayomi();
    }

    /**
     * What the fake server says about a single series, per test.
     *
     * Set to null for "the server does not know this manga", which is how the unresolvable-source
     * case is expressed.
     *
     * @var array<string, mixed>|null
     */
    private ?array $knownSeries = null;

    /**
     * What the batch listing returns, per test.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $knownMany = [];

    /**
     * Whether the batch listing fails outright.
     */
    private bool $manyFails = false;

    /**
     * One fake for the whole read path, reading properties rather than being reinstalled.
     *
     * `Http::fake()` called twice does not replace the first registration, so the fake in setUp
     * wins over any stub a test installs: the setUp stub then answers every case and the test
     * passes or fails for a reason that has nothing to do with what it set up. Trap 116, and the
     * first version of this file fell into it while writing a comment saying it was careful.
     */
    private function fakeSuwayomi(): void
    {
        Http::preventStrayRequests();

        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($body, 'fetchChapterPages')) {
                return Http::response(['data' => ['fetchChapterPages' => [
                    'chapter' => ['id' => '1', 'name' => 'Chapter 1', 'pageCount' => 3],
                    'pages' => [
                        '/api/v1/manga/76/chapter/1/page/0',
                        '/api/v1/manga/76/chapter/1/page/1',
                        '/api/v1/manga/76/chapter/1/page/2',
                    ],
                ]]]);
            }

            if (str_contains($body, 'query Many')) {
                if ($this->manyFails) {
                    return Http::response([
                        'errors' => [['message' => 'java.lang.Exception: the server is down']],
                        'data' => null,
                    ]);
                }

                return Http::response(['data' => ['mangas' => ['nodes' => $this->knownMany]]]);
            }

            if (str_contains($body, 'query Series')) {
                return Http::response(['data' => ['manga' => $this->knownSeries]]);
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
                'id' => self::ASURA,
                'name' => 'Asura Scans',
                'lang' => 'en',
                'contentWarning' => 'SAFE',
            ],
            'chapters' => [
                'totalCount' => 3,
                'nodes' => [
                    ['id' => '1', 'name' => 'Chapter 1', 'chapterNumber' => 1.0, 'scanlator' => null, 'uploadDate' => '1767000000000', 'pageCount' => -1, 'isRead' => false, 'lastPageRead' => 0, 'sourceOrder' => 1],
                    ['id' => '2', 'name' => 'Chapter 2', 'chapterNumber' => 2.0, 'scanlator' => null, 'uploadDate' => '1767100000000', 'pageCount' => -1, 'isRead' => false, 'lastPageRead' => 0, 'sourceOrder' => 2],
                    ['id' => '3', 'name' => 'Chapter 3', 'chapterNumber' => 3.0, 'scanlator' => null, 'uploadDate' => '1767198093000', 'pageCount' => -1, 'isRead' => false, 'lastPageRead' => 0, 'sourceOrder' => 3],
                ],
            ],
        ];
    }

    private function goIncognito(int $sourceId = self::ASURA): void
    {
        Incognito::setFor($this->user->id, [$sourceId], true);

        // The mapping is cached by design, and the cached value is the source id rather than
        // the preference, so this is not strictly needed. It is here because a test that
        // depends on a cache surviving from setUp is a test that fails for the wrong reason.
        Cache::flush();
    }

    // ---------------------------------------------------------------- the feature

    public function test_opening_a_chapter_records_nothing_on_an_incognito_source(): void
    {
        $this->goIncognito();

        $response = $this->get('/read/76/1');

        // The chapter still renders. Suppressing the record must not suppress the reading.
        //
        // Asserted on a page image rather than on a chapter name, because the reader has no
        // chapter label anywhere in its markup: the view takes pages and a count, and the first
        // run of this asserted "Chapter 1" against a document that has never contained it.
        $response->assertOk();
        $response->assertSee('/suwayomi/api/v1/manga/76/chapter/1/page/0', escape: false);

        // Counted on the actual image `src` URLs rather than on `srcset`, because the responsive
        // widths repeat the same URLs in the srcset list and would otherwise count the same page
        // several times. The reader renders every page twice, once for vertical and once for paged,
        // and the modes share the URLs rather than re-fetching them.
        preg_match_all(
            '/src="[^"]*?\/suwayomi\/api\/v1\/manga\/76\/chapter\/1\/page\/\d+(?:\?[^\"]*)?"/',
            (string) $response->getContent(),
            $matches,
        );

        $this->assertSame(
            6,
            count($matches[0]),
            'A reader who asked for no history still gets every page: three pages, rendered once per mode.'
        );

        $this->assertSame(
            0,
            ChapterState::query()->count(),
            'Opening a chapter is the write that stamps last_read_at on the chapter and the series and moves the continue-reading pointer.'
        );

        $this->assertSame(
            0,
            SeriesState::query()->whereNotNull('last_read_at')->count(),
            'A reader who chose not to record must not appear in their own history.'
        );
    }

    public function test_posting_progress_records_nothing_on_an_incognito_source(): void
    {
        $this->goIncognito();

        $this->get('/read/76/1')->assertOk();

        $this->post('/read/76/1/progress', ['page' => 2, 'total' => 3])->assertOk();

        $this->assertSame(0, ChapterState::query()->count());
    }

    public function test_the_progress_endpoint_still_answers_when_it_records_nothing(): void
    {
        $this->goIncognito();

        $this->get('/read/76/1')->assertOk();

        // The reader's own script reads this answer and keeps going. A 500 here would stop the
        // chapter dead in front of someone who only asked to read it.
        $this->post('/read/76/1/progress', ['page' => 2, 'total' => 3])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_recording_resumes_when_the_source_is_revealed_again(): void
    {
        $this->goIncognito();
        $this->get('/read/76/1')->assertOk();
        $this->assertSame(0, ChapterState::query()->count());

        Incognito::setFor($this->user->id, [self::ASURA], false);

        $this->get('/read/76/1')->assertOk();

        $this->assertSame(
            1,
            ChapterState::query()->count(),
            'A reader who turns incognito off expects their reading to be recorded again.'
        );
    }

    public function test_incognito_on_one_source_does_not_affect_another(): void
    {
        $this->goIncognito(self::MANGADEX);

        // Manga 76 is on Asura Scans, and MangaDex is on incognito, so this must record.
        $this->get('/read/76/1')->assertOk();

        $this->assertSame(
            1,
            ChapterState::query()->count(),
            'The preference is per source. Suppressing one site must not suppress a reader\'s whole library.'
        );
    }

    public function test_another_readers_incognito_does_not_reach_this_one(): void
    {
        $other = User::factory()->create();

        Incognito::setFor($other->id, [self::ASURA], true);
        Cache::flush();

        $this->get('/read/76/1')->assertOk();

        $this->assertSame(
            1,
            ChapterState::query()->count(),
            'Incognito is per reader, for the same reason skip is: what one person records is nobody else\'s business.'
        );
    }

    public function test_the_library_is_untouched_by_incognito(): void
    {
        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => 76,
            'in_library' => true,
        ]);

        $this->goIncognito();

        $this->get('/read/76/1')->assertOk();
        $this->post('/read/76/1/progress', ['page' => 2, 'total' => 3])->assertOk();

        $this->assertSame(
            1,
            SeriesState::query()->where('in_library', true)->count(),
            'Incognito is read state only. A reader deciding to keep a series is a different gesture from reading it.'
        );
    }

    public function test_an_unresolvable_source_is_not_incognito(): void
    {
        /*
         * The failure mode this guards is the worst one the feature could have: failing closed
         * would stop recording read state for every series on a source the lookup could not
         * resolve, which is a silent loss of a reader's history rather than a privacy setting.
         */
        $this->knownSeries = null;

        $this->goIncognito(self::ASURA);

        // Asked directly rather than through the reader route, because a null manga makes the
        // reader 502 and a 502 would tell us nothing about the incognito decision.
        $this->assertFalse(
            app(Incognito::class)->suppresses($this->user->id, 76),
            'A source we cannot resolve must not silently stop a reader\'s history being kept.'
        );
    }

    // ---------------------------------------------------------------- the control

    public function test_the_health_page_offers_both_questions_and_neither_implies_the_other(): void
    {
        /*
         * The drill-down has to be opened first. Both controls live inside it, and they live
         * there for the same reason the existing Skip control does: one button retiring 61
         * MangaDex variants should not be pressable from a row that only says "61 variants".
         *
         * The first version of this asserted on the collapsed page and reported the controls
         * missing, which read as "the feature did not render" rather than "the page was
         * closed". Open the site, then look.
         */
        Livewire::actingAs($this->user)
            ->test('pages::health')
            ->assertOk()
            ->call('toggleSite', 'https://asurascans.com')
            ->assertSee(__('Stop searching this site?'))
            ->assertSee(__('Read here without leaving a trace?'));
    }

    public function test_the_two_controls_say_different_things(): void
    {
        // They are two preferences, so a reader who conflates them loses whichever one they
        // did not mean. Skip is about what the app asks for; incognito is about what it keeps.
        $html = Livewire::actingAs($this->user)
            ->test('pages::health')
            ->call('toggleSite', 'https://asurascans.com')
            ->html();

        $this->assertStringContainsString(__('Stop searching this site?'), $html);
        $this->assertStringContainsString(__('It stays installed and can be restored.'), $html);
        $this->assertStringContainsString(__('nothing sent to AniList'), $html);
    }

    public function test_the_incognito_control_names_the_library_it_will_not_touch(): void
    {
        SeriesState::createFor($this->user->id, ['suwayomi_manga_id' => 76, 'in_library' => true]);

        $html = Livewire::actingAs($this->user)
            ->test('pages::health')
            ->call('toggleSite', 'https://asurascans.com')
            ->html();

        /*
         * The count is one library series in the fake's batch, so the sentence is the singular
         * one. Asserting the plural because "1 series stay" reads wrong in prose would be
         * asserting a grammar bug rather than the behaviour, so the pattern accepts either and
         * the number is what is being checked.
         */
        $this->assertMatchesRegularExpression(
            '/Your 1 series stays? in your library/',
            $html,
            'The library count belongs in the confirmation body, where it can still change the decision.',
        );
    }

    public function test_the_control_writes_the_incognito_flag_and_not_the_disabled_one(): void
    {
        Livewire::actingAs($this->user)
            ->test('pages::health')
            ->call('incognito', 'https://asurascans.com')
            ->assertOk();

        $row = UserSource::query()->forUser($this->user->id)
            ->where('source_id', self::ASURA)
            ->first();

        $this->assertNotNull($row, 'The row is created rather than updated: a user who has never touched a source has no row, and whereIn(...)->update() would match nothing and quietly do nothing.');
        $this->assertTrue($row->incognito);
        $this->assertFalse(
            $row->disabled,
            'Incognito is read state only. Setting disabled as well would silently stop the searches too, which is a different preference the reader did not ask for.'
        );
    }

    public function test_revealing_clears_the_flag_and_keeps_the_search_preference_alone(): void
    {
        $this->goIncognito();

        Livewire::actingAs($this->user)
            ->test('pages::health')
            ->call('reveal', 'https://asurascans.com')
            ->assertOk();

        $row = UserSource::query()->forUser($this->user->id)
            ->where('source_id', self::ASURA)
            ->first();

        $this->assertNotNull($row);
        $this->assertFalse($row->incognito);
    }

    public function test_incognito_covers_every_language_variant_of_the_site(): void
    {
        // MangaDex is 61 language variants of one site in production, and a reader turning
        // incognito on means all of them. One variant would be a state nobody can act on.
        $variants = [];

        for ($i = 0; $i < 3; $i++) {
            $id = (string) (9000000000000000000 + $i);
            $variants[] = $id;

            Source::query()->create([
                'suwayomi_source_id' => $id,
                'name' => 'MangaDex (L'.$i.')',
                'display_name' => 'MangaDex (L'.$i.')',
                'extension_pkg_name' => 'tachiyomi-all.mangadex',
                'home_url' => 'https://mangadex.org',
                'lang' => 'en',
                'is_installed' => true,
            ]);
        }

        Incognito::setFor($this->user->id, $variants, true);

        $ids = Incognito::siteSourceIds('https://mangadex.org');

        $this->assertCount(4, $ids);

        foreach ($variants as $variant) {
            $this->assertTrue(
                app(Incognito::class)->sourceIsIncognito($this->user->id, $variant),
                'Every variant of the site has to be covered, or the badge on the site row lies about the rows behind it.'
            );
        }
    }

    public function test_the_library_count_is_answered_in_one_call_rather_than_one_per_series(): void
    {
        SeriesState::createFor($this->user->id, ['suwayomi_manga_id' => 76, 'in_library' => true]);

        $count = app(Incognito::class)->seriesCountFor((int) $this->user->id, [self::ASURA]);

        $this->assertSame(1, $count);

        /*
         * The measurement this shape exists for: one manga(id:) per library row measured 69ms
         * each, which is over twenty seconds for a 300-series library on a page render.
         *
         * Two calls rather than one because the token exchange is a separate request, so the
         * assertion is on the batch specifically. Reading it off the total would pass with
         * three batch calls and fail with one manga(id:) per series, which is the mistake the
         * assertion exists to catch.
         */
        Http::assertSent(function (Request $request): bool {
            return str_contains((string) $request->body(), 'query Many');
        });

        Http::assertNotSent(function (Request $request): bool {
            return str_contains((string) $request->body(), 'query Series($id: Int!)');
        });
    }

    public function test_the_library_count_survives_a_server_that_cannot_answer(): void
    {
        SeriesState::createFor($this->user->id, ['suwayomi_manga_id' => 76, 'in_library' => true]);

        $this->manyFails = true;

        $this->assertSame(
            0,
            app(Incognito::class)->seriesCountFor((int) $this->user->id, [self::ASURA]),
            'The number sits inside a confirmation body. A control that cannot render because a source is down would make the outage look like a broken page.'
        );
    }
}
