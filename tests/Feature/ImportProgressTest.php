<?php

namespace Tests\Feature;

use App\Import\BackupImporter;
use App\Import\BackupReader;
use App\Import\BackupReaders;
use App\Import\ExtensionInstaller;
use App\Import\ImportedBackup;
use App\Import\SeriesResolver;
use App\Import\SeriesWriter;
use App\Import\Tachimanga\TachimangaReader;
use App\Jobs\RunBackupImport;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\ReadingState;
use App\Suwayomi\ExtensionManager;
use App\Suwayomi\Gateway;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SeriesLocator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsTachimangaBackups;
use Tests\TestCase;

/**
 * That an import writes progress as it goes, and that the job running it fails honestly.
 *
 * ## Why the progress columns need their own suite
 *
 * {@see ImportPageTest} asserts the page renders a bar and calls a run stuck. Neither proves
 * anything is ever written into the columns those two read. A page can render a correct
 * progress bar from a run that never moves, which is exactly what a queued import does when
 * no worker is serving the queue, so the writing has to be asserted from the other end.
 *
 * ## Why the job's failures are here
 *
 * Three things can go wrong between a reader pressing the button and a library appearing,
 * and all three were silent:
 *
 * 1. **The file was gone.** Dispatched from a path, so the file can be deleted in between. That
 *    has to be a refusal a page can read, not an exception in a table nobody opens.
 * 2. **The importer threw.** The run row is written at the end, so a throw leaves it reading
 *    `running` forever, which a polling page cannot tell from a run in progress.
 * 3. **The account was deleted.** Nothing to write to, and failing loudly would fill
 *    `failed_jobs` with work that can never succeed.
 */
class ImportProgressTest extends TestCase
{
    use BuildsTachimangaBackups;
    use RefreshDatabase;

    private User $user;

    private string $built = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->fakeSuwayomi();
    }

    /*
     * ---------------------------------------------------------------------------
     * The importer writes progress.
     * ---------------------------------------------------------------------------
     */

    public function test_the_run_row_carries_the_final_progress(): void
    {
        $this->importer()->import($this->user, $this->backup());

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(2, $run->library_series);
        $this->assertSame(2, $run->series_processed, 'every series in the file was looked at');
        $this->assertNotNull($run->progress_note);

        /*
         * The shelf receipt is the last word an import says, because it is the pass that runs
         * after the series loop and needs the final ledger row. The series phase's own
         * completion is what already makes that line exist, and it is asserted above from the
         * column that keeps it permanently, where the old `of 2 series` note could only ever
         * be the last thing said rather than a state.
         */
        $this->assertStringContainsString('categories:', $run->progress_note);

        $this->assertStringContainsString('landed', $run->progress_note, 'and the shelf receipt, like the series note before it, says what was done and not that the run had begun');
    }

    public function test_progress_never_exceeds_the_library(): void
    {
        $this->importer()->import($this->user, $this->backup());

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->assertLessThanOrEqual($run->library_series, $run->series_processed);
    }

    public function test_a_refused_run_records_no_progress(): void
    {
        /*
         * A refusal happens before the loop, so there is nothing to report progress about.
         * The run still has to exist: "it did nothing" and "it worked" are different outcomes.
         */
        $this->version = 900;
        $this->built = $this->buildTachimangaBackup();

        try {
            $this->importer()->import($this->user, $this->built);
            $this->fail('expected the reader to refuse the file');
        } catch (\Throwable) {
            // The refusal is the assertion.
        }

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(ImportRun::REFUSED, $run->state);
        $this->assertSame(0, $run->series_processed);
        $this->assertNull($run->library_series, 'the file was never read, so there is no total');
    }

    public function test_an_inspect_writes_a_sentence_rather_than_a_terminals_output(): void
    {
        /*
         * Measured: the page showed the raw output of `import:inspect`, which is a wall of
         * `+---+` box drawing, `INFO` prefixes and a paragraph explaining WARN levels. Those are
         * the terminal's layout, not an answer, and stripping the characters afterwards would be
         * guessing which were content.
         */
        $job = new RunBackupImport($this->user->id, $this->backup(), inspectOnly: true);
        $job->handle($this->app->make(BackupImporter::class));

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $note = (string) $run->progress_note;

        $this->assertStringContainsString('series in your library', $note);
        $this->assertStringContainsString('marked read', $note);

        foreach (['+---', '|', 'INFO', 'WARN', 'Count'] as $artefact) {
            $this->assertStringNotContainsString(
                $artefact,
                $note,
                "terminal output leaked into a page: {$artefact}",
            );
        }

        $this->assertSame('tachimanga', $run->format, 'and the run now says what it read');
    }

    public function test_an_inspect_of_something_unreadable_is_a_refusal_not_a_crash(): void
    {
        /*
         * `is_file()` catches a path that has gone, not one that exists and is not a backup.
         * `BackupReaders::for()` raises for that, and the inspect call sat outside the try, so
         * the refusal escaped uncaught and the reader saw nothing at all. Found by a test that
         * was about the queue and happened to dispatch an inspect of a temp file.
         */
        $path = $this->temporaryFile('papyrus-not-a-backup-');
        file_put_contents((string) $path, 'this is not a backup');

        $job = new RunBackupImport($this->user->id, (string) $path, inspectOnly: true);

        try {
            $job->handle($this->app->make(BackupImporter::class));
        } finally {
            @unlink((string) $path);
        }

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(ImportRun::REFUSED, $run->state);
        $this->assertStringContainsString('No reader recognises', (string) $run->refusal_reason);
    }

    public function test_the_importer_still_notifies_the_caller(): void
    {
        $lines = [];

        $importer = $this->importer();
        $importer->onProgress(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $importer->import($this->user, $this->backup());

        $this->assertNotEmpty($lines, 'the command still prints what it always printed');

        /*
         * And the last line made it into the row too, which is the point: a page and a terminal
         * are now reading the same sentence rather than the page inventing its own.
         */
        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(end($lines), $run->progress_note);
    }

    /*
     * ---------------------------------------------------------------------------
     * The job.
     * ---------------------------------------------------------------------------
     */

    public function test_the_job_runs_a_real_import_and_finishes_the_run(): void
    {
        $job = new RunBackupImport($this->user->id, $this->backup());
        $job->handle($this->app->make(BackupImporter::class));

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(ImportRun::COMPLETE, $run->state);
        $this->assertNotNull($run->finished_at);
    }

    public function test_a_file_that_vanished_is_a_refusal_not_an_exception(): void
    {
        $job = new RunBackupImport($this->user->id, 'D:/no/such/backup.tmb');
        $job->handle($this->app->make(BackupImporter::class));

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(ImportRun::REFUSED, $run->state);
        $this->assertStringContainsString('no/such/backup.tmb', (string) $run->refusal_reason);
    }

    public function test_a_deleted_account_does_not_fail_the_job(): void
    {
        Queue::fake();

        $userId = $this->user->id;
        $this->user->delete();

        $job = new RunBackupImport($userId, $this->backup());
        $job->handle($this->app->make(BackupImporter::class));

        $this->assertSame(
            0,
            ImportRun::query()->forUser($userId)->count(),
            'there is nobody to write a run for',
        );
    }

    public function test_a_throw_inside_the_importer_closes_the_run(): void
    {
        /*
         * A reader that throws something the importer does not expect.
         *
         * The importer catches `ImportRefused` and nothing else, so this is the shape that
         * leaves a run row reading `running` forever: a corrupt archive that raises out of the
         * zip layer rather than being recognised as unreadable. That is exactly the state a
         * polling page cannot interpret, so it is what this test is about.
         *
         * `BackupImporter` is final, so the throw is planted in its collaborator rather than
         * in the importer itself.
         */
        $importer = $this->importerWith(new class implements BackupReader
        {
            public function name(): string
            {
                return 'a reader that throws';
            }

            public function recognises(string $path): bool
            {
                return true;
            }

            public function read(string $path): ImportedBackup
            {
                throw new \RuntimeException('the archive ended halfway');
            }
        });

        $job = new RunBackupImport($this->user->id, $this->backup());

        $threw = false;

        try {
            $job->handle($importer);
        } catch (\RuntimeException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'the exception continues, so failed_jobs keeps the trace');

        $run = ImportRun::query()->latest('id')->firstOrFail();

        $this->assertSame(ImportRun::FAILED, $run->state, 'not left reading running forever');
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('ended halfway', (string) $run->refusal_reason);
    }

    public function test_the_job_declares_enough_time_for_a_large_library(): void
    {
        $job = new RunBackupImport($this->user->id, 'D:/x.tmb');

        /*
         * 1,277 series is about eleven minutes at the measured 520ms a series. A worker timeout
         * below that kills the job with the run row still reading `running`, which is the state
         * the page cannot interpret.
         */
        $this->assertGreaterThanOrEqual(1800, $job->timeout);
    }

    public function test_the_job_is_queueable(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(RunBackupImport::class));
    }

    /*
     * ---------------------------------------------------------------------------
     * Helpers.
     * ---------------------------------------------------------------------------
     */

    private function backup(): string
    {
        return $this->built = $this->buildTachimangaBackup();
    }

    private function importer(): BackupImporter
    {
        $locator = new SeriesLocator($this->app->make(Gateway::class));

        return new BackupImporter(
            readers: new BackupReaders([$this->app->make(TachimangaReader::class)]),
            installer: new ExtensionInstaller($this->app->make(ExtensionManager::class), $locator),
            resolver: new SeriesResolver($locator),
            writer: new SeriesWriter($this->app->make(ReadingState::class), $this->app->make(SeriesGateway::class)),
        );
    }

    /**
     * The same importer, reading through a reader the test chooses.
     *
     * So a failure can be planted where a real one happens: inside the read, rather than by
     * subclassing an importer that is `final` for good reason.
     */
    private function importerWith(BackupReader $reader): BackupImporter
    {
        $locator = new SeriesLocator($this->app->make(Gateway::class));

        return new BackupImporter(
            readers: new BackupReaders([$reader]),
            installer: new ExtensionInstaller($this->app->make(ExtensionManager::class), $locator),
            resolver: new SeriesResolver($locator),
            writer: new SeriesWriter($this->app->make(ReadingState::class), $this->app->make(SeriesGateway::class)),
        );
    }

    /**
     * The smallest server that lets a run finish: a login and two series that resolve.
     *
     * Deliberately not the full shape from `BackupImporterTest`. This suite is about what gets
     * written down, not about resolution tiers, so a two-series library that both resolve is
     * enough to prove the counters move and land.
     */
    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => 'token',
                    'refreshToken' => 'refresh',
                ]]]);
            }

            $id = (int) (data_get($request->data(), 'variables.id') ?? 0);

            if (str_contains($query, 'fetchMangaAndChapters') || str_contains($query, 'mutation Refresh')) {
                return Http::response(['data' => ['fetchMangaAndChapters' => [
                    'manga' => [
                        'id' => (string) ($id ?: 1),
                        'title' => "Series {$id}",
                        'initialized' => true,
                        'genre' => [],
                        'source' => [
                            'id' => '2848420679472350044',
                            'name' => 'Thunder Scans',
                            'lang' => 'en',
                        ],
                    ],
                    'chapters' => ['nodes' => [[
                        'id' => 1,
                        'name' => 'Chapter 1',
                        'chapterNumber' => 1,
                        'scanlator' => null,
                        'uploadDate' => 0,
                        'pageCount' => 20,
                        'isRead' => false,
                        'isDownloaded' => false,
                        'lastPageRead' => 0,
                        'sourceOrder' => 1,
                    ]]],
                ]]]);
            }

            if (str_contains($query, 'query SourceFetchManga')) {
                return Http::response(['data' => ['source' => ['fetchManga' => [
                    'id' => (string) $id,
                    'name' => 'Series '.$id,
                    'url' => '/series/'.$id,
                ]]]]);
            }

            if (str_contains($query, 'PageFetchManga')) {
                return Http::response(['data' => ['page' => ['fetchManga' => [
                    'id' => (string) $id,
                    'name' => 'Series '.$id,
                    'url' => '/series/'.$id,
                    'chapters' => ['nodes' => []],
                ]]]]);
            }

            return Http::response(['data' => []]);
        });
    }
}
