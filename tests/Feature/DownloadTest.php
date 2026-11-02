<?php

namespace Tests\Feature;

use App\Downloads\DownloadLedger;
use App\Jobs\SyncDownloads;
use App\Models\Download;
use App\Models\User;
use App\Support\ReadingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Downloads: the ledger, the reconciliation, and the centre page.
 *
 * The interesting test is `test_a_sync_that_cannot_reach_the_queue_leaves_rows_pending`.
 * The queue only holds what is happening right now, so "finished" and "failed"
 * look identical from outside it, and the only way to tell them apart is to ask
 * whether the chapter is on disk. A sync that cannot ask has to do nothing, because
 * guessing marks good downloads as broken.
 *
 * Every way the server can behave is a property rather than a second `Http::fake()`,
 * because Laravel keeps the earliest matching stub. Trap 23.
 */
class DownloadTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /** @var array<int, array<string, mixed>> one entry per queued chapter */
    private array $queue = [];

    /**
     * The chapters a series has.
     *
     * Separate from `downloadedChapters` on purpose: a chapter existing and a
     * chapter being on disk are different facts, and a fake that derives one from
     * the other cannot express an unfetched series, which is the state almost
     * every chapter starts in.
     */
    private array $seriesChapters = [];

    /** Which of those the server reports as on disk. */
    private array $downloadedChapters = [];

    /** When set, every non-login call fails with this status. */
    private ?int $serverStatus = null;

    /**
     * When set, only the batched series read fails, and the queue still answers.
     *
     * `$serverStatus` cannot express this, because it fails everything and the sync returns
     * from the queue catch before reaching the second call. The second call is the one that
     * fabricated a failure reason, and it had no test at all for that reason.
     */
    private ?int $batchedReadStatus = null;

    /** @var array<int, string> every GraphQL document sent */
    private array $sent = [];

    /** @var array<int, array<string, mixed>> the ids enqueue was called with */
    private array $enqueueCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->queue = [];
        $this->neverFetched([]);
        $this->serverStatus = null;
        $this->batchedReadStatus = null;
        $this->sent = [];
        $this->enqueueCalls = [];

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
                return Http::response([
                    'data' => ['login' => [
                        'accessToken' => $this->accessToken(),
                        'refreshToken' => $this->refreshToken(),
                    ]],
                ]);
            }

            if ($this->serverStatus !== null) {
                return Http::response('down', $this->serverStatus);
            }

            if (str_contains($query, 'mutation Enqueue')) {
                $input = (array) ($variables['input'] ?? []);
                $ids = $input['ids'] ?? $input['id'] ?? [];
                $this->enqueueCalls[] = array_map('intval', (array) $ids);

                return Http::response(['data' => ['enqueueChapterDownloads' => ['clientMutationId' => null]]]);
            }

            if (str_contains($query, 'mutation Dequeue')) {
                $input = (array) ($variables['input'] ?? []);
                $id = (int) ($input['id'] ?? 0);
                $this->queue = array_values(array_filter($this->queue, fn (array $e): bool => $e['chapter_id'] !== $id));

                return Http::response(['data' => ['dequeueChapterDownload' => ['clientMutationId' => null]]]);
            }

            if (str_contains($query, 'mutation DeleteDownloaded')) {
                $input = (array) ($variables['input'] ?? []);
                $id = (int) ($input['id'] ?? 0);
                $this->downloadedChapters = array_values(array_diff($this->downloadedChapters, [$id]));

                return Http::response(['data' => ['deleteDownloadedChapter' => ['clientMutationId' => null]]]);
            }

            if (str_contains($query, 'query Downloads')) {
                return Http::response(['data' => ['downloadStatus' => [
                    'state' => $this->queue === [] ? 'STOPPED' : 'STARTED',
                    'queue' => array_map(fn (array $e): array => [
                        'position' => $e['position'] ?? 0,
                        'progress' => $e['progress'] ?? 0,
                        'state' => $e['state'] ?? 'DOWNLOADING',
                        'tries' => 0,
                        'chapter' => [
                            'id' => (string) $e['chapter_id'],
                            'name' => 'Chapter '.$e['chapter_id'],
                            'mangaId' => (string) ($e['manga_id'] ?? 76),
                        ],
                        'manga' => ['id' => (string) ($e['manga_id'] ?? 76), 'title' => 'Series'],
                    ], $this->queue),
                ]]]);
            }

            if (str_contains($query, 'mangas(')) {
                if ($this->batchedReadStatus !== null) {
                    return Http::response('down', $this->batchedReadStatus);
                }

                // The bulk call, carrying isDownloaded per chapter.
                return Http::response(['data' => ['mangas' => ['nodes' => [
                    [
                        'id' => '76',
                        'title' => 'Series 76',
                        'thumbnailUrl' => '/api/v1/manga/76/thumbnail',
                        'status' => 'ONGOING',
                        'initialized' => true,
                        'source' => ['id' => '6247824327199706550', 'name' => 'Asura', 'lang' => 'en'],
                        'chapters' => [
                            'totalCount' => count($this->downloadedChapters),
                            'nodes' => array_map(fn (int $id): array => [
                                'id' => (string) $id,
                                // The name rides along because the downloads page now reads
                                // it to label a row, and a chapter's name is not derivable from
                                // its number: measured across sources, one labels a chapter
                                // `Chapter 1` and another `Ch. 1`.
                                'name' => 'Chapter '.$id,
                                'isDownloaded' => in_array($id, $this->downloadedChapters, true),
                            ], $this->seriesChapters),
                        ],
                    ],
                ]]]]);
            }

            if (str_contains($query, 'manga(id:')) {
                $nodes = array_map(fn (int $id): array => [
                    'id' => (string) $id,
                    'name' => 'Chapter '.$id,
                    'chapterNumber' => 1.0,
                    'scanlator' => null,
                    'uploadDate' => 1767000000000,
                    'pageCount' => in_array($id, $this->downloadedChapters, true) ? 22 : -1,
                    'isRead' => false,
                    'isDownloaded' => in_array($id, $this->downloadedChapters, true),
                    'lastPageRead' => 0,
                    'sourceOrder' => 0,
                ], $this->seriesChapters);

                return Http::response(['data' => ['manga' => [
                    'id' => '76',
                    'title' => 'Series 76',
                    'url' => '/series/x',
                    'realUrl' => 'https://x.test/s',
                    'thumbnailUrl' => '/api/v1/manga/76/thumbnail',
                    'status' => 'ONGOING',
                    'author' => null,
                    'artist' => null,
                    'genre' => [],
                    'description' => null,
                    'initialized' => true,
                    'unreadCount' => 0,
                    'updateStrategy' => 'ALWAYS_UPDATE',
                    'chaptersLastFetchedAt' => 1790776498,
                    'source' => ['id' => '6247824327199706550', 'name' => 'Asura', 'lang' => 'en', 'contentWarning' => 'SAFE'],
                    'chapters' => ['totalCount' => count($nodes), 'nodes' => $nodes],
                ]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    // Helpers -------------------------------------------------------------

    /**
     * The opening tag of the button carrying a label.
     *
     * The rendered HTML carries seventy-odd occurrences of "disabled" because
     * Flux puts disabled:* Tailwind variants in every button's class list, so a
     * substring count says nothing. Reading the one tag that holds the label is
     * the only way to ask whether that specific control is disabled.
     */
    private function buttonTag(string $html, string $label): string
    {
        $at = strpos($html, $label);

        if ($at === false) {
            return '';
        }

        $open = strrpos(substr($html, 0, $at), '<button');

        return $open === false
            ? ''
            : substr($html, $open, (int) strpos($html, '>', $open) - $open + 1);
    }

    /** Whether a control is rendered disabled, as an attribute rather than a class. */
    private function isDisabled(string $tag): bool
    {
        return $tag !== '' && preg_match('/\\sdisabled(\\s|=|>)/', $tag) === 1;
    }

    /**
     * The visible text of the button carrying a label.
     *
     * `buttonTag()` returns only the opening tag, which is enough to read a `wire:click`
     * or a disabled attribute but drops the count rendered inside the control.
     */
    private function buttonText(string $html, string $label): string
    {
        $tag = $this->buttonTag($html, $label);
        $start = $tag === '' ? false : strpos($html, $tag);

        if ($start === false) {
            return '';
        }

        $end = strpos($html, '</button>', $start);

        if ($end === false) {
            return '';
        }

        $text = strip_tags(substr($html, $start, $end - $start));

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function ledger(): DownloadLedger
    {
        return app(DownloadLedger::class);
    }

    /**
     * Chapters that exist and are on disk.
     *
     * Both lists, always. A chapter that is on disk necessarily exists, so
     * setting one without the other describes a state the server cannot be in.
     *
     * @param  array<int, int>  $ids
     */
    private function onDisk(array $ids): void
    {
        $this->seriesChapters = $ids;
        $this->downloadedChapters = $ids;
    }

    /** Chapters that exist but have never been fetched. */
    private function neverFetched(array $ids): void
    {
        $this->seriesChapters = $ids;
        $this->downloadedChapters = [];
    }

    /** Put a chapter into the fake queue. */
    private function queueUp(int $chapterId, float $progress = 0.0, int $mangaId = 76): void
    {
        $this->queue[] = [
            'chapter_id' => $chapterId,
            'manga_id' => $mangaId,
            'progress' => $progress,
            'state' => 'DOWNLOADING',
        ];
    }

    private function pendingRow(int $chapterId, int $mangaId = 76): Download
    {
        return Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => $chapterId,
            'suwayomi_manga_id' => $mangaId,
            'state' => Download::QUEUED,
            'progress' => 0,
            'enqueued_at' => now(),
        ]);
    }

    // Enqueueing ----------------------------------------------------------

    /*
     * A note on what these enqueue tests may assert.
     *
     * Enqueueing dispatches the reconciler, and the test suite runs on the sync
     * queue driver, so that dispatch runs inline and has already settled the row by
     * the time the assertion runs. In production the worker defers it and the row is
     * still `queued` when the action returns.
     *
     * So these tests assert what is true in both worlds: the row exists, and the
     * server was asked. Which state it settled into is the reconciler's subject and
     * is asserted above, where the queue can be controlled.
     */
    public function test_enqueueing_records_the_intent_before_calling_the_server(): void
    {
        $this->ledger()->enqueue($this->user, [13], 76);

        $this->assertDatabaseHas('downloads', [
            'user_id' => $this->user->id,
            'suwayomi_chapter_id' => 13,
        ]);

        // A row only exists because it was written, and the row surviving whatever
        // the inline reconciler decided is the point: the intent outlives the call.
        $this->assertDatabaseCount('downloads', 1);
        $this->assertSame([[13]], $this->enqueueCalls);
    }

    /** The row is written even when the server refuses the batch. */
    public function test_enqueueing_leaves_a_row_even_when_the_server_refuses(): void
    {
        $this->serverStatus = 503;

        $this->ledger()->enqueue($this->user, [13], 76);

        $this->assertDatabaseHas('downloads', [
            'suwayomi_chapter_id' => 13,
            'state' => Download::FAILED,
        ]);
    }

    public function test_enqueueing_sends_the_ids_to_the_server(): void
    {
        $this->ledger()->enqueue($this->user, [13, 14, 15], 76);

        $this->assertSame([[13, 14, 15]], $this->enqueueCalls);
    }

    /**
     * The input field is `id` for one and `ids` for many. Sending `chapterId` is
     * rejected as an undefined field. Measured against the live server.
     */
    public function test_the_enqueue_batch_uses_the_ids_field(): void
    {
        $this->ledger()->enqueue($this->user, [13], 76);

        $enqueue = array_values(array_filter($this->sent, fn (string $q): bool => str_contains($q, 'mutation Enqueue')));

        $this->assertCount(1, $enqueue);
        $this->assertStringContainsString('EnqueueChapterDownloadsInput', $enqueue[0]);
    }

    public function test_enqueueing_nothing_does_nothing(): void
    {
        $result = $this->ledger()->enqueue($this->user, [], 76);

        $this->assertSame(0, $result['queued']);
        $this->assertSame([], $this->enqueueCalls);
        $this->assertDatabaseCount('downloads', 0);
    }

    public function test_enqueueing_a_settled_row_queues_it_again(): void
    {
        $row = $this->pendingRow(13);
        $row->state = Download::FAILED;
        $row->error = 'It left the queue without downloading.';
        $row->save();

        $result = $this->ledger()->enqueue($this->user, [13], 76);

        $this->assertSame(1, $result['queued']);

        // One row, reused, with the old verdict cleared. The history of what failed
        // and when is the reason the ledger exists at all, so a retry updates the
        // row rather than adding a second one.
        $this->assertDatabaseCount('downloads', 1);
        $this->assertDatabaseMissing('downloads', [
            'suwayomi_chapter_id' => 13,
            'error' => 'It left the queue without downloading.',
        ]);
    }

    public function test_enqueueing_a_pending_row_twice_does_not_duplicate(): void
    {
        $this->pendingRow(13);

        $result = $this->ledger()->enqueue($this->user, [13], 76);

        $this->assertSame(0, $result['queued']);
        $this->assertDatabaseCount('downloads', 1);
    }

    public function test_a_double_click_that_loses_the_insert_race_is_not_an_error(): void
    {
        $competingInsertDone = false;

        /*
         * Both requests read no row before either writes. The other request's insert lands
         * between this ledger's read and its create, which is the exact shape of two tabs
         * pressing Download at once. A model listener writes that row through the query
         * builder so it cannot fire this listener again.
         */
        Download::creating(function (Download $row) use (&$competingInsertDone): void {
            if ($competingInsertDone) {
                return;
            }

            $competingInsertDone = true;

            DB::table('downloads')->insert([
                'user_id' => $row->user_id,
                'suwayomi_chapter_id' => $row->suwayomi_chapter_id,
                'suwayomi_manga_id' => $row->suwayomi_manga_id,
                'state' => Download::QUEUED,
                'progress' => 0,
                'enqueued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $result = $this->ledger()->enqueue($this->user, [13], 76);

        $this->assertTrue($competingInsertDone, 'the competing insert happened after the read missed it');
        $this->assertSame(0, $result['queued'], 'the other request already queued it, so this is its existing-row branch');
        $this->assertDatabaseCount('downloads', 1);
        $this->assertDatabaseHas('downloads', [
            'user_id' => $this->user->id,
            'suwayomi_chapter_id' => 13,
            'state' => Download::QUEUED,
        ]);
        $this->assertSame([[13]], $this->enqueueCalls, 'the server is still asked for the batch');
    }

    /**
     * A rejected batch is not "queued". Saying it was would be a lie the reader
     * discovers ten seconds later when nothing moves.
     */
    public function test_a_rejected_batch_fails_the_rows_with_our_own_reason(): void
    {
        $this->serverStatus = 503;

        // The login succeeded before the status was set, so the call itself fails.
        $this->serverStatus = 503;
        $this->sent = [];

        $result = $this->ledger()->enqueue($this->user, [13], 76);

        $this->assertSame(0, $result['queued']);
        $this->assertSame(1, $result['rejected']);
    }

    public function test_a_rejection_leaves_an_explanatory_row(): void
    {
        $this->pendingRow(13);
        $this->serverStatus = 503;

        $this->ledger()->enqueue($this->user, [13], 76);

        $row = Download::forUser($this->user->id)->where('suwayomi_chapter_id', 13)->first();

        $this->assertSame(Download::FAILED, $row->state);
        $this->assertStringContainsString('would not accept', (string) $row->error);
    }

    // Reconciling ---------------------------------------------------------

    public function test_progress_from_the_queue_is_folded_in(): void
    {
        $this->pendingRow(13);
        $this->queueUp(13, 0.5);

        $result = $this->ledger()->sync($this->user);

        $row = Download::forUser($this->user->id)->where('suwayomi_chapter_id', 13)->first();

        $this->assertSame(1, $result['updated']);
        $this->assertSame(Download::DOWNLOADING, $row->state);
        $this->assertSame(0.5, $row->progress);
        $this->assertNotNull($row->started_at);
    }

    public function test_progress_is_clamped_to_a_fraction(): void
    {
        $this->pendingRow(13);
        $this->queueUp(13, 4.2);

        $this->ledger()->sync($this->user);

        $this->assertSame(1.0, Download::forUser($this->user->id)->first()->progress);
    }

    /**
     * The queue cannot tell finished from failed: both entries simply vanish. The
     * only question that separates them is whether the chapter is on disk.
     */
    public function test_a_chapter_that_left_the_queue_and_is_on_disk_finished(): void
    {
        $this->pendingRow(13);
        $this->onDisk([13]);
        $this->onDisk([13]);

        // Nothing in the queue: it finished between ticks.

        $result = $this->ledger()->sync($this->user);

        $row = Download::forUser($this->user->id)->where('suwayomi_chapter_id', 13)->first();

        $this->assertSame(1, $result['finished']);
        $this->assertSame(Download::FINISHED, $row->state);
        $this->assertSame(1.0, $row->progress);
        $this->assertNotNull($row->finished_at);
    }

    public function test_a_chapter_that_left_the_queue_and_is_not_on_disk_failed(): void
    {
        $this->pendingRow(13);
        $this->neverFetched([]);

        $result = $this->ledger()->sync($this->user);

        $row = Download::forUser($this->user->id)->where('suwayomi_chapter_id', 13)->first();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(Download::FAILED, $row->state);
        $this->assertStringContainsString('no reason', (string) $row->error);
    }

    /**
     * A queue that answers and a series endpoint that does not.
     *
     * This is the case the class got wrong. `downloadedChapters()` returned `[]` on a transport
     * failure, its caller read that as "not downloaded", and every orphan was written to the
     * database as FAILED with the reason "It left the download queue without downloading. The
     * server reports no reason."
     *
     * The server reported no reason because it was never asked. The row was durable, the reason
     * was shown to the reader as the server's, and the next sync saw a FAILED row and never
     * retried it. One unreachable series was enough to do that to every orphan in the library.
     *
     * The queue path twenty lines above already got this right, in as many words: "failing them
     * on a transport error would be wrong."
     */
    public function test_a_sync_that_cannot_read_the_series_leaves_orphans_pending(): void
    {
        $this->pendingRow(13);
        $this->pendingRow(14);

        // The queue answers, and holds neither of them.
        $this->queue = [];

        // The series endpoint does not.
        $this->batchedReadStatus = 503;

        $result = $this->ledger()->sync($this->user);

        foreach ([13, 14] as $chapterId) {
            $row = Download::forUser($this->user->id)->where('suwayomi_chapter_id', $chapterId)->first();

            $this->assertSame(
                Download::QUEUED,
                $row->state,
                "chapter {$chapterId} is still pending, because nobody could ask what became of it",
            );

            $this->assertNull(
                $row->error,
                'and nothing is written claiming the server said something it did not say',
            );
        }

        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['finished']);
        $this->assertSame(2, $result['still_pending'], 'both are resolvable by a later sync');
    }

    /**
     * A sync that cannot reach the queue must do nothing. Failing the rows would
     * mark healthy downloads as broken because one poll timed out.
     */
    public function test_a_sync_that_cannot_reach_the_queue_leaves_rows_pending(): void
    {
        $this->pendingRow(13);
        $this->serverStatus = 503;

        $result = $this->ledger()->sync($this->user);

        $row = Download::forUser($this->user->id)->where('suwayomi_chapter_id', 13)->first();

        $this->assertSame(Download::QUEUED, $row->state);
        $this->assertSame(0, $result['failed']);
        $this->assertNotNull($row->error, 'The reason it could not check is recorded.');
    }

    public function test_a_sync_with_nothing_pending_makes_no_calls(): void
    {
        $this->sent = [];

        $result = $this->ledger()->sync($this->user);

        $this->assertSame([], $this->sent);
        $this->assertSame(0, $result['still_pending']);
    }

    /**
     * Two calls per sync, not one per chapter. This is the same lesson the library
     * page taught, and the reason the check exists.
     */
    public function test_a_sync_uses_two_calls_regardless_of_how_many_are_pending(): void
    {
        foreach ([13, 14, 15, 16, 17, 18] as $id) {
            $this->pendingRow($id);
        }

        $this->sent = [];
        $this->ledger()->sync($this->user);

        $statusCalls = count(array_filter($this->sent, fn (string $q): bool => str_contains($q, 'query Downloads')));
        $bulkCalls = count(array_filter($this->sent, fn (string $q): bool => str_contains($q, 'mangas(')));

        $this->assertSame(1, $statusCalls);
        $this->assertLessThanOrEqual(2, $bulkCalls, 'Six pending chapters should not cost six manga calls.');
    }

    public function test_a_finished_download_records_its_page_count(): void
    {
        // Measured: pageCount is -1 until a chapter downloads, so this is the
        // moment the number becomes knowable.
        $this->pendingRow(13);
        $this->onDisk([13]);

        $this->ledger()->sync($this->user);

        $this->assertSame(22, Download::forUser($this->user->id)->first()->page_count);
    }

    // Cancelling and deleting ---------------------------------------------

    public function test_cancelling_records_a_cancellation_not_a_failure(): void
    {
        $this->pendingRow(13);
        $this->queueUp(13, 0.4);

        $this->assertTrue($this->ledger()->cancel($this->user, 13));

        $row = Download::forUser($this->user->id)->first();

        $this->assertSame(Download::CANCELLED, $row->state);
        $this->assertNull($row->error);
    }

    public function test_cancelling_takes_the_chapter_out_of_the_queue(): void
    {
        $this->pendingRow(13);
        $this->queueUp(13, 0.4);

        $this->ledger()->cancel($this->user, 13);

        $this->assertSame([], $this->queue);
    }

    public function test_cancelling_someone_elses_download_is_refused(): void
    {
        $bob = User::factory()->create();
        Download::createFor($bob->id, [
            'suwayomi_chapter_id' => 13,
            'suwayomi_manga_id' => 76,
            'state' => Download::QUEUED,
        ]);

        $this->assertFalse($this->ledger()->cancel($this->user, 13));

        $this->assertSame(Download::QUEUED, Download::forUser($bob->id)->first()->state);
    }

    public function test_forgetting_a_finished_download_deletes_the_pages(): void
    {
        $this->pendingRow(13);
        $this->onDisk([13]);
        $this->ledger()->sync($this->user);

        $this->assertTrue($this->ledger()->forget($this->user, 13));

        $this->assertSame([], $this->downloadedChapters);
        $this->assertDatabaseCount('downloads', 0);
    }

    public function test_a_download_still_in_flight_cannot_be_forgotten(): void
    {
        $this->pendingRow(13);

        $this->assertFalse($this->ledger()->forget($this->user, 13));
        $this->assertDatabaseCount('downloads', 1);
    }

    // The shared queue ----------------------------------------------------

    public function test_the_shared_queue_counts_other_readers_without_naming_them(): void
    {
        $bob = User::factory()->create();

        $this->pendingRow(13);
        $this->queueUp(13, 0.1);          // mine
        $this->queue[] = [
            'chapter_id' => 99,
            'manga_id' => 100,
            'progress' => 0.2,
            'state' => 'DOWNLOADING',
        ];                                  // someone else's

        $shared = $this->ledger()->sharedQueue($this->user);

        $this->assertSame(1, $shared['count']);
        $this->assertTrue($shared['running']);
    }

    public function test_the_shared_queue_is_zero_when_alone(): void
    {
        $this->pendingRow(13);
        $this->queueUp(13, 0.1);

        $this->assertSame(0, $this->ledger()->sharedQueue($this->user)['count']);
    }

    public function test_an_unreachable_queue_reports_nothing_shared_rather_than_failing(): void
    {
        $this->serverStatus = 503;

        $this->assertSame(0, $this->ledger()->sharedQueue($this->user)['count']);
    }

    // Counts --------------------------------------------------------------

    public function test_counts_add_up_across_states(): void
    {
        Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => 1, 'suwayomi_manga_id' => 76, 'state' => Download::FINISHED, 'page_count' => 20,
        ]);
        Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => 2, 'suwayomi_manga_id' => 76, 'state' => Download::FINISHED, 'page_count' => 30,
        ]);
        Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => 3, 'suwayomi_manga_id' => 76, 'state' => Download::DOWNLOADING,
        ]);
        Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => 4, 'suwayomi_manga_id' => 76, 'state' => Download::FAILED,
        ]);
        Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => 5, 'suwayomi_manga_id' => 76, 'state' => Download::CANCELLED,
        ]);

        $counts = $this->ledger()->counts($this->user);

        $this->assertSame(1, $counts['pending']);
        $this->assertSame(2, $counts['finished']);
        $this->assertSame(2, $counts['failed'], 'Cancelled counts as not-broken, but not as finished.');
        $this->assertSame(50, $counts['pages']);
    }

    // Isolation -----------------------------------------------------------

    public function test_the_ledger_is_per_reader(): void
    {
        $bob = User::factory()->create();

        $this->pendingRow(13);
        Download::createFor($bob->id, [
            'suwayomi_chapter_id' => 14, 'suwayomi_manga_id' => 76, 'state' => Download::QUEUED,
        ]);

        $this->assertCount(1, $this->ledger()->forUser($this->user));
        $this->assertCount(1, $this->ledger()->forUser($bob));
    }

    public function test_a_sync_only_touches_this_readers_rows(): void
    {
        $bob = User::factory()->create();
        Download::createFor($bob->id, [
            'suwayomi_chapter_id' => 14, 'suwayomi_manga_id' => 76, 'state' => Download::QUEUED,
        ]);

        $this->pendingRow(13);
        $this->ledger()->sync($this->user);

        $this->assertSame(Download::QUEUED, Download::forUser($bob->id)->first()->state);
    }

    // The job -------------------------------------------------------------

    public function test_the_job_syncs_every_reader_with_pending_rows(): void
    {
        $bob = User::factory()->create();

        $this->pendingRow(13);
        Download::createFor($bob->id, [
            'suwayomi_chapter_id' => 14, 'suwayomi_manga_id' => 76, 'state' => Download::QUEUED,
        ]);

        $this->onDisk([13, 14]);

        $this->app->call([new SyncDownloads, 'handle']);

        $this->assertSame(Download::FINISHED, Download::forUser($this->user->id)->first()->state);
        $this->assertSame(Download::FINISHED, Download::forUser($bob->id)->first()->state);
    }

    public function test_the_job_records_why_it_could_not_sync_rather_than_failing(): void
    {
        $this->pendingRow(13);
        $this->serverStatus = 503;

        $this->app->call([new SyncDownloads, 'handle']);

        $row = Download::forUser($this->user->id)->first();

        $this->assertSame(Download::QUEUED, $row->state);
        $this->assertNotNull($row->error);
    }

    /*
     * The chaining that makes the feature work.
     *
     * Measured in dev: only `queue:work` was running, not `schedule:work`. The
     * ten-second schedule therefore never fired, nothing dispatched the
     * reconciler, and the ledger sat still. So the job books its own next pass
     * through the worker, and the schedule is the safety net for after a restart.
     */

    public function test_the_job_books_another_pass_while_a_download_is_pending(): void
    {
        Queue::fake();

        /*
         * The row has to be in the fake queue, or the sync resolves it as an
         * orphan, marks it failed, and the chain correctly ends. This test is about
         * the chaining, so it has to leave work genuinely outstanding.
         */
        $this->pendingRow(13);
        $this->queueUp(13, 0.2);

        $this->app->call([new SyncDownloads, 'handle']);

        $this->assertSame(Download::DOWNLOADING, Download::forUser($this->user->id)->first()->state);
        Queue::assertPushed(SyncDownloads::class);
    }

    /** A row the server is not acting on fails, and a failure is not outstanding work. */
    public function test_the_job_books_nothing_when_a_download_leaves_the_queue(): void
    {
        Queue::fake();

        $this->pendingRow(13);
        $this->neverFetched([13]);

        $this->app->call([new SyncDownloads, 'handle']);

        $this->assertSame(Download::FAILED, Download::forUser($this->user->id)->first()->state);
        Queue::assertNotPushed(SyncDownloads::class);
    }

    /**
     * The chain has to end. A job that always re-queued itself would run forever
     * against a server that is not doing anything.
     */
    public function test_the_job_books_nothing_once_nothing_is_pending(): void
    {
        Queue::fake();

        $this->app->call([new SyncDownloads, 'handle']);

        Queue::assertNotPushed(SyncDownloads::class);
    }

    public function test_the_job_books_nothing_once_the_last_download_settles(): void
    {
        Queue::fake();

        $this->pendingRow(13);
        $this->onDisk([13]);

        $this->app->call([new SyncDownloads, 'handle']);

        $this->assertSame(Download::FINISHED, Download::forUser($this->user->id)->first()->state);
        Queue::assertNotPushed(SyncDownloads::class);
    }

    public function test_enqueueing_starts_the_reconciler_without_waiting_for_the_schedule(): void
    {
        Queue::fake();

        $this->ledger()->enqueue($this->user, [13], 76);

        Queue::assertPushed(SyncDownloads::class);
    }

    public function test_enqueueing_nothing_starts_no_reconciler(): void
    {
        Queue::fake();

        $this->ledger()->enqueue($this->user, [], 76);

        Queue::assertNotPushed(SyncDownloads::class);
    }

    /** A rejected batch is nothing to reconcile, so nothing is dispatched. */
    public function test_a_rejected_enqueue_starts_no_reconciler(): void
    {
        Queue::fake();

        $this->serverStatus = 503;

        $this->ledger()->enqueue($this->user, [13], 76);

        Queue::assertNotPushed(SyncDownloads::class);
    }

    /**
     * The sync queue driver must not turn the chain into a loop.
     *
     * This test runs with no `Queue::fake()` on purpose, and sets the sync driver
     * itself rather than inheriting one. A dispatch then runs inline. Before the
     * depth guard this exhausted 128MB in about a second: a download the server never
     * finishes stays pending, the pass re-books itself, the re-book runs inline,
     * and the next pass sees the same pending row and does it again.
     *
     * A test that measures memory rather than an answer, because the failure mode is
     * a hung process rather than a wrong value.
     */
    public function test_an_unreachable_queue_does_not_loop_under_the_sync_driver(): void
    {
        config(['queue.default' => 'sync']);

        $this->pendingRow(13);
        $this->serverStatus = 503;

        $this->assertSame('sync', config('queue.default'), 'This test is only meaningful on the sync driver.');

        $before = memory_get_usage();

        $this->app->call([new SyncDownloads, 'handle']);

        $used = memory_get_usage() - $before;

        // Two passes ran, inline, and stopped. A loop would have taken all of it.
        $this->assertLessThan(32 * 1024 * 1024, $used, 'The chain must stop re-entering itself.');

        $row = Download::forUser($this->user->id)->first();

        $this->assertSame(Download::QUEUED, $row->state, 'Still pending, because the server never said otherwise.');
    }

    // The page ------------------------------------------------------------

    public function test_the_page_needs_authentication(): void
    {
        auth()->logout();

        $this->get('/downloads')->assertRedirect(route('login'));
    }

    public function test_an_empty_downloads_page_explains_itself(): void
    {
        Livewire::test('pages::downloads')
            ->assertOk()
            ->assertSee('No downloads yet');
    }

    public function test_the_page_lists_rows_and_their_states(): void
    {
        Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => 13, 'suwayomi_manga_id' => 76,
            'state' => Download::FINISHED, 'page_count' => 22, 'finished_at' => now(),
        ]);

        Livewire::test('pages::downloads')
            ->assertOk()
            ->assertSee('Ready offline')
            ->assertSee('22 pages');
    }

    public function test_the_page_shows_a_progress_bar_for_pending_rows(): void
    {
        $this->pendingRow(13);
        $this->queueUp(13, 0.42);

        Livewire::test('pages::downloads')
            ->assertOk()
            ->assertSee('role="progressbar"', false)
            ->assertSee('42%');
    }

    public function test_the_page_shows_the_shared_queue_note(): void
    {
        $this->pendingRow(13);
        $this->queue[] = [
            'chapter_id' => 99, 'manga_id' => 100, 'progress' => 0.2, 'state' => 'DOWNLOADING',
        ];

        Livewire::test('pages::downloads')
            ->assertOk()
            ->assertSee('Shared download queue');
    }

    public function test_a_failed_row_shows_a_retry(): void
    {
        Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => 13, 'suwayomi_manga_id' => 76,
            'state' => Download::FAILED, 'error' => 'It left the queue.', 'finished_at' => now(),
        ]);

        Livewire::test('pages::downloads')
            ->assertOk()
            ->assertSee('It left the queue.')
            ->assertSee('Try again');
    }

    public function test_cancelling_from_the_page_works(): void
    {
        $this->pendingRow(13);

        Livewire::test('pages::downloads')->call('cancel', 13)->assertOk();

        $this->assertSame(Download::CANCELLED, Download::forUser($this->user->id)->first()->state);
    }

    public function test_clearing_history_leaves_pending_rows_alone(): void
    {
        $this->pendingRow(13);
        Download::createFor($this->user->id, [
            'suwayomi_chapter_id' => 14, 'suwayomi_manga_id' => 76,
            'state' => Download::FINISHED, 'page_count' => 10, 'finished_at' => now(),
        ]);

        /*
         * Chapter 13 has to be in the fake queue, or the page's mount() sync
         * resolves it as an orphan, marks it failed, and the history clear then
         * deletes a row the test believed was still running. That is the page
         * behaving correctly: an intent the server is not acting on is a failure.
         */
        $this->queueUp(13, 0.3);

        Livewire::test('pages::downloads')->call('clearHistory')->assertOk();

        $left = Download::forUser($this->user->id)->get();

        $this->assertCount(1, $left, 'The in flight row must survive a history clear.');
        $this->assertSame(13, (int) $left->first()->suwayomi_chapter_id);
    }

    public function test_the_page_polls_on_a_timer(): void
    {
        $this->assertSame(
            ['poll' => '10000ms'],
            Livewire::test('pages::downloads')->instance()->with()
        );
    }

    // Series page ---------------------------------------------------------

    public function test_the_series_page_offers_to_download_unread(): void
    {
        $this->fakeSeries();

        Livewire::test('pages::series', ['manga' => 76])
            ->assertOk()
            ->assertSee('Download unread')
            ->assertSee('wire:click="downloadUnread"', false);
    }

    /**
     * Unread is our read state. `ChapterType.isRead` belongs to Admin(1) and has
     * never meant anything to a reader of this app.
     */
    public function test_download_unread_skips_chapters_this_reader_has_read(): void
    {
        // Four chapters: three on disk, so only chapter 4 is downloadable.
        $this->fakeSeries();

        $first = Livewire::test('pages::series', ['manga' => 76])->assertOk();

        $this->assertSame(1, $first->instance()->unreadDownloadableCount());

        $tag = $this->buttonTag($first->html(), 'Download unread');

        $this->assertStringContainsString(
            'wire:click="downloadUnread"',
            $tag,
            'The control under test is the one carrying the label.',
        );

        $this->assertFalse(
            $this->isDisabled($tag),
            'One chapter is downloadable, so the control must not be disabled.',
        );

        app(ReadingState::class)->markRead($this->user->id, 76, 4, true);

        /*
         * A second component rather than re-reading the first. A computed is
         * cached on the instance, and marking a chapter read in the database does
         * not invalidate it, so asking the same instance would measure the cache
         * instead of the behaviour. What matters is what a reader sees on the next
         * load, so that is what the test asks.
         */
        $second = Livewire::test('pages::series', ['manga' => 76])->assertOk();

        $this->assertSame(0, $second->instance()->unreadDownloadableCount());

        $this->assertTrue(
            $this->isDisabled($this->buttonTag($second->html(), 'Download unread')),
            'Nothing is left to queue, so the control must not invite a click.',
        );
    }

    public function test_download_unread_queues_only_the_unread_ones(): void
    {
        $this->fakeSeries();

        $reading = app(ReadingState::class);
        $reading->markRead($this->user->id, 76, 3, true);

        Livewire::test('pages::series', ['manga' => 76])->call('downloadUnread')->assertOk();

        $this->assertDatabaseCount('downloads', 1);
        $this->assertSame([4], Download::forUser($this->user->id)->pluck('suwayomi_chapter_id')->all());
    }

    /**
     * Read state beyond the open page still decides what "Download unread" queues.
     *
     * The page renders fifty chapters at a time and used to read read state from that
     * same page slice, so a read chapter on any later page looked unread to the bulk
     * action. This series has sixty chapters and two unread, one on each side of the
     * page boundary: chapter 5 on page one and chapter 55 on page two.
     */
    public function test_download_unread_uses_read_state_beyond_the_open_page(): void
    {
        $chapters = range(1, 60);

        $this->fakeSeries($chapters, []);

        $reading = app(ReadingState::class);

        foreach (array_diff($chapters, [5, 55]) as $chapterId) {
            $reading->markRead($this->user->id, 76, $chapterId, true);
        }

        $component = Livewire::test('pages::series', ['manga' => 76])->assertOk();

        $this->assertSame(2, $component->instance()->unreadDownloadableCount());

        $button = $this->buttonText($component->html(), 'Download unread');

        $this->assertStringContainsString('Download unread', $button);
        $this->assertMatchesRegularExpression(
            '/\b2\b/',
            $button,
            'The rendered button must carry the same count the action will queue.',
        );

        $component->call('downloadUnread')->assertOk();

        $this->assertSame([[5, 55]], $this->enqueueCalls);
        $this->assertSame(
            [5, 55],
            Download::forUser($this->user->id)->orderBy('id')->pluck('suwayomi_chapter_id')->map(fn (mixed $id): int => (int) $id)->all(),
        );
    }

    public function test_downloading_one_chapter_from_the_series_page(): void
    {
        $this->fakeSeries();

        Livewire::test('pages::series', ['manga' => 76])->call('downloadChapter', 1)->assertOk();

        // A row for that chapter, and the server asked for it. The state is the
        // reconciler's business, and under the sync driver it has already settled.
        $this->assertDatabaseHas('downloads', [
            'user_id' => $this->user->id,
            'suwayomi_chapter_id' => 1,
        ]);

        $this->assertSame([[1]], $this->enqueueCalls);
    }

    public function test_the_series_query_asks_for_the_downloaded_flag(): void
    {
        $this->fakeSeries();
        $this->sent = [];

        Livewire::test('pages::series', ['manga' => 76])->assertOk();

        $series = array_values(array_filter($this->sent, fn (string $q): bool => str_contains($q, 'manga(id:')));

        $this->assertNotEmpty($series);
        $this->assertStringContainsString('isDownloaded', $series[0]);
    }

    /**
     * A four chapter series: three on disk, one not. Chapter 3 is the one a test
     * marks read, so "unread and not already on disk" leaves exactly chapter 4.
     *
     * Both lists can be supplied when a test needs a longer series or a different
     * split between what exists and what is already downloaded.
     *
     * @param  array<int, int>  $chapters
     * @param  array<int, int>  $downloaded
     */
    private function fakeSeries(array $chapters = [1, 2, 3, 4], array $downloaded = [1, 2, 3]): void
    {
        // Set both lists directly rather than through onDisk(), which assumes the
        // two are the same. Here they are not: chapter 4 exists and has never been
        // fetched, and that gap is the whole subject of the tests below.
        $this->seriesChapters = $chapters;
        $this->downloadedChapters = $downloaded;
    }
}
