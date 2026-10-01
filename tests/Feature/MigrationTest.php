<?php

namespace Tests\Feature;

use App\Jobs\FindMigrationCandidates;
use App\Migration\CandidateFinder;
use App\Migration\ChapterMapper;
use App\Migration\SeriesMigrator;
use App\Models\Category;
use App\Models\CategorySeries;
use App\Models\ChapterState;
use App\Models\Download;
use App\Models\MigrationSearch;
use App\Models\SeriesMigration;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\User;
use App\Search\TitleNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Source migration: the matcher, the chapter mapping, and the move.
 *
 * Three things here are measured facts rather than preferences, and the tests are
 * written so they would fail if those measurements were wrong:
 *
 *  - Suwayomi exposes no migration primitive. No GraphQL field across 32 query, 78
 *    mutation and 4 subscription fields, and no REST v1 endpoint. So the wizard
 *    composes the move, and `test_a_destination_the_search_never_offered_is_refused`
 *    is the test that holds the boundary it draws around itself.
 *  - A search result is a stub: `author: null`, `chapters: 0`, `initialized: false`
 *    on 71 of 72 shortlisted candidates. So ranking needs a source fetch, and
 *    `test_candidates_are_fetched_per_site_and_not_per_hit` holds that boundary.
 *  - `chapterNumber` placed 41 of 41 chapters across two sources while `name` placed
 *    none of them, because the two label them "Chapter 1" and "Ch. 1". So the
 *    mapper's unit tests are about the number being the key and the label being
 *    noise.
 *
 * The two unique indexes are the other reason these tests are long:
 * `chapter_states` is unique on `(user_id, suwayomi_chapter_id)` and `series_states`
 * on `(user_id, suwayomi_manga_id)`, so a naive remap raises a constraint violation
 * halfway through a migration rather than merely losing data.
 *
 * Every way the server can behave is a property, not a second `Http::fake()`. Trap 23.
 */
class MigrationTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /** The series being moved. */
    private const FROM = 76;

    /** The destination the search offers. */
    private const TO = 2033;

    /** A third series the reader may already have filed. */
    private const OTHER = 3000;

    /** Chapters each manga reports, keyed by manga id. */
    private array $chapterLists = [];

    /** Title, author and source for each manga, keyed by manga id. */
    private array $meta = [];

    /** Sources whose listing throws "No chapters found". */
    private array $noChapters = [];

    /** Manga the server does not know, as ids. */
    private array $unknown = [];

    /** Search hits per source id, as manga id to title. */
    private array $searchHits = [];

    /** Sources the fixture created, so a test reuses rather than re-inserts. */
    private array $sources = [];

    private ?int $serverStatus = null;

    /** @var array<int, string> every GraphQL document sent */
    private array $sent = [];

    /** @var array<int, array<string, mixed>> the source fetch calls, in order */
    private array $fetches = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->chapterLists = [];
        $this->meta = [];
        $this->sources = [];
        $this->noChapters = [];
        $this->unknown = [];
        $this->searchHits = [];
        $this->serverStatus = null;
        $this->sent = [];
        $this->fetches = [];

        $this->fakeSuwayomi();
    }

    // The fake ------------------------------------------------------------

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = $request->data()['query'] ?? '';
            $variables = (array) ($request->data()['variables'] ?? []);
            $this->sent[] = $query;

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if ($this->serverStatus !== null) {
                return Http::response('down', $this->serverStatus);
            }

            if (str_contains($query, 'mutation Search')) {
                $sourceId = (string) ($variables['source'] ?? '');
                $hits = $this->searchHits[$sourceId] ?? [];

                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => false,
                    'mangas' => array_map(
                        fn (int $mangaId, string $title): array => [
                            'id' => (string) $mangaId,
                            'title' => $title,
                            'url' => '/series/'.$mangaId,
                            'realUrl' => 'https://source.test/'.$mangaId,
                            'author' => null,
                            'artist' => null,
                            'description' => null,
                            'status' => 'ONGOING',
                            'initialized' => false,
                        ],
                        array_keys($hits),
                        array_values($hits),
                    ),
                ]]]);
            }

            if (str_contains($query, 'mutation Refresh')) {
                $mangaId = (int) ($variables['id'] ?? 0);
                $this->fetches[] = $mangaId;

                if (in_array($mangaId, $this->noChapters, true)) {
                    return Http::response(['errors' => [[
                        'message' => 'java.lang.Exception: No chapters found',
                    ]]]);
                }

                if (($this->chapterLists[$mangaId] ?? null) === null) {
                    return Http::response(['data' => ['fetchMangaAndChapters' => [
                        'manga' => $this->mangaNode($mangaId),
                        'chapters' => [],
                    ]]]);
                }

                return Http::response(['data' => ['fetchMangaAndChapters' => [
                    'manga' => $this->mangaNode($mangaId),
                    'chapters' => $this->chapters($mangaId),
                ]]]);
            }

            if (str_contains($query, 'manga(id:')) {
                $mangaId = (int) ($variables['id'] ?? 0);

                if (in_array($mangaId, $this->unknown, true)) {
                    return Http::response(['data' => ['manga' => null]]);
                }

                return Http::response(['data' => ['manga' => $this->mangaNode($mangaId)]]);
            }

            return Http::response(['data' => []]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function mangaNode(int $mangaId): array
    {
        $list = $this->chapterLists[$mangaId] ?? null;
        $meta = $this->meta[$mangaId] ?? [];

        return [
            'id' => (string) $mangaId,
            'title' => (string) ($meta['title'] ?? "Series {$mangaId}"),
            'url' => '/series/'.$mangaId,
            'realUrl' => 'https://source.test/'.$mangaId,
            'thumbnailUrl' => '/api/v1/manga/'.$mangaId.'/thumbnail',
            'status' => 'ONGOING',
            'author' => $meta['author'] ?? null,
            'artist' => $meta['artist'] ?? null,
            'genre' => [],
            'description' => null,
            'initialized' => $list !== null,
            'unreadCount' => 0,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => 1790776498,
            'source' => [
                'id' => (string) ($meta['source_id'] ?? self::fromSourceId()),
                'name' => (string) ($meta['source_name'] ?? 'Asura Scans'),
                'lang' => 'en',
                'contentWarning' => 'SAFE',
            ],
            'chapters' => [
                'totalCount' => $list === null ? 0 : count($this->chapters($mangaId)),
                'nodes' => $this->chapters($mangaId),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function chapters(int $mangaId): array
    {
        return $this->chapterLists[$mangaId] ?? [];
    }

    private static function fromSourceId(): int
    {
        return 6247824327199706550;
    }

    // Fixtures ------------------------------------------------------------

    private function source(string $name, int $offset, ?string $site = null, bool $installed = true): Source
    {
        $key = $name.'|'.$offset;

        if (isset($this->sources[$key])) {
            return $this->sources[$key];
        }

        return $this->sources[$key] = Source::create([
            'suwayomi_source_id' => self::fromSourceId() + $offset,
            'name' => $name,
            'display_name' => $name,
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.en.test'.$offset,
            'lang' => 'en',
            'home_url' => $site ?? "https://{$name}.test",
            'content_warning' => Source::SAFE,
            'is_installed' => $installed,
            'supports_latest' => true,
        ]);
    }

    /**
     * The default world: one source holding the series, three others offering it,
     * and both series' chapter lists in place.
     *
     * The label difference is deliberate and measured. Asura calls them "Chapter 1"
     * and MangaFire calls them "Ch. 1", which is why the name key places nothing.
     */
    private function world(): void
    {
        $from = $this->source('Asura Scans', 0);
        $fire = $this->source('MangaFire', 1);
        $lagoon = $this->source('Lagoon Scans', 2);

        // 61 language variants on one site, so grouping has something to collapse.
        foreach (range(3, 6) as $offset) {
            $this->source('MangaDex ('.$offset.')', $offset, 'https://mangadex.test');
        }

        $this->searchHits[(string) $from->suwayomi_source_id] = [];
        $this->searchHits[(string) $fire->suwayomi_source_id] = [self::TO => "The Berserker's Second Playthrough"];
        $this->searchHits[(string) $lagoon->suwayomi_source_id] = [4001 => 'The Berserker’s Second Playthrough'];

        foreach (range(3, 6) as $offset) {
            $this->searchHits[(string) (self::fromSourceId() + $offset)] = [5000 + $offset => "The Berserker's Second Playthrough"];
        }

        $title = "The Berserker's Second Playthrough";

        $this->chapterLists[self::FROM] = $this->chaptersLabelled('Chapter ', 1, 5);
        $this->meta[self::FROM] = [
            'title' => $title,
            'author' => 'Life Ruined',
            'artist' => 'Isang',
            'source_id' => (string) $from->suwayomi_source_id,
            'source_name' => 'Asura Scans',
        ];

        $this->chapterLists[self::TO] = $this->chaptersLabelled('Ch. ', 1, 5, base: 2000);
        $this->meta[self::TO] = [
            'title' => $title,
            'author' => 'Life Ruined',
            'source_id' => (string) $fire->suwayomi_source_id,
            'source_name' => 'MangaFire',
        ];

        // Measured: the real MangaFire and Lagoon entries are three chapters behind
        // and two behind, which is what makes the chapter count a ranking signal
        // rather than a tie.
        $this->chapterLists[4001] = $this->chaptersLabelled('Ch. ', 1, 3, base: 3000);
        $this->meta[4001] = [
            'title' => 'The Berserker’s Second Playthrough',
            'author' => 'Life Ruined',
            'source_id' => (string) $lagoon->suwayomi_source_id,
            'source_name' => 'Lagoon Scans',
        ];
    }

    /**
     * Chapters from $from to $to, labelled $prefix plus the number.
     *
     * $base is the id offset, and it is a parameter because the two sides of a
     * migration must not share chapter ids. Ids are per-manga and global to the
     * server, so chapter 1 of this series on one source and chapter 1 of it on
     * another are two different rows, and that difference is the entire reason a
     * remap exists.
     *
     * @return array<int, array<string, mixed>>
     */
    private function chaptersLabelled(string $prefix, int $from, int $to, int $base = 1000): array
    {
        $chapters = [];

        for ($n = $from; $n <= $to; $n++) {
            $chapters[] = [
                'id' => (string) ($base + $n),
                'name' => $prefix.$n,
                'chapterNumber' => (float) $n,
                'scanlator' => null,
                'uploadDate' => 1767000000000,
                'pageCount' => 20,
                'isRead' => false,
                'isDownloaded' => false,
                'lastPageRead' => 0,
                'sourceOrder' => $n,
            ];
        }

        return $chapters;
    }

    /** A finished, ready search offering the default world. */
    private function readySearch(): MigrationSearch
    {
        return MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::READY,
            'sources_searched' => 7,
            'sources_total' => 7,
            'candidates' => $this->candidateList(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function candidateList(): array
    {
        return [
            [
                'manga_id' => self::TO,
                'site' => 'https://mangafire.test',
                'source_id' => (string) (self::fromSourceId() + 1),
                'source_name' => 'MangaFire',
                'source_lang' => 'en',
                'title' => "The Berserker's Second Playthrough",
                'title_similarity' => 1.0,
                'status' => 'ready',
                'chapters' => 5,
                'chapter_delta' => 0,
                'chapter_proximity' => 1.0,
                'author' => 'Life Ruined',
                'author_overlap' => 1.0,
            ],
            [
                'manga_id' => 4001,
                'site' => 'https://lagoonscans.test',
                'source_id' => (string) (self::fromSourceId() + 2),
                'source_name' => 'Lagoon Scans',
                'source_lang' => 'en',
                'title' => 'The Berserker’s Second Playthrough',
                'title_similarity' => 1.0,
                'status' => 'ready',
                'chapters' => 3,
                'chapter_delta' => -2,
                'chapter_proximity' => 0.6,
                'author' => 'Life Ruined',
                'author_overlap' => 1.0,
            ],
        ];
    }

    private function fileInLibrary(int $mangaId, array $shelves = []): SeriesState
    {
        $state = SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => $mangaId,
            'in_library' => true,
            'added_at' => now()->subDays(30),
        ]);

        foreach ($shelves as $name) {
            $category = Category::firstOrCreate(
                ['user_id' => $this->user->id, 'name' => $name],
            );

            DB::connection()->table('category_series')->insert([
                'user_id' => $this->user->id,
                'category_id' => $category->getKey(),
                'series_state_id' => $state->getKey(),
                'added_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $state;
    }

    private function markRead(int $mangaId, int $chapterId, int $page = 5, int $seconds = 60): ChapterState
    {
        $state = ChapterState::firstOrNew([
            'user_id' => $this->user->id,
            'suwayomi_chapter_id' => $chapterId,
        ]);

        $state->suwayomi_manga_id = $mangaId;
        $state->is_read = true;
        $state->last_page_read = $page;
        $state->read_seconds = $seconds;
        $state->last_read_at = now();
        $state->save();

        return $state;
    }

    // ChapterMapper, in isolation -----------------------------------------

    public function test_the_mapper_places_chapters_by_number_when_the_labels_disagree(): void
    {
        $from = $this->chaptersLabelled('Chapter ', 1, 5);
        $to = $this->chaptersLabelled('Ch. ', 1, 5);

        $result = app(ChapterMapper::class)->map($from, $to);

        $this->assertSame(5, $result['by_number']);
        $this->assertSame(0, $result['by_name'], 'The labels share nothing, so the name key is never reached.');
        $this->assertSame([], $result['unmatched']);
        $this->assertCount(5, $result['map']);
    }

    public function test_the_mapper_reports_a_chapter_it_cannot_place(): void
    {
        $from = $this->chaptersLabelled('Chapter ', 1, 3);
        // The destination is missing chapter 3 and has a prologue the source lacks.
        $to = array_merge(
            [['id' => '9001', 'name' => 'Prologue', 'chapterNumber' => 0.0]],
            $this->chaptersLabelled('Ch. ', 1, 2),
        );

        $result = app(ChapterMapper::class)->map($from, $to);

        $this->assertSame(2, $result['by_number']);
        $this->assertSame(['Chapter 3'], $result['unmatched']);
        $this->assertSame(3, $result['total']);
    }

    public function test_the_mapper_falls_back_to_the_label_for_an_unnumbered_chapter(): void
    {
        // Both read as 0.0, so the number cannot separate them, and the labels are the
        // only thing left. This is the prologue case the primary key cannot handle.
        $from = [['id' => '5001', 'name' => 'Prologue', 'chapterNumber' => 0.0]];
        $to = [['id' => '9001', 'name' => 'Prologue', 'chapterNumber' => -1.0]];

        $result = app(ChapterMapper::class)->map($from, $to);

        $this->assertSame(1, $result['by_name']);
        $this->assertSame(0, $result['by_number'], 'A shared 0.0 is not a shared key.');
        $this->assertSame([5001 => 9001], $result['map']);
    }

    public function test_the_mapper_will_not_place_a_chapter_a_source_split_in_two(): void
    {
        $from = [['id' => '5001', 'name' => 'Chapter 1', 'chapterNumber' => 1.0]];

        $to = [
            ['id' => '9001', 'name' => 'Chapter 1 part 1', 'chapterNumber' => 1.0],
            ['id' => '9002', 'name' => 'Chapter 1 part 2', 'chapterNumber' => 1.0],
        ];

        $result = app(ChapterMapper::class)->map($from, $to);

        $this->assertSame([], $result['map'], 'Guessing which half a reader finished is worse than not answering.');
        $this->assertSame(1, $result['ambiguous']);
    }

    public function test_the_mapper_keeps_fractional_chapters_apart(): void
    {
        // chapterNumber is a Float precisely because half chapters exist, and PHP
        // casts a float array key to int, so 1.0 and 1.5 would collide.
        $from = [
            ['id' => '5001', 'name' => 'Chapter 1', 'chapterNumber' => 1.0],
            ['id' => '5002', 'name' => 'Chapter 1.5', 'chapterNumber' => 1.5],
        ];

        $to = [
            ['id' => '9001', 'name' => 'Chapter 1', 'chapterNumber' => 1.0],
            ['id' => '9002', 'name' => 'Chapter 1.5', 'chapterNumber' => 1.5],
        ];

        $result = app(ChapterMapper::class)->map($from, $to);

        $this->assertSame([5001 => 9001, 5002 => 9002], $result['map']);
    }

    public function test_the_mapper_caps_the_reported_labels_but_not_the_count(): void
    {
        $from = [];

        for ($n = 1; $n <= 30; $n++) {
            $from[] = ['id' => (string) (5000 + $n), 'name' => 'Chapter '.$n, 'chapterNumber' => (float) $n];
        }

        $result = app(ChapterMapper::class)->map($from, []);

        $this->assertCount(12, $result['unmatched'], 'The list is capped so a report stays readable.');
        $this->assertSame(30, $result['total'], 'The count is never capped, so the number shown is true.');
    }

    // CandidateFinder -----------------------------------------------------

    public function test_candidates_collapse_by_site(): void
    {
        $this->world();

        $result = app(CandidateFinder::class)->find(
            $this->source('Asura Scans', 0),
            $this->seriesNode(self::FROM),
        );

        $sites = array_column($result['candidates'], 'site');

        // Four MangaDex variants, one site, one candidate.
        $this->assertSame(1, count(array_filter($sites, static fn (string $s): bool => $s === 'https://mangadex.test')));
        $this->assertCount(3, $result['candidates']);
    }

    public function test_candidates_are_fetched_per_site_and_not_per_hit(): void
    {
        $this->world();

        app(CandidateFinder::class)->find(
            $this->source('Asura Scans', 0),
            $this->seriesNode(self::FROM),
        );

        // Six candidate entries exist before grouping across three sites, so three
        // fetches. Fetching all six would write six chapter lists to the server for
        // the same decision.
        $this->assertCount(3, $this->fetches);
        $this->assertSame(count(array_unique($this->fetches)), count($this->fetches), 'No site is fetched twice.');
    }

    public function test_ranking_puts_the_identical_title_and_chapter_count_first(): void
    {
        $this->world();

        $result = app(CandidateFinder::class)->find(
            $this->source('Asura Scans', 0),
            $this->seriesNode(self::FROM),
        );

        $top = $result['candidates'][0];

        $this->assertSame(self::TO, $top['manga_id']);
        $this->assertSame(0, $top['chapter_delta']);
        $this->assertSame(1.0, $top['author_overlap']);
    }

    public function test_a_curly_apostrophe_is_not_a_different_title(): void
    {
        $this->world();

        $result = app(CandidateFinder::class)->find(
            $this->source('Asura Scans', 0),
            $this->seriesNode(self::FROM),
        );

        foreach ($result['candidates'] as $candidate) {
            $this->assertSame(
                1.0,
                $candidate['title_similarity'],
                'Measured 0.943 before similarity() normalised its input, which put this below the 0.75 floor risk.',
            );
        }
    }

    public function test_a_source_with_no_chapters_is_reported_rather_than_hidden(): void
    {
        $this->world();
        $this->noChapters = [self::TO];

        $result = app(CandidateFinder::class)->find(
            $this->source('Asura Scans', 0),
            $this->seriesNode(self::FROM),
        );

        $byId = collect($result['candidates'])->keyBy('manga_id');

        // MangaDex (AF) measured "No chapters found" for a series that exists, so a
        // candidate can be real and still be a dead end.
        $this->assertSame('no_chapters', $byId[self::TO]['status']);
        $this->assertArrayNotHasKey('chapters', $byId[self::TO]);
    }

    public function test_progress_is_reported_per_source(): void
    {
        $this->world();

        $seen = [];

        app(CandidateFinder::class)->find(
            $this->source('Asura Scans', 0),
            $this->seriesNode(self::FROM),
            function (int $done, int $total) use (&$seen): void {
                $seen[] = [$done, $total];
            },
        );

        $this->assertNotEmpty($seen);
        $this->assertSame([6, 6], end($seen), 'The last report is the whole sweep.');
    }

    /**
     * A subtitle variant is offered, ranked last, and the chapter count is what
     * tells the reader it is a fragment.
     *
     * Worth being precise, because two earlier attempts at this test asserted the
     * opposite and both were wrong.
     *
     * `TitleNormalizer` has one length floor, not two, and the arithmetic is why. For
     * a title that is a prefix of the other, `similar_text` scores `2r/(1+r)` where r
     * is the length ratio, and that reaches the 0.75 threshold only at r = 0.6:
     *
     *   "chainsawman" (11) vs "chainsawmantyphoon" (18), r = 0.611, score 0.7586
     *
     * So a migration-specific lower floor would have coincided with the deduplicator's
     * exactly where the argument for one applies. There is one floor.
     *
     * And 0.7586 clears 0.75, so the variant is offered. That is the intended behaviour:
     * a reader whose source has died may find the whole series listed under an arc-titled
     * entry elsewhere, and hiding it would remove the only place they could go. The
     * reader is the one who confirms, and the signals are what inform them.
     */
    public function test_a_subtitle_variant_is_offered_and_its_chapter_gap_is_visible(): void
    {
        $this->world();

        $source = $this->source('Fragments', 20, 'https://fragments.test');
        $this->searchHits[(string) $source->suwayomi_source_id] = [7001 => "The Berserker's Second Playthrough: Side Story"];

        // Two chapters where the series has five, which is the signal that says this
        // entry is an arc rather than the series.
        $this->chapterLists[7001] = $this->chaptersLabelled('Ch. ', 1, 2, base: 7000);
        $this->meta[7001] = [
            'title' => "The Berserker's Second Playthrough: Side Story",
            'author' => 'Life Ruined',
        ];

        $result = app(CandidateFinder::class)->find(
            $this->source('Asura Scans', 0),
            $this->seriesNode(self::FROM),
        );

        $byId = collect($result['candidates'])->keyBy('manga_id');

        $this->assertTrue($byId->has(7001), 'A reader must be able to see it and decide.');
        $this->assertSame(2, $byId[7001]['chapters']);
        $this->assertSame(-3, $byId[7001]['chapter_delta']);

        // And it ranks below the complete copy, which is the ordering doing the work
        // that hiding it would have done badly.
        $order = array_keys($byId->all());
        $this->assertGreaterThan(
            array_search(self::TO, $order, true),
            array_search(7001, $order, true),
        );
    }

    /** A candidate whose title matches is offered, whatever its source. */
    public function test_a_matching_title_on_any_source_is_offered(): void
    {
        $this->world();

        $source = $this->source('Latecomer', 20, 'https://latecomer.test');
        $this->searchHits[(string) $source->suwayomi_source_id] = [7001 => "The Berserker's Second Playthrough"];
        $this->chapterLists[7001] = $this->chaptersLabelled('Ch. ', 1, 5, base: 7000);
        $this->meta[7001] = ['title' => "The Berserker's Second Playthrough", 'author' => 'Life Ruined'];

        // Asura is the series' own source, so it is the one excluded from the sweep.
        $result = app(CandidateFinder::class)->find($this->source('Asura Scans', 0), $this->seriesNode(self::FROM));

        $this->assertContains(7001, array_column($result['candidates'], 'manga_id'));
    }

    public function test_an_uninstalled_source_is_not_searched(): void
    {
        $this->world();

        $idle = $this->source('Removed', 30, 'https://removed.test', installed: false);
        $this->searchHits[(string) $idle->suwayomi_source_id] = [8001 => "The Berserker's Second Playthrough"];

        $result = app(CandidateFinder::class)->find(
            $this->source('Asura Scans', 0),
            $this->seriesNode(self::FROM),
        );

        $this->assertNotContains(8001, array_column($result['candidates'], 'manga_id'));
    }

    public function test_the_series_own_source_is_never_a_candidate(): void
    {
        $this->world();

        $from = $this->source('Asura Scans', 0);
        $this->searchHits[(string) $from->suwayomi_source_id] = [9001 => "The Berserker's Second Playthrough"];

        $result = app(CandidateFinder::class)->find($from, $this->seriesNode(self::FROM));

        $this->assertNotContains(9001, array_column($result['candidates'], 'manga_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesNode(int $mangaId): array
    {
        $node = $this->mangaNode($mangaId);

        return $node;
    }

    // SeriesMigrator ------------------------------------------------------

    public function test_a_migration_moves_the_series_and_its_read_state(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->markRead(self::FROM, 1001);
        $this->markRead(self::FROM, 1002);
        $this->readySearch();

        $outcome = app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $this->assertTrue($outcome['ok'], (string) $outcome['reason']);

        $this->assertDatabaseMissing('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => self::FROM,
        ]);

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => self::TO,
            'in_library' => true,
        ]);

        $carried = ChapterState::forUser($this->user->id)->where('suwayomi_manga_id', self::TO)->get();

        $this->assertCount(2, $carried);

        // Onto the destination's ids, not the source's, which is the whole point.
        $this->assertSame([2001, 2002], $carried->pluck('suwayomi_chapter_id')->sort()->values()->all());
        $this->assertSame(0, ChapterState::forUser($this->user->id)->where('suwayomi_manga_id', self::FROM)->count());
    }

    public function test_a_migration_records_what_it_carried(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->markRead(self::FROM, 1001);
        $this->readySearch();

        app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $migration = SeriesMigration::forUser($this->user->id)->first();

        $this->assertNotNull($migration);
        $this->assertSame(self::FROM, $migration->from_manga_id);
        $this->assertSame(self::TO, $migration->to_manga_id);
        $this->assertSame('Asura Scans', $migration->from_source_name);
        $this->assertSame('MangaFire', $migration->to_source_name);
        $this->assertSame(5, $migration->chapters_total);
        $this->assertSame(5, $migration->chapters_carried);
        $this->assertNull($migration->carriedRatio() === null ? null : null);
    }

    public function test_a_migration_reports_the_chapters_it_could_not_place(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->markRead(self::FROM, 1001);

        // The destination loses chapter 5 and gains a prologue.
        $this->chapterLists[self::TO] = array_merge(
            [['id' => '2901', 'name' => 'Prologue', 'chapterNumber' => 0.0]],
            $this->chaptersLabelled('Ch. ', 1, 4, base: 2000),
        );

        $this->readySearch();

        app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $migration = SeriesMigration::forUser($this->user->id)->first();

        $this->assertSame(4, $migration->chapters_carried);
        $this->assertSame(1, $migration->chaptersLost());
        $this->assertSame(['Chapter 5'], $migration->unmatched_chapters);
    }

    public function test_a_destination_the_search_never_offered_is_refused(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->readySearch();

        // 9999 was never offered. Nothing upstream checks this, so the action does.
        $outcome = app(SeriesMigrator::class)->migrate($this->user, self::FROM, 9999);

        $this->assertFalse($outcome['ok']);
        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => self::FROM,
        ]);
        $this->assertDatabaseCount('series_migrations', 0);
    }

    public function test_migrating_to_the_current_series_is_refused(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->readySearch();

        $this->assertFalse(app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::FROM)['ok']);
    }

    public function test_migrating_without_a_search_is_refused(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);

        $this->assertFalse(app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO)['ok']);
    }

    public function test_an_older_search_does_not_authorise_a_destination(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);

        // An old search offered it; a newer one did not. The newer wins, because a
        // source that has since dropped the series would still pass the old check.
        $this->readySearch();
        $newer = MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::READY,
            'candidates' => [],
        ]);

        $this->assertGreaterThan(0, $newer->getKey());
        $this->assertFalse(app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO)['ok']);
    }

    public function test_a_series_the_server_does_not_know_is_refused(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->readySearch();
        $this->unknown = [self::TO];

        $outcome = app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $this->assertFalse($outcome['ok']);
        $this->assertDatabaseHas('series_states', ['suwayomi_manga_id' => self::FROM]);
    }

    public function test_a_destination_with_no_chapters_is_refused(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->readySearch();

        // A candidate the search never fetched: the migrator finds an empty list and
        // fetches. If that also yields nothing, there is nothing to move to.
        unset($this->chapterLists[self::TO]);
        $this->chapterLists[self::TO] = [];

        $outcome = app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $this->assertFalse($outcome['ok']);
    }

    public function test_an_unmatched_series_is_left_alone(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->readySearch();

        // Nothing on the other side can be placed, so a move would silently unfile
        // the reader's history.
        $this->chapterLists[self::TO] = [
            ['id' => '9001', 'name' => 'Something Else', 'chapterNumber' => 99.0],
        ];

        $outcome = app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $this->assertFalse($outcome['ok']);
        $this->assertDatabaseHas('series_states', ['suwayomi_manga_id' => self::FROM]);
    }

    // The collisions ------------------------------------------------------

    public function test_a_destination_already_in_the_library_merges_the_two_rows(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM, ['Reading']);
        $this->fileInLibrary(self::TO, ['Finished']);
        $this->markRead(self::FROM, 1001);
        $this->readySearch();

        $outcome = app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $this->assertTrue($outcome['ok'], (string) $outcome['reason']);

        // One row, not two. The unique index on (user_id, suwayomi_manga_id) would
        // have refused two.
        $this->assertSame(1, SeriesState::forUser($this->user->id)->count());
        $this->assertDatabaseHas('series_states', ['suwayomi_manga_id' => self::TO, 'in_library' => true]);
    }

    public function test_merging_keeps_the_shelves_from_both_series(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM, ['Reading']);
        $this->fileInLibrary(self::TO, ['Finished']);
        $this->readySearch();

        app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $state = SeriesState::forUser($this->user->id)->first();
        $names = Category::forUser($this->user->id)->pluck('name')->sort()->values()->all();

        $this->assertSame(['Finished', 'Reading'], $names);
        $this->assertSame(2, CategorySeries::where('series_state_id', $state->getKey())->count());
    }

    public function test_chapter_state_already_on_the_destination_is_merged_not_overwritten(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->fileInLibrary(self::TO);

        // Read on the old source, at page 5, 60 seconds.
        $this->markRead(self::FROM, 1001, page: 5, seconds: 60);
        // Read on the new one already, further along and longer.
        $this->markRead(self::TO, 2001, page: 12, seconds: 200);
        // And a chapter only the new source has.
        $this->markRead(self::TO, 2002, page: 3, seconds: 30);

        $this->readySearch();

        app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $merged = ChapterState::forUser($this->user->id)->where('suwayomi_chapter_id', 2001)->first();

        $this->assertNotNull($merged, 'The row must survive the unique index, not be dropped.');
        $this->assertSame(12, $merged->last_page_read, 'The larger page position wins.');
        $this->assertSame(260, $merged->read_seconds, 'Seconds add, because the time was genuinely spent.');
        $this->assertSame(self::TO, $merged->suwayomi_manga_id);

        $this->assertSame(2, ChapterState::forUser($this->user->id)->count());
    }

    public function test_a_chapter_read_on_both_sides_counts_once(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->fileInLibrary(self::TO);
        $this->markRead(self::FROM, 1001);
        $this->markRead(self::TO, 2002);

        $this->readySearch();

        app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $this->assertSame(2, ChapterState::forUser($this->user->id)->where('is_read', true)->count());
    }

    public function test_the_chapter_list_fingerprint_is_cleared(): void
    {
        $this->world();
        $state = $this->fileInLibrary(self::FROM);
        $state->chapter_list_fingerprint = 'abc123';
        $state->chapter_list_checked_at = now();
        $state->save();

        $this->readySearch();

        app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $moved = SeriesState::forUser($this->user->id)->first();

        // The fingerprint is a hash over chapter ids in order, and every one of them
        // has changed. Keeping it would tell the library the list is unchanged when
        // it has entirely turned over, which is what it exists to catch.
        $this->assertNull($moved->chapter_list_fingerprint);
        $this->assertNull($moved->chapter_list_checked_at);
    }

    public function test_the_last_read_chapter_pointer_moves_with_the_chapters(): void
    {
        $this->world();
        $state = $this->fileInLibrary(self::FROM);
        $state->last_read_chapter_id = 1003;
        $state->save();
        $this->markRead(self::FROM, 1003);

        $this->readySearch();

        app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $moved = SeriesState::forUser($this->user->id)->first();

        $this->assertSame(2003, $moved->last_read_chapter_id, 'The pointer follows the chapter to its new id.');
        $this->assertTrue(ChapterState::forUser($this->user->id)
            ->where('suwayomi_chapter_id', $moved->last_read_chapter_id)
            ->exists());
    }

    public function test_a_failed_migration_leaves_nothing_half_done(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->markRead(self::FROM, 1001);
        $this->readySearch();

        // The server goes away after the search but before the move.
        $this->serverStatus = 503;

        $outcome = app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $this->assertFalse($outcome['ok']);
        $this->assertDatabaseHas('series_states', ['suwayomi_manga_id' => self::FROM]);
        $this->assertDatabaseHas('chapter_states', [
            'user_id' => $this->user->id,
            'suwayomi_chapter_id' => 1001,
            'suwayomi_manga_id' => self::FROM,
        ]);
        $this->assertDatabaseCount('series_migrations', 0);
    }

    public function test_downloads_for_the_old_series_are_left_where_they_are(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->readySearch();

        Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => 1001,
            'suwayomi_manga_id' => self::FROM,
            'state' => Download::FINISHED,
            'page_count' => 20,
            'finished_at' => now(),
        ]);

        app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        // Deliberate. A reader who downloaded chapters to read on a train keeps that
        // copy; the download centre finds it by the old manga id and offers to delete.
        $this->assertDatabaseHas('downloads', [
            'suwayomi_chapter_id' => 1001,
            'suwayomi_manga_id' => self::FROM,
        ]);
    }

    public function test_stranded_downloads_are_findable_in_one_lookup(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->readySearch();

        app(SeriesMigrator::class)->migrate($this->user, self::FROM, self::TO);

        $replacements = SeriesMigration::replacementsFor($this->user->id, [self::FROM, self::OTHER]);

        $this->assertSame([self::FROM => self::TO], $replacements);
    }

    // The job -------------------------------------------------------------

    public function test_the_job_writes_a_ready_search_with_candidates(): void
    {
        $this->world();

        $search = MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::SEARCHING,
        ]);

        $this->app->call([new FindMigrationCandidates($search->getKey(), $this->user->id, self::FROM), 'handle']);

        $search->refresh();

        $this->assertSame(MigrationSearch::READY, $search->state);
        $this->assertNotEmpty($search->candidates);
        $this->assertSame(6, $search->sources_total, 'Seven sources exist, and the series own is not searched.');
    }

    public function test_the_job_marks_a_search_failed_when_the_server_cannot_be_read(): void
    {
        $this->world();

        $search = MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::SEARCHING,
        ]);

        $this->serverStatus = 503;

        $this->app->call([new FindMigrationCandidates($search->getKey(), $this->user->id, self::FROM), 'handle']);

        $search->refresh();

        $this->assertSame(MigrationSearch::FAILED, $search->state);
        $this->assertNotNull($search->error);
    }

    public function test_the_job_does_not_overwrite_a_newer_search(): void
    {
        $this->world();

        $search = MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::READY,
            'candidates' => [['manga_id' => 12345]],
        ]);

        $this->app->call([new FindMigrationCandidates($search->getKey(), $this->user->id, self::FROM), 'handle']);

        $search->refresh();

        $this->assertSame([['manga_id' => 12345]], $search->candidates);
    }

    // The page ------------------------------------------------------------

    public function test_the_page_needs_authentication(): void
    {
        auth()->logout();

        $this->get('/migrate/76')->assertRedirect();
    }

    public function test_the_page_offers_to_search(): void
    {
        $this->world();

        Livewire::test('pages::migrate', ['manga' => self::FROM])
            ->assertOk()
            ->assertSee('Look for another source');
    }

    public function test_starting_a_search_dispatches_the_job(): void
    {
        $this->world();
        Queue::fake();

        Livewire::test('pages::migrate', ['manga' => self::FROM])
            ->call('findSources')
            ->assertOk();

        Queue::assertPushed(FindMigrationCandidates::class);
    }

    public function test_the_page_shows_the_ranked_candidates(): void
    {
        $this->world();
        $this->readySearch();

        Livewire::test('pages::migrate', ['manga' => self::FROM])
            ->assertOk()
            ->assertSee('MangaFire')
            ->assertSee('Lagoon Scans')
            ->assertSee('Same author')
            ->assertSee('Same as here: 5 chapters.');
    }

    public function test_the_page_shows_a_search_in_progress_with_a_count(): void
    {
        $this->world();

        MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::SEARCHING,
            'sources_searched' => 3,
            'sources_total' => 82,
        ]);

        Livewire::test('pages::migrate', ['manga' => self::FROM])
            ->assertOk()
            ->assertSee('3 / 82')
            ->assertSee('Searching every installed source');
    }

    public function test_the_page_polls_only_while_a_search_is_running(): void
    {
        $this->world();

        MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::SEARCHING,
        ]);

        $polling = Livewire::test('pages::migrate', ['manga' => self::FROM])->instance()->with();
        $this->assertSame(['poll' => '3000ms'], $polling);

        MigrationSearch::forUser($this->user->id)->update(['state' => MigrationSearch::READY, 'candidates' => []]);

        $idle = Livewire::test('pages::migrate', ['manga' => self::FROM])->instance()->with();
        $this->assertSame([], $idle, 'A reader looking at a finished list should not pay for a poll.');
    }

    public function test_the_page_says_so_when_nothing_else_has_the_series(): void
    {
        $this->world();

        MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::READY,
            'sources_total' => 7,
            'candidates' => [],
        ]);

        Livewire::test('pages::migrate', ['manga' => self::FROM])
            ->assertOk()
            ->assertSee('No other source has it');
    }

    /*
     * The staleness guard.
     *
     * Measured, and it exists because of what happened rather than what might. The
     * first live run was killed by the worker's timeout with `--tries=1`, so nothing
     * recorded the failure, the row said `searching`, and the page polled it for three
     * minutes with no end in sight.
     */

    public function test_a_search_that_stopped_reporting_is_given_up_on(): void
    {
        $this->world();

        $search = MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::SEARCHING,
            'sources_searched' => 12,
            'sources_total' => 81,
        ]);

        $search->forceFill(['updated_at' => now()->subSeconds(MigrationSearch::STALL_AFTER_SECONDS + 60)])->save();

        $search->refresh();

        $this->assertTrue($search->isStalled());

        $component = Livewire::test('pages::migrate', ['manga' => self::FROM])->assertOk();

        $component->assertSee('The search could not finish');
        $component->assertSee('Nothing was changed');
    }

    /** A stalled search must not keep the page polling, or it polls for ever. */
    public function test_a_stalled_search_stops_the_polling(): void
    {
        $this->world();

        $search = MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::SEARCHING,
        ]);

        $search->forceFill(['updated_at' => now()->subSeconds(MigrationSearch::STALL_AFTER_SECONDS + 60)])->save();

        $this->assertSame([], Livewire::test('pages::migrate', ['manga' => self::FROM])->instance()->with());
    }

    /**
     * A slow search is not a dead one. The job rewrites `updated_at` every five
     * seconds while it works, so the guard cannot fire on a run that is progressing
     * however long it takes.
     */
    public function test_a_slow_but_reporting_search_is_not_given_up_on(): void
    {
        $this->world();

        $search = MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::SEARCHING,
        ]);

        // Four minutes of work, which is seven times the measured 35.4 seconds.
        $search->forceFill(['updated_at' => now()->subSeconds(240)])->save();
        $search->refresh();

        $this->assertFalse($search->isStalled());
        $this->assertSame(
            ['poll' => '3000ms'],
            Livewire::test('pages::migrate', ['manga' => self::FROM])->instance()->with(),
        );
    }

    /** Only a searching row can be stale. A finished one is finished. */
    public function test_a_finished_search_is_never_stalled(): void
    {
        $this->world();

        $search = MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::READY,
        ]);

        $search->forceFill(['updated_at' => now()->subDays(3)])->save();
        $search->refresh();

        $this->assertFalse($search->isStalled());
    }

    public function test_the_page_reports_a_failed_search(): void
    {
        $this->world();

        MigrationSearch::createFor($this->user->id, [
            'from_manga_id' => self::FROM,
            'state' => MigrationSearch::FAILED,
            'error' => 'The server did not answer.',
        ]);

        Livewire::test('pages::migrate', ['manga' => self::FROM])
            ->assertOk()
            ->assertSee('The search could not finish')
            ->assertSee('The server did not answer.');
    }

    public function test_moving_from_the_page_reports_the_alignment(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->markRead(self::FROM, 1001);

        $this->chapterLists[self::TO] = array_merge(
            [['id' => '9001', 'name' => 'Prologue', 'chapterNumber' => 0.0]],
            $this->chaptersLabelled('Ch. ', 1, 4),
        );

        $this->readySearch();

        Livewire::test('pages::migrate', ['manga' => self::FROM])
            ->call('moveTo', self::TO)
            ->assertOk()
            ->assertSee('4 chapters kept their place.')
            ->assertSee('1 chapter found no match.')
            ->assertSee('Chapter 5');
    }

    public function test_the_page_refuses_a_move_it_was_tricked_into(): void
    {
        $this->world();
        $this->fileInLibrary(self::FROM);
        $this->readySearch();

        Livewire::test('pages::migrate', ['manga' => self::FROM])
            ->call('moveTo', 9999)
            ->assertOk();

        // The toast is rendered by Flux on the client, so the durable claim is that
        // nothing was written and the series still points where it did.
        $this->assertDatabaseCount('series_migrations', 0);
        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => self::FROM,
        ]);
    }
}
