<?php

namespace Tests\Feature;

use App\Models\SeriesCover;
use App\Models\SeriesState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The login screen's showcase: the wall of covers behind the form.
 *
 * These tests are mostly about the gate. A public endpoint that describes a
 * reader's collection is only acceptable while the collection has one owner,
 * so mode, switch, and payload shape are all pinned here.
 */
class ShowcaseTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /** What the fake server knows: manga id => title. */
    private array $serverRows = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config([
            'papyrus.deployment_mode' => 'single',
            'papyrus.showcase.enabled' => true,
            'papyrus.showcase.cache_seconds' => 0,
        ]);
    }

    public function test_single_mode_with_a_library_serves_covers_without_titles(): void
    {
        $this->fakeSuwayomi();
        $this->addSeries(116, 'One Piece');
        $this->addSeries(76, 'Berserker');

        $response = $this->getJson('/showcase');

        $response->assertOk();

        $covers = $response->json('covers');

        $this->assertCount(2, $covers);

        /*
         * Looked up by manga id rather than by position. `added_at` is a second-precision
         * timestamp, so two rows created inside one second tie and SQLite is free to return
         * them in either order. This test is about the payload, and ordering has its own
         * test below.
         */
        $byManga = collect($covers)->keyBy('manga');

        $this->assertEqualsCanonicalizing(['manga', 'url'], array_keys($byManga[116]));
        $this->assertStringContainsString('/showcase/cover/116', $byManga[116]['url']);

        /*
         * The wall is art, not a readable list. The fake server knows both
         * titles, so a payload that asks for them and prints them fails here.
         */
        $this->assertStringNotContainsString('One Piece', (string) $response->getContent());
        $this->assertStringNotContainsString('Berserker', (string) $response->getContent());

        /*
         * The payload must never carry a raw server URL: the guest browser has
         * no token, and the authed proxy route would only hand it a redirect
         * to the login page for every image on the wall.
         */
        $this->assertStringNotContainsString('suwayomi/api/v1', (string) $response->getContent());
    }

    /**
     * The payload is art and ids, so it asks the manga server for nothing.
     *
     * The wall used to fetch a title batch to fill a key the login page never
     * read. Keeping the round trip out is part of the payload shape: a future
     * title field would have to bring the call back, and this test would say
     * so.
     */
    public function test_the_payload_does_not_ask_suwayomi_for_titles(): void
    {
        $this->fakeSuwayomi();
        $this->addSeries(116, 'One Piece');

        $this->getJson('/showcase')->assertOk();

        Http::assertNotSent(function (Request $request): bool {
            return str_contains((string) ($request->data()['query'] ?? ''), 'mangas(');
        });
    }

    public function test_favourites_come_before_the_rest(): void
    {
        $this->fakeSuwayomi();
        $this->addSeries(116);
        $this->addSeries(76, favourite: true);

        $covers = $this->getJson('/showcase')->json('covers');

        $this->assertSame(76, $covers[0]['manga']);
    }

    public function test_a_limited_count_bounds_the_wall(): void
    {
        config(['papyrus.showcase.count' => 3]);

        $this->fakeSuwayomi();
        for ($id = 10; $id <= 19; $id++) {
            $this->addSeries($id);
        }

        $this->assertCount(3, $this->getJson('/showcase')->json('covers'));
    }

    public function test_no_library_answers_an_empty_wall(): void
    {
        $this->fakeSuwayomi();

        $response = $this->getJson('/showcase');

        $response->assertOk()->assertExactJson(['covers' => []]);
    }

    public function test_a_row_outside_the_library_is_not_shown(): void
    {
        $this->fakeSuwayomi();
        SeriesState::factory()->for($this->user)->notInLibrary()->create(['suwayomi_manga_id' => 116]);

        $this->assertSame([], $this->getJson('/showcase')->json('covers'));
    }

    public function test_shared_mode_is_refused(): void
    {
        config(['papyrus.deployment_mode' => 'shared']);

        $this->fakeSuwayomi();
        $this->addSeries(116);

        $this->getJson('/showcase')->assertNotFound();
        $this->get('/showcase/cover/116')->assertNotFound();
    }

    public function test_the_switch_turns_the_whole_feature_off(): void
    {
        config(['papyrus.showcase.enabled' => false]);

        $this->fakeSuwayomi();
        $this->addSeries(116);

        $this->getJson('/showcase')->assertNotFound();
        $this->get('/showcase/cover/116')->assertNotFound();
    }

    public function test_the_payload_is_rate_limited_and_the_covers_are_not(): void
    {
        /*
         * The asymmetry is deliberate and it is the whole of a measured bug, so it is pinned
         * here rather than left as a comment nobody checks.
         *
         * Every rate-limited request writes: the limiter counts with `increment()`, and
         * `DatabaseStore::increment()` is a transaction that reads the counter then updates it.
         * On SQLite that is a deferred transaction upgrading to a write, which trap 129
         * reproduces twelve times out of twelve in about 3ms, so neither `busy_timeout` nor
         * WAL helps. The login page asks for eighteen covers at once because that is what the
         * wall is, and throttling each one was answering 6 of 72 as 500 in the browser, with
         * the trace inside `increment()`.
         *
         * Moving the counter to the file store was measured and rejected rather than assumed
         * away: `FileStore::increment()` is a read-modify-write with no lock, so it loses
         * counts silently, and 140 of 140 requests were served against a declared 120. A rate
         * limit that silently stops limiting is worse than a blank cover, so the covers carry
         * no limit and the payload, which is one request per page load, keeps its own.
         *
         * A test cannot prove the 500 is gone, because `database is locked` needs two real
         * processes contending on one SQLite file and the suite runs in one. It can prove the
         * routing decision, which is what this is.
         */
        $payload = Route::getRoutes()->getByName('showcase.index');
        $cover = Route::getRoutes()->getByName('showcase.cover');

        $this->assertNotNull($payload);
        $this->assertNotNull($cover);

        $this->assertContains(
            'throttle:120,1',
            $payload->gatherMiddleware(),
            'The payload is one request per page load, so it can afford a write to count itself.',
        );

        $this->assertNotContains(
            'throttle:120,1',
            $cover->gatherMiddleware(),
            'Eighteen covers arrive at once by design, and counting each one in SQLite answers 500. The cover is gated to single mode and serves an hour of public cache instead.',
        );
    }

    public function test_a_source_cover_streams_publicly(): void
    {
        $this->fakeSuwayomi();
        $this->addSeries(116);

        $response = $this->get('/showcase/cover/116');

        $response->assertOk();
        $this->assertSame('cover-bytes-116', $response->streamedContent());
        $response->assertHeader('Content-Type', 'image/webp');

        /*
         * Symfony re-serializes Cache-Control into its own directive order, so
         * the two facts are asserted separately rather than as one string.
         */
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=3600', $cache);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_custom_cover_wins_over_the_source_art(): void
    {
        $this->fakeSuwayomi();
        $this->addSeries(116);

        Storage::fake('local');
        $path = "covers/{$this->user->id}/116.png";
        Storage::disk('local')->put($path, 'custom-cover-bytes');

        SeriesCover::createFor($this->user->id, [
            'suwayomi_manga_id' => 116,
            'disk' => 'local',
            'path' => $path,
        ]);

        $response = $this->get('/showcase/cover/116');

        $response->assertOk();
        $this->assertStringContainsString('custom-cover-bytes', (string) $response->getContent());
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=600', $cache);
    }

    public function test_an_unknown_series_cover_is_a_quiet_404(): void
    {
        $this->fakeSuwayomi();

        $this->get('/showcase/cover/99999')->assertNotFound();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The same shape of fake the library tests use: login, then one batch, and
     * thumbnails served as the real server serves them.
     */
    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => [
                        'accessToken' => $this->accessToken(),
                        'refreshToken' => $this->refreshToken(),
                    ]],
                ]);
            }

            if (str_contains($query, 'mangas(')) {
                $nodes = [];

                foreach ($this->serverRows as $mangaId => $title) {
                    $nodes[] = [
                        'id' => (string) $mangaId,
                        'title' => $title,
                        'thumbnailUrl' => "/api/v1/manga/{$mangaId}/thumbnail",
                        'status' => 'ONGOING',
                        'initialized' => true,
                        'source' => ['id' => '6247824327199706550', 'name' => 'Asura Scans', 'lang' => 'en'],
                        'chapters' => ['totalCount' => 4, 'nodes' => []],
                    ];
                }

                return Http::response(['data' => ['mangas' => ['nodes' => $nodes]]]);
            }

            if (preg_match('#/manga/(\d+)/thumbnail#', $request->url(), $m) === 1
                && isset($this->serverRows[(int) $m[1]])) {
                return Http::response('cover-bytes-'.$m[1], 200, ['Content-Type' => 'image/webp']);
            }

            return Http::response('not found', 404);
        });
    }

    private function addSeries(int $mangaId, string $title = 'Series ', bool $favourite = false): void
    {
        $this->serverRows[$mangaId] = $title === 'Series ' ? 'Series '.$mangaId : $title;

        SeriesState::factory()->for($this->user)
            ->when($favourite, fn ($factory) => $factory->favourite())
            ->create(['suwayomi_manga_id' => $mangaId, 'in_library' => true]);
    }
}
