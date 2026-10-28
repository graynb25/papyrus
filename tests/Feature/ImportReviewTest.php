<?php

namespace Tests\Feature;

use App\Import\BackupImporter;
use App\Import\ImportReviewExpiry;
use App\Jobs\RunBackupImport;
use App\Models\ExtensionStore;
use App\Models\ImportedSeries;
use App\Models\ImportRun;
use App\Models\User;
use App\Models\UserExtensionStore;
use App\Suwayomi\ExtensionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsTachimangaBackups;
use Tests\TestCase;

/**
 * The review an import writes before it installs anything, and the answer that finishes it.
 *
 * ## Why this is tested at the job, not only at the page
 *
 * {@see ImportPageTest} proves the page renders the stores and dispatches the right job. What
 * it cannot prove is that the run the review lives in is durable: that the survey writes the
 * path it read, that a confirm continues that same row rather than starting a second, that a
 * refusal takes the upload with it, and that an abandoned review eventually does. Those are
 * properties of the worker, so they are asserted where the worker runs.
 *
 * ## The trust question is asked twice on purpose
 *
 * The review shows what a store offers. The install asks the database which stores the reader
 * trusts *now*. A test that only trusts before the review would pass whether or not the
 * second read happens, so the revocation test ticks a store, revokes it, and then confirms.
 */
class ImportReviewTest extends TestCase
{
    use BuildsTachimangaBackups;
    use RefreshDatabase;

    private User $owner;

    private ExtensionStore $store;

    /** A real backup on disk, registered for cleanup by the fixture trait. */
    private string $backup = '';

    /** Packages the fake server currently has installed. */
    private array $installed = [];

    /** Every install mutation the fake server received. */
    private array $installs = [];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The local disk is faked for every test so the expiry sweep can never walk the
         * developer's real `storage/app/imports` directory and remove something that is not
         * this test's.
         */
        Storage::fake('local');

        $this->owner = User::factory()->create(['role' => User::ROLE_INSTALL_OWNER]);
        $this->actingAs($this->owner);

        $this->store = ExtensionStore::create([
            'name' => 'Example store',
            'index_url' => 'https://example.test/index.pb',
            'signing_key' => 'abc123',
        ]);

        $this->fakeSuwayomi();
    }

    /*
     * ---------------------------------------------------------------------------
     * The survey.
     * ---------------------------------------------------------------------------
     */

    public function test_a_survey_writes_the_review_and_keeps_the_file_it_read(): void
    {
        $this->backup = $this->buildTachimangaBackup();

        $this->job(surveyForConfirmation: true, limit: 7, force: true, originalName: 'Library backup.tmb')
            ->handle($this->app->make(BackupImporter::class));

        $run = ImportRun::query()->latest('id')->firstOrFail();
        $review = $run->review;

        $this->assertSame(ImportRun::AWAITING_CONFIRMATION, $run->state);
        $this->assertSame($this->backup, $run->file_path, 'a review opened later must still know which file it read');
        $this->assertSame('Library backup.tmb', $run->file_name);
        $this->assertSame('tachimanga', $run->format);
        $this->assertSame(2, $run->library_series);
        $this->assertSame(4, $run->chapters);
        $this->assertSame(0, $run->series_processed, 'a survey reads, it does not import');
        $this->assertSame(0, $run->applied);
        $this->assertFileExists($this->backup);

        $this->assertIsArray($review);
        $this->assertSame(7, $review['limit'], 'the limit the reader chose travels with the review');
        $this->assertTrue($review['force'], 'and so does the force choice');
        $this->assertSame(1, $review['wanted']);
        $this->assertSame(1, $review['missing_sources']);
        $this->assertSame(0, $review['already']);
        $this->assertNull($review['catalogue_error']);

        $this->assertCount(1, $review['stores']);
        $this->assertSame($this->store->id, $review['stores'][0]['id']);
        $this->assertSame('abc123', $review['stores'][0]['signing_key'], 'the key is shown before anything installs');
        $this->assertSame(
            'eu.kanade.tachiyomi.extension.en.thunderscans',
            $review['stores'][0]['packages'][0]['pkg_name'],
        );
        $this->assertSame(2, $review['stores'][0]['packages'][0]['series']);

        $this->assertSame([], $this->installs, 'a survey changes nothing on the server');
        $this->assertSame(0, UserExtensionStore::query()->count(), 'and records no trust');
    }

    public function test_a_survey_that_cannot_read_the_upload_refuses_and_deletes_it(): void
    {
        $reader = User::factory()->create();

        Storage::disk('local')->makeDirectory('imports');
        $path = Storage::disk('local')->path('imports/broken.tmb');
        file_put_contents($path, 'this is not a backup archive');

        $this->job($reader, $path, surveyForConfirmation: true)
            ->handle($this->app->make(BackupImporter::class));

        $run = ImportRun::query()->forUser($reader->id)->latest('id')->firstOrFail();

        $this->assertSame(ImportRun::REFUSED, $run->state);
        $this->assertNull($run->file_path, 'the review holds no path once the file is gone');
        $this->assertNull($run->review);
        $this->assertFileDoesNotExist($path, 'an unreadable upload will not become readable by being kept');
        $this->assertStringNotContainsString(dirname($path), (string) $run->refusal_reason, 'the page and history must not name server directories');
    }

    public function test_a_reader_cannot_survey_a_path_outside_the_imports_directory(): void
    {
        $this->backup = $this->buildTachimangaBackup();
        $reader = User::factory()->create();

        $this->job($reader, $this->backup, surveyForConfirmation: true)
            ->handle($this->app->make(BackupImporter::class));

        $run = ImportRun::query()->forUser($reader->id)->latest('id')->firstOrFail();

        $this->assertSame(ImportRun::REFUSED, $run->state);
        $this->assertNull($run->review);
        $this->assertStringNotContainsString($this->backup, (string) $run->refusal_reason);
        $this->assertFileExists($this->backup, 'a path this app does not own is not its file to remove');
    }

    /*
     * ---------------------------------------------------------------------------
     * The confirm.
     * ---------------------------------------------------------------------------
     */

    public function test_a_confirm_continues_the_review_run_with_its_own_path_and_limit(): void
    {
        $this->backup = $this->buildTachimangaBackup();

        $run = $this->awaitingRun($this->owner, $this->backup, ['limit' => 1, 'force' => true]);

        /*
         * Every value a crafted payload could put here is wrong on purpose. None of it may
         * decide what the confirm reads: the run row carries the path and the choices.
         */
        $job = new RunBackupImport(
            userId: $this->owner->id,
            path: 'C:/no/such/backup.tmb',
            limit: 99,
            force: false,
            reuseRunId: $run->id,
        );

        $job->handle($this->app->make(BackupImporter::class));

        $run->refresh();

        $this->assertSame(ImportRun::COMPLETE, $run->state);
        $this->assertSame(1, ImportRun::query()->count(), 'the review row is continued, not duplicated');
        $this->assertSame(1, ImportedSeries::query()->count(), 'the review stored limit is what the run uses');
    }

    public function test_a_confirm_of_an_answered_review_does_nothing(): void
    {
        $this->backup = $this->buildTachimangaBackup();

        $run = $this->awaitingRun($this->owner, $this->backup);
        $run->update(['state' => ImportRun::DECLINED]);

        $this->job($this->owner, 'C:/no/such/backup.tmb', reuseRunId: $run->id)
            ->handle($this->app->make(BackupImporter::class));

        $run->refresh();

        $this->assertSame(ImportRun::DECLINED, $run->state);
        $this->assertSame(0, $run->series_processed);
        $this->assertSame([], $this->installs);
    }

    public function test_another_readers_review_cannot_be_confirmed_by_id(): void
    {
        $this->backup = $this->buildTachimangaBackup();

        $reader = User::factory()->create();
        $run = $this->awaitingRun($reader, $this->backup);

        $this->job($this->owner, 'C:/no/such/backup.tmb', reuseRunId: $run->id)
            ->handle($this->app->make(BackupImporter::class));

        $run->refresh();

        $this->assertSame(ImportRun::AWAITING_CONFIRMATION, $run->state);
        $this->assertSame(0, $run->series_processed);
        $this->assertSame([], $this->installs);
    }

    public function test_a_store_trusted_before_the_confirm_installs_its_packages(): void
    {
        $this->backup = $this->buildTachimangaBackup();

        app(ExtensionManager::class)->trust($this->owner, $this->store);

        $run = $this->awaitingRun($this->owner, $this->backup);

        $this->job($this->owner, 'C:/no/such/backup.tmb', reuseRunId: $run->id)
            ->handle($this->app->make(BackupImporter::class));

        $this->assertSame(
            ['eu.kanade.tachiyomi.extension.en.thunderscans'],
            $this->installs,
            'the confirm asks the database which stores are trusted now',
        );
        $this->assertSame(ImportRun::COMPLETE, $run->fresh()->state);
    }

    public function test_a_store_revoked_after_the_review_holds_its_packages(): void
    {
        $this->backup = $this->buildTachimangaBackup();

        $extensions = app(ExtensionManager::class);
        $extensions->trust($this->owner, $this->store);
        $extensions->revokeTrust($this->owner, $this->store);

        $run = $this->awaitingRun($this->owner, $this->backup);

        $this->job($this->owner, 'C:/no/such/backup.tmb', reuseRunId: $run->id)
            ->handle($this->app->make(BackupImporter::class));

        $this->assertSame([], $this->installs, 'a tick in the review is not permission to install later');
        $this->assertSame(ImportRun::COMPLETE, $run->fresh()->state, 'the library still imports without the extension');
    }

    public function test_a_successful_confirm_deletes_the_stored_upload(): void
    {
        Storage::disk('local')->makeDirectory('imports');
        $path = Storage::disk('local')->path('imports/reader-review.tmb');
        $this->backup = $this->buildTachimangaBackup($path);

        $reader = User::factory()->create();
        $run = $this->awaitingRun($reader, $path);

        $this->job($reader, 'C:/no/such/backup.tmb', reuseRunId: $run->id)
            ->handle($this->app->make(BackupImporter::class));

        $this->assertSame(ImportRun::COMPLETE, $run->fresh()->state);
        $this->assertFileDoesNotExist($path, 'the import has read the file for the last time');
    }

    /*
     * ---------------------------------------------------------------------------
     * Expiry and the orphan sweep.
     * ---------------------------------------------------------------------------
     */

    public function test_an_abandoned_review_expires_and_its_upload_is_removed(): void
    {
        Storage::disk('local')->makeDirectory('imports');
        $path = Storage::disk('local')->path('imports/abandoned.tmb');
        file_put_contents($path, 'backup bytes');

        $reader = User::factory()->create();
        $run = $this->awaitingRun($reader, $path);

        $this->age($run, ImportRun::REVIEW_TTL_HOURS + 1);

        $expired = $this->app->make(ImportReviewExpiry::class)->expire();

        $run->refresh();

        $this->assertSame(1, $expired);
        $this->assertSame(ImportRun::EXPIRED, $run->state);
        $this->assertNull($run->file_path);
        $this->assertFileDoesNotExist($path);
        $this->assertSame(
            'This review expired before it was answered, and any stored upload was removed.',
            $run->progress_note,
        );
    }

    public function test_a_review_inside_the_retention_period_is_left_alone(): void
    {
        Storage::disk('local')->makeDirectory('imports');
        $path = Storage::disk('local')->path('imports/recent.tmb');
        file_put_contents($path, 'backup bytes');

        $run = $this->awaitingRun($this->owner, $path);

        $this->age($run, ImportRun::REVIEW_TTL_HOURS - 1);

        $this->assertSame(0, $this->app->make(ImportReviewExpiry::class)->expire());
        $this->assertSame(ImportRun::AWAITING_CONFIRMATION, $run->fresh()->state);
        $this->assertFileExists($path);
    }

    public function test_an_answered_review_is_not_expired(): void
    {
        $this->backup = $this->buildTachimangaBackup();

        $run = $this->awaitingRun($this->owner, $this->backup, ['limit' => 0]);
        $run->update(['state' => ImportRun::COMPLETE, 'file_path' => null]);

        $this->age($run, ImportRun::REVIEW_TTL_HOURS + 1);

        $this->assertSame(0, $this->app->make(ImportReviewExpiry::class)->expire());
        $this->assertSame(ImportRun::COMPLETE, $run->fresh()->state);
    }

    public function test_the_sweep_removes_only_old_unreferenced_uploads(): void
    {
        Storage::disk('local')->makeDirectory('imports');

        $orphan = Storage::disk('local')->path('imports/orphan.tmb');
        file_put_contents($orphan, 'nobody references this');
        touch($orphan, now()->subHours(ImportRun::REVIEW_TTL_HOURS + 1)->getTimestamp());

        $fresh = Storage::disk('local')->path('imports/fresh.tmb');
        file_put_contents($fresh, 'a request has just stored this');

        $this->backup = $this->buildTachimangaBackup();
        $running = ImportRun::createFor($this->owner, [
            'format' => 'tachimanga',
            'state' => ImportRun::RUNNING,
            'file_path' => $this->backup,
            'started_at' => now(),
        ]);

        $referenced = Storage::disk('local')->path('imports/running.tmb');
        file_put_contents($referenced, 'a worker is reading this');
        touch($referenced, now()->subHours(ImportRun::REVIEW_TTL_HOURS + 1)->getTimestamp());
        $running->update(['file_path' => $referenced]);

        $this->assertSame(0, $this->app->make(ImportReviewExpiry::class)->expire());

        $this->assertFileDoesNotExist($orphan, 'an upload whose job never started still has a life cycle');
        $this->assertFileExists($fresh, 'the age bound protects a file just stored');
        $this->assertFileExists($referenced, 'a running import keeps the file it is reading');
    }

    /*
     * ---------------------------------------------------------------------------
     * Helpers.
     * ---------------------------------------------------------------------------
     */

    private function job(
        ?User $user = null,
        string $path = '',
        bool $surveyForConfirmation = false,
        int $limit = 0,
        bool $force = false,
        ?string $originalName = null,
        ?int $reuseRunId = null,
    ): RunBackupImport {
        return new RunBackupImport(
            userId: ($user ?? $this->owner)->id,
            path: $path !== '' ? $path : $this->backup,
            limit: $limit,
            force: $force,
            originalName: $originalName,
            surveyForConfirmation: $surveyForConfirmation,
            reuseRunId: $reuseRunId,
        );
    }

    /**
     * A review row, built by hand so the confirm path is tested without running a survey first.
     *
     * @param  array<string, mixed>  $review
     */
    private function awaitingRun(User $user, string $path, array $review = []): ImportRun
    {
        return ImportRun::createFor($user, [
            'format' => 'tachimanga',
            'state' => ImportRun::AWAITING_CONFIRMATION,
            'file_name' => basename($path),
            'file_path' => $path,
            'review' => $review + [
                'stores' => [],
                'unresolved' => [],
                'already' => 0,
                'obsolete' => 0,
                'missing_sources' => 1,
                'wanted' => 1,
                'catalogue_error' => null,
                'limit' => 0,
                'force' => false,
            ],
            'started_at' => now(),
            'finished_at' => now(),
            'progress_note' => 'Waiting for you to choose which extension stores to trust.',
        ]);
    }

    /**
     * Move a row's `updated_at` back by whole hours.
     *
     * Through the query builder so the model does not stamp the time it is being aged.
     */
    private function age(ImportRun $run, int $hours): void
    {
        DB::table('import_runs')
            ->where('id', $run->id)
            ->update(['updated_at' => now()->subHours($hours)]);
    }

    /**
     * The smallest server that lets a survey place the fixture's package and a confirm finish.
     *
     * The installed list is mutable because the install changes it: a fake that always answered
     * with the pre-install list would let a broken re-survey pass.
     */
    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true) ?: [];
            $query = (string) ($body['query'] ?? '');

            if (str_contains($query, 'login(')) {
                return Http::response(['data' => ['login' => ['accessToken' => 'a-token']]]);
            }

            if (str_contains($query, 'sources(first:')) {
                $nodes = array_map(
                    fn (string $package): array => [
                        'id' => (string) crc32($package),
                        'name' => $package,
                        'extension' => [
                            'pkgName' => $package,
                            'isInstalled' => true,
                        ],
                    ],
                    array_keys($this->installed),
                );

                return Http::response(['data' => ['sources' => ['nodes' => $nodes]]]);
            }

            if (str_contains($query, 'query Packages')) {
                $names = (array) ($body['variables']['filter']['and'][0]['pkgName']['in'] ?? []);

                $nodes = array_map(
                    fn (mixed $name): array => [
                        'pkgName' => (string) $name,
                        'name' => 'Thunder Scans',
                        'versionName' => '1.0',
                        'storeIndexUrl' => $this->store->index_url,
                    ],
                    $names,
                );

                return Http::response(['data' => ['extensions' => ['nodes' => $nodes]]]);
            }

            if (str_contains($query, 'updateExtension')) {
                $package = (string) ($body['variables']['id'] ?? '');

                $this->installs[] = $package;
                $this->installed[$package] = true;

                return Http::response(['data' => ['updateExtension' => ['clientMutationId' => null]]]);
            }

            $id = (int) (data_get($body, 'variables.id') ?? 0);

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
