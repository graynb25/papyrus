<?php

namespace Tests\Feature;

use App\Jobs\FetchSeriesChapters;
use App\Library\CheckLedger;
use App\Models\LibraryCheck;
use App\Models\User;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The series page fetches its own chapter list, so no reader has to press Refresh to see one.
 *
 * ## The defect
 *
 * `SeriesGateway::find()` reads Suwayomi's cache and `fetchMangaAndChapters` is what fills it.
 * A series reached from search or browse had therefore never been fetched: no chapters, status
 * UNKNOWN, and the Refresh chapters button as the only way out. Measured, 19 of 20 titles on a
 * source were in that state, so it was the normal path rather than an edge case.
 *
 * ## Why the status badge is part of this
 *
 * Because they are one defect. `status` lives on the manga record, and a listing answers UNKNOWN
 * for every row until something fetches it: measured across 122 rows from five sources, all
 * UNKNOWN, and all of them real the moment the fetch ran. So the empty chapter list and the
 * word UNKNOWN were the same fact, seen twice, and one fix clears both.
 *
 * ## What these pin
 *
 * That a fetch is booked when there are no chapters, and **not** booked when there are, when the
 * series is unknown, or when the reader already has one in flight. The last one is the claim, and
 * it is why nothing prefetches the grid: there is one worker, so a grid prefetch would put twenty
 * requests in front of the series the reader actually clicked.
 */
class SeriesAutoFetchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        /*
         * No fake registered here on purpose. `Http::fake()` called twice does not replace the
         * first registration, so a fake in setUp silently wins over the one a test installs,
         * every response comes back empty, and the page renders "this series is not in the
         * catalogue" for every case in the file. Trap 116, and it cost one confusing run.
         */
    }

    /**
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array<string, mixed>
     */
    private function manga(array $chapters = [], ?string $status = 'ONGOING'): array
    {
        return [
            'id' => 76,
            'title' => 'A Series',
            'url' => '/api/v1/manga/76',
            'realUrl' => 'https://source.test/a-series',
            'thumbnailUrl' => null,
            'status' => $status,
            'author' => 'Someone',
            'artist' => null,
            'genre' => ['Action'],
            'description' => 'A description.',
            'initialized' => true,
            'unreadCount' => 0,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => '1790990083',
            'source' => ['id' => '1', 'name' => 'test', 'lang' => 'en', 'contentWarning' => 'SAFE'],
            'chapters' => ['totalCount' => count($chapters), 'nodes' => $chapters],
        ];
    }

    /**
     * One fake for the whole series path, so a test never installs a second.
     *
     * `Http::fake()` called twice does not replace the first registration, so a second call in
     * a test is silently ignored and the case under test never happens. That made the
     * claim-release test pass without ever throwing, which is the same shape as a fake that
     * asserts the property rather than the control.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     */
    private function fakeSeries(array $chapters = [], ?string $status = 'ONGOING', ?string $refreshError = null): void
    {
        Http::fake(function (Request $request) use ($chapters, $status, $refreshError) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => ['accessToken' => 't', 'refreshToken' => 'r']]]);
            }

            if (str_contains($body, 'fetchMangaAndChapters')) {
                if ($refreshError !== null) {
                    return Http::response(['errors' => [['message' => $refreshError]]]);
                }

                return Http::response(['data' => ['fetchMangaAndChapters' => [
                    'manga' => $this->manga($chapters, $status),
                    'chapters' => $chapters,
                ]]]);
            }

            if (str_contains($body, 'query Series')) {
                return Http::response(['data' => ['manga' => $this->manga($chapters, $status)]]);
            }

            return Http::response(['data' => []]);
        });
    }

    public function test_a_series_with_chapters_books_no_fetch(): void
    {
        Queue::fake();

        $this->fakeSeries([['id' => 1, 'name' => 'Chapter 1', 'chapterNumber' => -1]]);

        Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->assertOk()
            ->assertSee('Chapter 1');

        Queue::assertNotPushed(FetchSeriesChapters::class);
    }

    public function test_a_series_with_chapters_but_an_unknown_status_still_books_a_fetch(): void
    {
        /*
         * Load-bearing, and the second half of the reader's report.
         *
         * A series can hold a complete chapter list and still report UNKNOWN. One Piece on
         * MangaFire measured exactly that: 2,440 chapters cached, `initialized` true, status
         * UNKNOWN, and ONGOING the moment `fetchMangaAndChapters` ran. A gate that only asked
         * "are there chapters?" called that series finished, so the status badge stayed hidden
         * for ever and the reader was told to press Refresh for a list they already had.
         */
        Queue::fake();

        $this->fakeSeries(
            [['id' => 1, 'name' => 'Chapter 1', 'chapterNumber' => -1]],
            status: 'UNKNOWN',
        );

        $page = Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76]);

        $page->assertOk()->assertSee('Chapter 1');

        Queue::assertPushed(
            FetchSeriesChapters::class,
            null,
            'A full chapter list does not mean a full fetch. The status lives on the manga record and is still missing.'
        );
    }

    public function test_a_series_missing_only_its_status_gets_no_loading_panel(): void
    {
        Queue::fake();

        $this->fakeSeries(
            [['id' => 1, 'name' => 'Chapter 1', 'chapterNumber' => -1]],
            status: 'UNKNOWN',
        );

        Cache::put(FetchSeriesChapters::claimKey((int) $this->user->id), 1, 180);

        $page = Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76]);

        $page->assertSee('Chapter 1')
            ->assertDontSee(__('Fetching chapters'))
            ->assertSee('wire:poll', false);
    }

    public function test_a_series_missing_only_its_status_still_polls(): void
    {
        Queue::fake();

        $this->fakeSeries(
            [['id' => 1, 'name' => 'Chapter 1', 'chapterNumber' => -1]],
            status: 'UNKNOWN',
        );

        Cache::put(FetchSeriesChapters::claimKey((int) $this->user->id), 1, 180);

        $html = Livewire::actingAs($this->user)->test('pages::series', ['manga' => 76])->html();

        $this->assertStringContainsString(
            'wire:poll',
            $html,
            'The badge has to appear when the fetch lands, and without a poll it would not until a reload.'
        );
    }

    public function test_a_missing_chapter_list_books_the_thorough_fetch(): void
    {
        Queue::fake();

        $this->fakeSeries([]);

        Livewire::actingAs($this->user)->test('pages::series', ['manga' => 76]);

        Queue::assertPushed(
            FetchSeriesChapters::class,
            fn (FetchSeriesChapters $job): bool => $job->chapters === true,
            'No chapters at all means there is nothing to read, so this is the only call that helps.'
        );
    }

    public function test_a_missing_status_alone_books_the_cheap_metadata_fetch(): void
    {
        /*
         * The split that makes the widened gate affordable.
         *
         * Measured on MangaFire: `fetchMangaAndChapters` is 1,642ms to 7,142ms and scales with
         * the chapter count, while `fetchManga` alone is a flat 186ms to 555ms whatever it is.
         * Booking the thorough fetch for a series whose only gap is one word would re-read 2,440
         * chapters to fix it, on a site the reader did not ask us to scrape again.
         *
         * And this case is not rare: browsing a source resets the status of every manga it lists,
         * so a reader who browses then opens a series hits it every time.
         */
        Queue::fake();

        $this->fakeSeries(
            [['id' => 1, 'name' => 'Chapter 1', 'chapterNumber' => -1]],
            status: 'UNKNOWN',
        );

        Livewire::actingAs($this->user)->test('pages::series', ['manga' => 76]);

        Queue::assertPushed(
            FetchSeriesChapters::class,
            fn (FetchSeriesChapters $job): bool => $job->chapters === false,
        );
    }

    public function test_the_metadata_mode_does_not_write_the_chapter_ledger(): void
    {
        // The ledger's row is a chapter count and a fingerprint. A metadata fetch never reads
        // chapters, so recording it there would mark a series re-checked on the strength of a
        // call that never looked at the list.
        $this->fakeSeries(
            [['id' => 1, 'name' => 'Chapter 1', 'chapterNumber' => -1]],
            status: 'ONGOING',
        );

        (new FetchSeriesChapters((int) $this->user->id, 76, chapters: false))
            ->handle(app(CheckLedger::class));

        $this->assertDatabaseMissing('library_checks', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
        ]);
    }

    public function test_the_metadata_mode_still_repairs_the_status(): void
    {
        $this->fakeSeries([], status: 'COMPLETED');

        (new FetchSeriesChapters((int) $this->user->id, 76, chapters: false))
            ->handle(app(CheckLedger::class));

        // Asserted through the call having run rather than through the row, because the gateway
        // response is what proves it and the series page re-reads on the next poll anyway.
        // The row is still made, so a page for a series the reader never added has one to work
        // with. The message belongs in a comment: assertDatabaseHas takes a connection name as
        // its third argument, not a message, and passing one is a configuration lookup that fails
        // with a message about database connections.
        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
        ]);
    }

    public function test_a_series_with_no_chapters_books_a_fetch(): void
    {
        Queue::fake();

        $this->fakeSeries([]);

        Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->assertOk();

        Queue::assertPushed(FetchSeriesChapters::class);
    }

    public function test_a_series_with_no_chapters_shows_a_loading_state_rather_than_a_button_to_press(): void
    {
        Queue::fake();

        $this->fakeSeries([]);

        Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->assertSee(__('Fetching chapters'))
            ->assertDontSee(__('Suwayomi has not fetched this series. Use Refresh chapters, which asks the source for its chapter list.'));
    }

    public function test_the_page_polls_itself_while_a_fetch_is_in_flight(): void
    {
        Queue::fake();

        $this->fakeSeries([]);

        $html = Livewire::actingAs($this->user)->test('pages::series', ['manga' => 76])->html();

        $this->assertStringContainsString(
            'wire:poll',
            $html,
            'The page has to look again, because the work is happening in another process.'
        );
    }

    public function test_a_second_series_does_not_queue_behind_the_first(): void
    {
        Queue::fake();

        $this->fakeSeries([]);

        // Stand in for another series' fetch already being in flight for this reader.
        Cache::put(FetchSeriesChapters::claimKey((int) $this->user->id), 1, 180);

        Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->assertOk();

        Queue::assertNotPushed(FetchSeriesChapters::class);
    }

    public function test_a_reader_with_no_fetch_in_flight_can_book_one_again(): void
    {
        $this->assertTrue(FetchSeriesChapters::claim((int) $this->user->id));
        $this->assertFalse(
            FetchSeriesChapters::claim((int) $this->user->id),
            'The claim is one deep, so two tabs racing do not both book.'
        );

        FetchSeriesChapters::release((int) $this->user->id);

        $this->assertTrue(FetchSeriesChapters::claim((int) $this->user->id));
    }

    public function test_an_unknown_series_is_not_fetched(): void
    {
        Queue::fake();

        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => ['accessToken' => 't', 'refreshToken' => 'r']]]);
            }

            if (str_contains($body, 'query Series')) {
                return Http::response(['data' => ['manga' => null]]);
            }

            return Http::response(['data' => []]);
        });

        Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->assertOk()
            ->assertSee(__('This series is not in the catalogue'));

        Queue::assertNotPushed(
            FetchSeriesChapters::class,
            'A series the server does not hold is not a series with no chapters, and fetching it spends an upstream request to learn what the page already knows.'
        );
    }

    public function test_the_status_badge_is_hidden_until_the_server_knows_one(): void
    {
        Queue::fake();

        $this->fakeSeries([], status: 'UNKNOWN');

        Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->assertDontSee('UNKNOWN');
    }

    public function test_the_status_badge_appears_once_it_is_real(): void
    {
        Queue::fake();

        $this->fakeSeries([], status: 'ONGOING');

        Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->assertSee('ONGOING');
    }

    public function test_the_job_releases_the_claim_even_when_the_fetch_throws(): void
    {
        $this->fakeSeries([], refreshError: 'the source exploded');

        FetchSeriesChapters::claim((int) $this->user->id);

        $job = new FetchSeriesChapters((int) $this->user->id, 76);

        // The swallow is the point: with the sync driver a throw out of handle() would 500 the
        // page the reader is looking at, turning a missing chapter list into a broken app.
        $job->handle(app(CheckLedger::class));

        $this->assertFalse(
            FetchSeriesChapters::isClaimed((int) $this->user->id),
            'A claim left behind by a throw is a reader who can never load a chapter list again.'
        );
    }

    public function test_the_job_records_the_failure_in_the_ledger(): void
    {
        $this->fakeSeries([], refreshError: 'the source is down');

        FetchSeriesChapters::claim((int) $this->user->id);

        (new FetchSeriesChapters((int) $this->user->id, 76))->handle(app(CheckLedger::class));

        $this->assertDatabaseHas('library_checks', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
            'state' => LibraryCheck::ERROR,
        ]);
    }

    public function test_the_job_fetches_through_the_one_existing_code_path(): void
    {
        $this->fakeSeries([['id' => 1, 'name' => 'Chapter 1', 'chapterNumber' => -1]]);
        app(ReadingState::class)->series($this->user->id, 76);

        FetchSeriesChapters::claim((int) $this->user->id);

        (new FetchSeriesChapters((int) $this->user->id, 76))->handle(app(CheckLedger::class));

        $this->assertDatabaseHas('library_checks', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
            'state' => LibraryCheck::CHECKED,
        ]);

        $this->assertDatabaseHas('series_states', [
            'user_id' => $this->user->id,
            'suwayomi_manga_id' => 76,
        ]);
    }

    public function test_pressing_the_manual_retry_releases_a_claim_the_automatic_fetch_holds(): void
    {
        Queue::fake();

        $this->fakeSeries([]);
        Cache::put(FetchSeriesChapters::claimKey((int) $this->user->id), 1, 180);

        $page = Livewire::actingAs($this->user)->test('pages::series', ['manga' => 76]);

        $this->assertTrue(FetchSeriesChapters::isClaimed((int) $this->user->id));

        $page->call('refresh');

        $this->assertFalse(
            FetchSeriesChapters::isClaimed((int) $this->user->id),
            'Otherwise the reader presses the button and watches it do nothing.'
        );
    }

    /**
     * Run the fetch the way the page books it, so the ledger row under test was written by the
     * code rather than seeded by hand.
     *
     * @return Testable
     */
    private function pageAfterAFailedFetch(string $serverMessage)
    {
        $this->fakeSeries([], refreshError: $serverMessage);

        app(ReadingState::class)->series($this->user->id, 76);

        FetchSeriesChapters::claim((int) $this->user->id);
        (new FetchSeriesChapters((int) $this->user->id, 76))->handle(app(CheckLedger::class));

        $page = Livewire::actingAs($this->user)->test('pages::series', ['manga' => 76]);

        /*
         * The mount takes the claim, so the first render is the loading panel, and the job has
         * already finished by now. Releasing and re-rendering is what the reader gets on the
         * next poll tick, and it is a second request, so nothing computed on the first render
         * is reused (trap 63).
         */
        FetchSeriesChapters::release((int) $this->user->id);

        return $page->call('$refresh');
    }

    public function test_a_source_that_could_not_be_reached_is_not_reported_as_having_no_chapters(): void
    {
        Queue::fake();

        /*
         * The message is Suwayomi's own, copied from a real answer on this box rather than
         * tidied up. Every zero-chapter series rendered one sentence, so a source answering
         * this was reported as "The source has not listed any chapters for this series", which
         * is a different and far more hopeful claim: it sends a reader to a source that cannot
         * answer at all, and nothing on the page says which of the two it is.
         *
         * Measured on the dev library, 4 of 8 zero-chapter series had this recorded, and all 8
         * were told the same thing.
         */
        $this->pageAfterAFailedFetch('Cloudflare bypass currently disabled')
            ->assertOk()
            ->assertSee(__('This source could not be reached'))
            ->assertSee('Cloudflare bypass currently disabled')
            ->assertDontSee(__('The source has not listed any chapters for this series.'));
    }

    public function test_a_source_that_answered_and_had_nothing_still_says_so(): void
    {
        Queue::fake();

        /*
         * The other half, and the reason the first test is not just "show the error". A source
         * with no chapters today is a fact about the source rather than a fault, so it keeps the
         * sentence that is true for it and does not get dressed up as a failure.
         */
        $this->pageAfterAFailedFetch('java.lang.Exception: No chapters found')
            ->assertOk()
            ->assertSee(__('No chapters yet'))
            ->assertSee(__('The source has not listed any chapters for this series.'))
            ->assertDontSee(__('This source could not be reached'));
    }

    public function test_the_retry_does_not_raise_an_undefined_method(): void
    {
        /*
         * The button this feature exists for, on the one series it exists for.
         *
         * `Flux::error()` does not exist in Flux 2, so the catch branch in `refresh()` raised
         * "Call to undefined method Flux\FluxManager::error()", which 500s the Livewire request.
         * A reader whose source could not answer got a spinner and a console error where a
         * sentence would do, and the gate reported it as "the series chapter list offers read
         * toggles: 0" because the page never came back. Trap 79.
         *
         * Asserted through the component rather than by checking the method is gone, because
         * what matters is that the press produces a rendered answer.
         */
        $this->fakeSeries([], refreshError: 'Cloudflare bypass currently disabled');

        app(ReadingState::class)->series($this->user->id, 76);

        Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->call('refresh')
            ->assertOk()
            ->assertSee(__('The chapter list did not load'))
            ->assertSee('Cloudflare bypass currently disabled');
    }

    public function test_the_retry_separates_an_empty_source_from_a_failed_call(): void
    {
        /*
         * Both arrive on `loadError`, and they are different facts. "No chapters found" is the
         * source answering that it has nothing, which is an upstream condition; anything else
         * is the call not completing, which is a fault. One sentence for both was wrong for one
         * of them, and it is classified on the message for the same reason `CheckLedger` is:
         * the manual retry writes no ledger row, so there is no state to read.
         */
        $this->fakeSeries([], refreshError: 'Exception while fetching data (/fetchChapters) : No chapters found');

        Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->call('refresh')
            ->assertOk()
            ->assertSee(__('The source returned no chapters'))
            ->assertDontSee(__('The chapter list did not load'));
    }

    public function test_an_empty_source_renders_its_facts_once(): void
    {
        /*
         * The duplication measured on the dev walkthrough of 2026-10-16, then located in code:
         * two callout blocks on this page both read `emptyReason()`, and on a source that
         * answered and had nothing (`loadError` set, chapter count 0) both rendered the same
         * heading and body, so the reader read the diagnosis twice and the retry under it twice.
         * The standalone callout now renders only when there is a chapter list to pair it with.
         *
         * Counted on the rendered HTML rather than asserted with `assertSee()`, because
         * `assertSee()` answers the same against two copies, which is exactly how the duplicate
         * sat unmeasured.
         */
        $this->fakeSeries([], refreshError: 'Exception while fetching data (/fetchChapters) : No chapters found');

        $component = Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->call('refresh');

        $expected = __('The source returned no chapters');
        $seen = substr_count($component->html(), $expected);

        $this->assertSame(1, $seen, "the empty-source callout rendered {$seen} times; one card and one retry is the contract");
    }

    /**
     * F26's sweep: `series()` is a render-time read, and a server that answers
     * nothing must render as a named failure rather than a 500. The
     * not-in-catalogue callout underneath is a different verdict, and showing it
     * here would be a wrong diagnosis: the server never said the manga id was
     * unknown, it said nothing at all.
     */
    public function test_the_page_renders_when_the_server_cannot_be_read(): void
    {
        Queue::fake();

        Http::fake(function (Request $request) {
            if (str_contains((string) $request->body(), 'mutation Login')) {
                return Http::response(['data' => ['login' => ['accessToken' => 't', 'refreshToken' => 'r']]]);
            }

            return Http::response('The server is not answering.', 500);
        });

        Livewire::actingAs($this->user)
            ->test('pages::series', ['manga' => 76])
            ->assertOk()
            ->assertSee('This series could not be read')
            ->assertDontSee('This series is not in the catalogue');
    }
}
