<?php

namespace Tests\Feature;

use App\Import\ImportedChapter;
use App\Import\ImportedSeries;
use App\Import\Resolution;
use App\Import\SeriesWriter;
use App\Models\ImportedSeries as LedgerRow;
use App\Models\ImportedSource;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\ReadingState;
use App\Suwayomi\SeriesGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Writing an imported series, and the two things that make a re-import safe.
 *
 * **Chapters pair by sourceOrder.** Measured on a real series both sides hold: 92 chapters,
 * `source_order` 1..92 on both, 92 of 92 pairing, 92 of 92 read chapters pairing. This suite
 * reproduces that shape and checks the pairing, the fallback, and what happens to a chapter
 * that only one side has.
 *
 * **Writes are forward only.** A read is never cleared and a bookmark is never unset, because
 * those are the two changes a reader cannot undo. The resume pointer moves forward and only
 * forward, because a pointer that moved backwards would send a reader to a chapter they have
 * already passed.
 *
 * **A second import is a delta.** The ledger's fingerprint is what makes that a claim rather
 * than a hope: same backup twice must apply nothing the second time, and a backup with one
 * more read chapter must apply exactly that one series.
 */
class SeriesWriterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->sent = [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $chapters  Suwayomi's chapter list.
     */
    private function writer(array $chapters = []): SeriesWriter
    {
        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true) ?: [];
            $this->sent[] = $body;
            $document = (string) ($body['query'] ?? '');

            if (str_contains($document, 'login(input')) {
                return Http::response(['data' => ['login' => ['accessToken' => 'a-token']]]);
            }

            /*
             * The refresh, which is what has to happen for a stub.
             *
             * Answered before the `chapters {` branch below because that substring matches
             * this document too, and a test whose fake answers the wrong branch is a test
             * asserting against a shape nothing produces.
             */
            if (str_contains($document, 'mutation Refresh')) {
                return Http::response(['data' => ['fetchMangaAndChapters' => [
                    'manga' => [
                        'id' => '900', 'title' => 'A Series', 'url' => '/comics/x/', 'realUrl' => null,
                        'thumbnailUrl' => null, 'status' => 0, 'author' => null, 'artist' => null,
                        'genre' => [], 'description' => null, 'initialized' => true,
                        'unreadCount' => 0, 'updateStrategy' => null, 'chaptersLastFetchedAt' => null,
                        'source' => ['id' => '2848420679472350044', 'name' => 'Thunder Scans',
                            'lang' => 'en', 'contentWarning' => null],
                    ],
                    'chapters' => $this->suwayomiChapters,
                ]]]);
            }

            if (str_contains($document, 'chapters {')) {
                $stub = $this->findIsStub;

                return Http::response(['data' => ['manga' => [
                    'id' => '900', 'title' => 'A Series', 'url' => '/comics/x/', 'realUrl' => null,
                    'thumbnailUrl' => null, 'status' => 0, 'author' => null, 'artist' => null,
                    'genre' => [], 'description' => null, 'initialized' => ! $stub,
                    'unreadCount' => 0, 'updateStrategy' => null, 'chaptersLastFetchedAt' => null,
                    'source' => ['id' => '2848420679472350044', 'name' => 'Thunder Scans',
                        'lang' => 'en', 'contentWarning' => null],
                    'chapters' => $stub
                        ? ['totalCount' => 0, 'nodes' => []]
                        : ['totalCount' => 92, 'nodes' => $this->suwayomiChapters],
                ]]]);
            }

            return Http::response(['data' => []]);
        });

        return new SeriesWriter(
            $this->app->make(ReadingState::class),
            $this->app->make(SeriesGateway::class),
        );
    }

    /** @var array<int, array<string, mixed>> */
    private array $suwayomiChapters = [];

    /**
     * Whether `manga(id:)` answers with a chapter list, or with a stub.
     *
     * A stub is what a manga entry the server created from a search answers with, and it is
     * the shape that lost the read state. Measured against the real backup: 31 of 40 imported
     * series answered this way, so the pairing had nothing to join on for most of a library and
     * every read chapter was dropped.
     */
    private bool $findIsStub = false;

    /**
     * Build a chapter list shaped like the measured one: ids that are nothing like the
     * backup's, and sourceOrder that matches them.
     *
     * @return Collection<int, ImportedChapter>
     */
    private function backupChapters(int $count = 92, array $readUpTo = []): Collection
    {
        $chapters = new Collection;

        for ($order = 1; $order <= $count; $order++) {
            $chapters->push(new ImportedChapter(
                backupId: 300 + $order,
                number: (float) $order,
                name: "Chapter {$order}",
                sourceOrder: $order,
                isRead: in_array($order, $readUpTo, true),
                isBookmarked: $order === 2,
                lastPageRead: 0,
            ));
        }

        return $chapters;
    }

    private function backupSeries(Collection $chapters, ?int $resumeBackupId = null): ImportedSeries
    {
        return new ImportedSeries(
            backupId: 217,
            sourceId: 2_848_420_679_472_350_044,
            url: '/comics/x',
            title: 'A Series',
            backupResumeChapterId: $resumeBackupId,
            chapters: $chapters,
        );
    }

    private function resolved(int $mangaId = 900): Resolution
    {
        return Resolution::fromTier(1, ['id' => $mangaId, 'title' => 'A Series', 'matches' => 1]);
    }

    public function test_it_files_the_series_and_marks_the_read_chapters_read(): void
    {
        $this->suwayomiChapters = $this->theirs(92);

        $writer = $this->writer();
        $result = $writer->write($this->user, $this->backupSeries($this->backupChapters(92, range(1, 40))), $this->resolved());

        $this->assertTrue($result['written']);
        $this->assertSame(40, $result['read_chapters']);

        $state = $this->app->make(ReadingState::class)->findSeries($this->user, 900);
        $this->assertTrue($state->in_library);

        $marked = DB::table('chapter_states')
            ->where('user_id', $this->user->id)
            ->where('is_read', true)
            ->count();

        $this->assertSame(40, $marked, '40 chapters were read in the backup, and no more');
    }

    /**
     * The pairing is the whole point, so it is checked against a deliberately mismatched id set.
     */
    public function test_chapters_pair_on_source_order_not_on_id(): void
    {
        // Suwayomi numbers its chapters 5001.., nothing like the backup's 301..
        $this->suwayomiChapters = $this->theirs(92, 5000);

        $writer = $this->writer();
        $result = $writer->write($this->user, $this->backupSeries($this->backupChapters(92, [1, 2, 3])), $this->resolved());

        $this->assertSame(3, $result['read_chapters']);

        // The read state must land on the chapters with sourceOrder 1, 2 and 3, which are
        // the first three Suwayomi ids, and on nothing else.
        $readIds = DB::table('chapter_states')
            ->where('user_id', $this->user->id)
            ->where('is_read', true)
            ->pluck('suwayomi_chapter_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([5000, 5001, 5002], $readIds);
    }

    public function test_a_chapter_only_the_backup_has_is_skipped_without_failing_the_series(): void
    {
        // Suwayomi has fewer chapters than the backup, which is normal.
        $this->suwayomiChapters = $this->theirs(39, 5000);

        $writer = $this->writer();
        $result = $writer->write($this->user, $this->backupSeries($this->backupChapters(92, range(1, 45))), $this->resolved());

        $this->assertTrue($result['written'], 'the series is still filed');
        $this->assertSame(39, $result['read_chapters'], 'and only the chapters that exist here');

        $marked = DB::table('chapter_states')
            ->where('user_id', $this->user->id)
            ->where('is_read', true)
            ->count();

        $this->assertSame(39, $marked);
    }

    public function test_an_unresolved_series_is_not_written(): void
    {
        $this->suwayomiChapters = $this->theirs(92);

        $result = $this->writer()->write(
            $this->user,
            $this->backupSeries($this->backupChapters(92, range(1, 40))),
            Resolution::unresolved('the source has no series at that url'),
        );

        $this->assertFalse($result['written']);
        $this->assertSame(0, DB::table('series_states')->count());
        $this->assertSame(0, DB::table('chapter_states')->count());
    }

    /**
     * Forward only. A reader who read further on this device must not be undone.
     */
    public function test_an_earlier_backup_never_clears_a_read(): void
    {
        $this->suwayomiChapters = $this->theirs(92, 5000);
        $writer = $this->writer();

        // First the reader is ahead: 60 chapters read here.
        $this->app->make(ReadingState::class)
            ->markManyRead($this->user, 900, range(5001, 5060), true);

        // Then an older backup arrives claiming only 10 are read.
        $writer->write($this->user, $this->backupSeries($this->backupChapters(92, range(1, 10))), $this->resolved());

        $marked = DB::table('chapter_states')
            ->where('user_id', $this->user->id)
            ->where('is_read', true)
            ->count();

        /*
         * 61, and one chapter being *added* is the behaviour under test rather than a fault.
         * The reader had read sourceOrder 2..61 here, which are Suwayomi ids 5001..5060. The
         * older backup says sourceOrder 1 is read, and sourceOrder 1 is id 5000, which nobody
         * here had read. So it is added, and none of the sixty is cleared.
         */
        $this->assertSame(61, $marked);

        $this->assertSame(
            0,
            DB::table('chapter_states')->where('user_id', $this->user->id)->where('is_read', false)->count(),
            'and nothing was marked unread, which is the only irreversible move available'
        );
    }

    public function test_the_resume_pointer_moves_forward(): void
    {
        $this->suwayomiChapters = $this->theirs(92, 5000);

        $this->writer()->write(
            $this->user,
            $this->backupSeries($this->backupChapters(92, range(1, 40)), resumeBackupId: 340),
            $this->resolved(),
        );

        $state = $this->app->make(ReadingState::class)->findSeries($this->user, 900);

        // backupId 340 is sourceOrder 40, which is Suwayomi's 5039.
        $this->assertSame(5039, $state->last_read_chapter_id);
    }

    /**
     * And only forward. A backup that thinks the reader was further along than they are must
     * not send them back.
     */
    public function test_an_older_pointer_does_not_move_the_resume_point_backwards(): void
    {
        $this->suwayomiChapters = $this->theirs(92, 5000);
        $writer = $this->writer();

        // The reader is at chapter 80 here.
        $writer->write($this->user, $this->backupSeries($this->backupChapters(92), resumeBackupId: 380), $this->resolved());

        $state = $this->app->make(ReadingState::class)->findSeries($this->user, 900);
        $this->assertSame(5079, $state->last_read_chapter_id);

        // An older backup says chapter 10.
        $writer->write($this->user, $this->backupSeries($this->backupChapters(92), resumeBackupId: 310), $this->resolved());

        $after = $this->app->make(ReadingState::class)->findSeries($this->user, 900);
        $this->assertSame(5079, $after->last_read_chapter_id, 'the reader was not sent back');
    }

    public function test_a_resume_point_that_is_missing_is_reported_not_guessed(): void
    {
        $this->suwayomiChapters = $this->theirs(92, 5000);

        // A backup id that names no chapter, which is what an upstream deletion looks like.
        $result = $this->writer()->write(
            $this->user,
            $this->backupSeries($this->backupChapters(92), resumeBackupId: 99_999),
            $this->resolved(),
        );

        $this->assertTrue($result['written']);
        $this->assertStringContainsString('gone', $result['pointer']);

        $state = $this->app->make(ReadingState::class)->findSeries($this->user, 900);
        $this->assertNull($state->last_read_chapter_id, 'nothing was invented');
    }

    /*
     * ---------------------------------------------------------------------------
     * The ledger, which is what makes a re-import a delta.
     * ---------------------------------------------------------------------------
     */

    public function test_an_identical_backup_asks_for_nothing_the_second_time(): void
    {
        $series = $this->backupSeries($this->backupChapters(92, range(1, 40)));
        $fingerprint = LedgerRow::fingerprintFor($series);

        $row = LedgerRow::createFor($this->user, [
            'import_run_id' => $this->aRun()->id,
            'source_id' => $series->sourceId,
            'source_url' => $series->url,
            'fingerprint' => $fingerprint,
            'suwayomi_manga_id' => 900,
            'backup_manga_id' => $series->backupId,
            'title' => $series->title,
            'state' => LedgerRow::RESOLVED,
        ]);

        $this->assertFalse($row->wantsApplyingFor($fingerprint), 'an unchanged series is left alone');

        // One chapter read more, which is the usual reason a reader imports again.
        $moved = $this->backupSeries($this->backupChapters(92, range(1, 41)));
        $movedFingerprint = LedgerRow::fingerprintFor($moved);

        $this->assertNotSame($fingerprint, $movedFingerprint, 'reading one more chapter changes it');
        $this->assertTrue($row->wantsApplyingFor($movedFingerprint));
    }

    public function test_a_series_that_never_resolved_is_retried_rather_than_skipped(): void
    {
        $row = LedgerRow::createFor($this->user, [
            'import_run_id' => $this->aRun()->id,
            'source_id' => 365_063_160_735_482_9018,
            'source_url' => '/manga/gone',
            'fingerprint' => null,
            'state' => LedgerRow::MISSING_SOURCE,
        ]);

        // A dead source last month, alive this month. Its fingerprint is null because it was
        // never resolved, so the new one must be applied rather than treated as unchanged.
        $this->assertTrue($row->wantsApplyingFor('anything at all'));
    }

    public function test_a_second_import_of_the_same_series_does_not_file_it_twice(): void
    {
        $series = $this->backupSeries($this->backupChapters(92, range(1, 40)));
        $fingerprint = LedgerRow::fingerprintFor($series);

        $first = LedgerRow::createFor($this->user, [
            'import_run_id' => $this->aRun()->id,
            'source_id' => $series->sourceId,
            'source_url' => $series->url,
            'fingerprint' => $fingerprint,
            'suwayomi_manga_id' => 900,
            'backup_manga_id' => $series->backupId,
            'state' => LedgerRow::RESOLVED,
        ]);

        $this->assertSame(1, LedgerRow::query()->count());

        /*
         * The unique identity index is what stops a second run filing the same series twice,
         * so the second create has to fail rather than quietly add a row. Asserted on the
         * second attempt rather than a third: an earlier version seeded two rows in a loop
         * and then expected a third to throw, which meant the loop itself hit the constraint
         * and the test passed for the wrong reason.
         */
        $this->expectException(QueryException::class);

        LedgerRow::createFor($this->user, [
            'import_run_id' => $this->aRun()->id,
            'source_id' => $series->sourceId,
            'source_url' => $series->url,
            'fingerprint' => $fingerprint,
            'suwayomi_manga_id' => 900,
            'backup_manga_id' => $series->backupId,
            'state' => LedgerRow::RESOLVED,
        ]);
    }

    public function test_the_run_reports_its_own_size_and_what_did_not_come_across(): void
    {
        $run = ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'schema_version' => 520,
            'state' => ImportRun::COMPLETE,
            'library_series' => 1277,
            'applied' => 1082,
        ]);

        $this->assertSame(84.7, $run->completionPercent());

        $group = 0;

        foreach ([['the source has no series at that url', 190],
            ['this server does not have this series source installed', 5]] as [$reason, $n]) {
            for ($i = 0; $i < $n; $i++) {
                LedgerRow::createFor($this->user, [
                    'import_run_id' => $run->id,
                    'source_id' => 1000 + $i + ($group * 100_000),
                    'source_url' => "/manga/{$i}",
                    'state' => LedgerRow::UNRESOLVED,
                    'reason' => $reason,
                ]);
            }

            $group++;
        }

        $grouped = $run->reasonsGrouped();

        $this->assertCount(2, $grouped);
        $this->assertSame(190, $grouped->first(), 'grouped and ordered, so a report says 190 rather than listing them');
    }

    public function test_a_missing_source_says_what_the_reader_can_do(): void
    {
        $obsolete = ImportedSource::createFor($this->user, [
            'import_run_id' => $this->aRun()->id,
            'source_id' => 365_063_160_735_482_9018,
            'name' => 'Utoon',
            'series_count' => 117,
            'obsolete' => true,
        ]);

        $this->assertStringContainsString('no longer exists', $obsolete->summary());
        $this->assertStringContainsString('117', $obsolete->summary());

        $installable = ImportedSource::createFor($this->user, [
            'import_run_id' => $this->aRun()->id,
            'source_id' => 284_842_067_947_235_0044,
            'name' => 'Thunder Scans',
            'series_count' => 50,
            'obsolete' => false,
        ]);

        $this->assertStringNotContainsString('no longer exists', $installable->summary());
    }

    /**
     * The discriminating case for the pairing key.
     *
     * The fixture used to give every chapter a sourceOrder and a chapterNumber that agreed,
     * which meant the two keys were interchangeable and this test could not tell which one the
     * writer used. So the orders here are deliberately reversed against the numbers: a chapter
     * the writer pairs by order lands on a different Suwayomi id than one it paired by number.
     */
    public function test_chapters_pair_on_source_order_even_when_the_number_says_otherwise(): void
    {
        // sourceOrder runs 1..92, and Suwayomi's ids run the other way, so pairing on either
        // key gives a different answer and only one of them is right.
        $this->suwayomiChapters = $this->theirs(92, 5000);
        foreach ($this->suwayomiChapters as $i => $row) {
            $this->suwayomiChapters[$i]['sourceOrder'] = 92 - $i;
        }

        /*
         * The read chapter's number is pinned to 50 while its order is 1. Reversing the
         * orders and reversing the numbers in the same way leaves the two keys agreeing, so
         * an earlier version of this fixture reversed both and the test still passed with the
         * sourceOrder branch deleted.
         *
         *   sourceOrder 1     -> the last Suwayomi row, id 5091
         *   chapterNumber 50  -> id 5049
         *
         * Different chapters, so only one of the two keys can be right.
         */
        $chapters = new Collection;
        for ($order = 1; $order <= 92; $order++) {
            $chapters->push(new ImportedChapter(
                backupId: 300 + $order,
                number: $order === 1 ? 50.0 : (float) $order,
                name: "Chapter {$order}",
                sourceOrder: $order,
                isRead: $order === 1,
            ));
        }

        $series = new ImportedSeries(
            backupId: 217,
            sourceId: 2_848_420_679_472_350_044,
            url: '/comics/x',
            title: 'A Series',
            chapters: $chapters,
        );

        $result = $this->writer()->write($this->user, $series, $this->resolved());

        $this->assertSame(1, $result['read_chapters']);

        // sourceOrder 1 is the LAST Suwayomi chapter, id 5091, because the orders were
        // reversed. Pairing by the number would have put it on 5049 instead.
        $readIds = DB::table('chapter_states')
            ->where('user_id', $this->user->id)
            ->where('is_read', true)
            ->pluck('suwayomi_chapter_id')
            ->all();

        $this->assertSame([5091], $readIds, 'sourceOrder won, and the chapter number did not');
    }

    /**
     * The other direction: a source that gives no sourceOrder at all, so the chapter number is
     * the only key there is. Removing the fallback would leave those chapters unpaired, which
     * would silently drop a reader's read state rather than fail.
     */
    public function test_chapters_pair_on_the_number_when_the_source_gives_no_order(): void
    {
        $this->suwayomiChapters = $this->theirs(92, 5000);
        foreach ($this->suwayomiChapters as $i => $row) {
            $this->suwayomiChapters[$i]['sourceOrder'] = null;
        }

        $chapters = new Collection;
        for ($n = 1; $n <= 92; $n++) {
            $chapters->push(new ImportedChapter(
                backupId: 300 + $n,
                number: (float) $n,
                name: "Chapter {$n}",
                sourceOrder: null,
                isRead: $n <= 5,
            ));
        }

        $series = new ImportedSeries(
            backupId: 217,
            sourceId: 2_848_420_679_472_350_044,
            url: '/comics/x',
            title: 'A Series',
            chapters: $chapters,
        );

        $result = $this->writer()->write($this->user, $series, $this->resolved());

        $this->assertSame(5, $result['read_chapters'], 'the number was the only key available');
    }

    /**
     * A resume point that leaps most of the way through a series is a reshuffle, not progress,
     * and moving to it would send a reader somewhere they never were.
     */
    public function test_a_resume_point_that_jumps_too_far_is_refused(): void
    {
        /*
         * 400 chapters, because the limit is 200 and a 92 chapter series can never reach it.
         * The first version of this test used the small fixture and passed for the wrong
         * reason: the guard never fired, so nothing was actually being refused. Worth noting
         * that this guard is protection for long series only, and on a short one it never runs.
         */
        $this->suwayomiChapters = $this->theirs(400, 5000);
        $writer = $this->writer();

        // The reader is at chapter 1.
        $writer->write($this->user, $this->backupSeries($this->backupChapters(400), resumeBackupId: 301), $this->resolved());

        $state = $this->app->make(ReadingState::class)->findSeries($this->user, 900);
        $this->assertSame(5000, $state->last_read_chapter_id);

        // An older backup says chapter 390, which is 390 forward, past the limit of 200.
        $result = $writer->write(
            $this->user,
            $this->backupSeries($this->backupChapters(400), resumeBackupId: 690),
            $this->resolved(),
        );

        $this->assertStringContainsString('too far', $result['pointer']);

        $after = $this->app->make(ReadingState::class)->findSeries($this->user, 900);
        $this->assertSame(5000, $after->last_read_chapter_id, 'the reader was not moved to chapter 390');
    }

    /**
     * A series that resolved once and then lost its source must be retried when it comes back.
     *
     * The earlier version of this test used a null fingerprint, which meant the null check
     * masked the state check and removing either guard alone still passed. A real row that has
     * been resolved and later cannot is unresolved AND has a fingerprint, which is the only
     * shape where the state guard is load-bearing.
     */
    public function test_a_series_that_resolved_and_then_lost_its_source_is_retried(): void
    {
        $row = LedgerRow::createFor($this->user, [
            'import_run_id' => $this->aRun()->id,
            'source_id' => 284_842_067_947_235_0044,
            'source_url' => '/comics/x',
            'fingerprint' => str_repeat('a', 64),
            'suwayomi_manga_id' => 900,
            'state' => LedgerRow::MISSING_SOURCE,
        ]);

        $this->assertSame(LedgerRow::MISSING_SOURCE, $row->state);
        $this->assertTrue(
            $row->wantsApplyingFor(str_repeat('a', 64)),
            'the fingerprint is identical, and it must still be applied, because the state changed'
        );
    }

    /**
     * The fingerprint has to cover the resume point, because that is the field a reader most
     * expects a fresh backup to improve.
     */
    public function test_the_fingerprint_covers_the_resume_point(): void
    {
        $chapters = $this->backupChapters(92, range(1, 40));

        $early = new ImportedSeries(
            backupId: 217, sourceId: 2_848_420_679_472_350_044, url: '/comics/x',
            title: 'A Series', backupResumeChapterId: 310, chapters: $chapters,
        );
        $late = new ImportedSeries(
            backupId: 217, sourceId: 2_848_420_679_472_350_044, url: '/comics/x',
            title: 'A Series', backupResumeChapterId: 350, chapters: $chapters,
        );

        $this->assertNotSame(
            LedgerRow::fingerprintFor($early),
            LedgerRow::fingerprintFor($late),
            'reading further along must count as a change'
        );

        // And the same series twice must not, or every re-import rewrites everything.
        $same = new ImportedSeries(
            backupId: 217, sourceId: 2_848_420_679_472_350_044, url: '/comics/x',
            title: 'A Series', backupResumeChapterId: 350, chapters: $chapters,
        );

        $this->assertSame(LedgerRow::fingerprintFor($late), LedgerRow::fingerprintFor($same));
    }

    /**
     * The fingerprint must not depend on the order SQLite happened to return rows in, or every
     * import would rewrite every series.
     */
    public function test_the_fingerprint_does_not_depend_on_chapter_order(): void
    {
        $chapters = $this->backupChapters(92, range(1, 40));

        $forward = new ImportedSeries(
            backupId: 217, sourceId: 2_848_420_679_472_350_044, url: '/comics/x',
            title: 'A Series', chapters: $chapters,
        );

        $reversed = new ImportedSeries(
            backupId: 217, sourceId: 2_848_420_679_472_350_044, url: '/comics/x',
            title: 'A Series', chapters: $chapters->reverse()->values(),
        );

        $this->assertSame(
            LedgerRow::fingerprintFor($forward),
            LedgerRow::fingerprintFor($reversed),
            'the same chapters in another order are the same series'
        );
    }

    private function aRun(): ImportRun
    {
        return ImportRun::createFor($this->user, [
            'format' => 'tachimanga',
            'schema_version' => 520,
            'state' => ImportRun::COMPLETE,
        ]);
    }

    /**
     * Suwayomi's chapter list, shaped like the measured one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function theirs(int $count, int $firstId = 5001): array
    {
        $rows = [];

        for ($order = 1; $order <= $count; $order++) {
            $rows[] = [
                'id' => (string) ($firstId + $order - 1),
                'name' => "Chapter {$order}",
                'chapterNumber' => (float) $order,
                'scanlator' => null,
                'uploadDate' => null,
                'pageCount' => -1,
                'isRead' => false,
                'isDownloaded' => false,
                'lastPageRead' => 0,
                'sourceOrder' => $order,
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // The stub, which is where the read state went.
    // ------------------------------------------------------------------

    public function test_read_state_survives_a_destination_that_has_never_been_fetched(): void
    {
        /*
         * The bug this file could not see.
         *
         * Every other test here had the fake answer `initialized: true` with a chapter list,
         * so the pairing always had something to join on. In production a manga entry the
         * server created from a search answers `initialized: false` with an empty list, the
         * pairing had nothing, every chapter fell out of `pair()`, and every read chapter in
         * the backup was dropped.
         *
         * Measured on the real import: the file carries 40,160 read chapters and the run
         * applied 1,053, because only the series that happened to already be initialised had a
         * list to pair against. 31 of 40 sampled imported series answered this way, and a
         * reader with a finished series was told they had read none of it.
         */
        $this->findIsStub = true;
        $this->suwayomiChapters = $this->theirs(92);

        $result = $this->writer()->write(
            $this->user,
            $this->backupSeries($this->backupChapters(92, [1, 2, 3])),
            $this->resolved(),
        );

        $this->assertTrue($result['written']);
        $this->assertSame(
            3,
            $result['read_chapters'],
            'three chapters were read in the backup, so three must be read here',
        );
    }

    public function test_a_destination_that_already_has_its_chapters_is_not_fetched_again(): void
    {
        $this->suwayomiChapters = $this->theirs(92);

        $this->writer()->write(
            $this->user,
            $this->backupSeries($this->backupChapters(92, [1, 2, 3])),
            $this->resolved(),
        );

        $this->assertSame(
            0,
            $this->refreshCount(),
            'the extra fetch costs 370ms to 1346ms a series, so it is only worth paying when it is needed',
        );
    }

    public function test_a_stub_is_fetched_exactly_once(): void
    {
        $this->findIsStub = true;
        $this->suwayomiChapters = $this->theirs(92);

        $this->writer()->write(
            $this->user,
            $this->backupSeries($this->backupChapters(92, [1])),
            $this->resolved(),
        );

        $this->assertSame(1, $this->refreshCount());
    }

    /** How many `mutation Refresh` documents this test has sent. */
    private function refreshCount(): int
    {
        $count = 0;

        foreach ($this->sent as $body) {
            if (str_contains((string) ($body['query'] ?? ''), 'mutation Refresh')) {
                $count++;
            }
        }

        return $count;
    }
}
