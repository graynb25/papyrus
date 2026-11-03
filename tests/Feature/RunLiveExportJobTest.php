<?php

namespace Tests\Feature;

use App\Export\ExportLibrary;
use App\Export\TachibkExport;
use App\Jobs\RunLiveExport;
use App\Models\LiveExportRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\BuildsTachibkBackups;
use Tests\Support\MintsTokens;
use Tests\Support\StagesTachibkExports;
use Tests\TestCase;

/**
 * The job around the outward export: the row it writes, and how a press ends on it.
 *
 * ## What the tests are about
 *
 * Not the merge, which TachibkExportTest owns end to end. The row: that a completed press
 * names the product that landed and the receipt it carries, that a refusal records the
 * sentence with nothing delivered, that an unexpected error fails the row honestly and
 * rethrows, and that a run whose reader vanished between press and execution leaves no row
 * at all, because an export without its reader's arrangement is nothing to run.
 *
 * The arrangement under the first test is the exporter main test's own, so every number the
 * receipt asserts here was proven at its source there first; the trait keeps the two rigs
 * from drifting apart.
 */
class RunLiveExportJobTest extends TestCase
{
    use BuildsTachibkBackups;
    use MintsTokens;
    use StagesTachibkExports;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->answers = [];

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-export-job-'.getmypid().'-'.uniqid();

        File::ensureDirectoryExists($this->scratch);

        /*
         * A real file-backed database, so the exports folder the job resolves beside it is
         * this scratch directory and not the checkout's own tree: products that a test
         * leaves in the checkout would be the 1,217 untracked entries story again.
         */
        $database = $this->scratch.DIRECTORY_SEPARATOR.'app.sqlite';

        File::put($database, '');

        config(['database.connections.sqlite.database' => $database]);

        DB::purge('sqlite');

        $this->artisan('migrate', ['--force' => true])->run();

        $this->fakeTheExportLedgerQueries();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /** Every exported product the job left in this install's exports folder.
     *
     * @return list<string> */
    private function products(): array
    {
        $found = glob(ExportLibrary::forThisInstall()->directory().DIRECTORY_SEPARATOR.'tachibk-*.tachibk');

        return is_array($found) ? $found : [];
    }

    /**
     * A completed press: the row carries the name of the product that landed, the byte
     * size of what landed, the three series counts and the merge totals the run's own
     * comparison produced, and nothing left in staging.
     */
    public function test_a_completed_press_records_its_receipt_and_delivers_the_file(): void
    {
        $user = User::factory()->create();
        $this->arrangeTheRoundTrip($user);

        (new RunLiveExport($user->id))->handle(app(TachibkExport::class));

        $run = LiveExportRun::query()->sole();

        $this->assertSame(LiveExportRun::COMPLETE, $run->state);
        $this->assertFalse($run->isRunning(), 'a finished run is not running, so the page does not poll it');
        $this->assertSame(1, $run->started_by_user_id);
        $this->assertSame(2, $run->series_in_file, 'the fresh export carried two series');
        $this->assertSame(1, $run->series_planned, 'one stateful series the run tried to carry');
        $this->assertSame(1, $run->series_matched, 'that series matched, so its bytes changed');
        $this->assertSame(1, $run->shelves_appended);
        $this->assertSame(2, $run->read_marks);
        $this->assertSame([], $run->skipped, 'nothing planned was skipped');
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('1 series carry reader state to merge.', $run->progress_note,
            "the last note is the exporter's own last line, not the row's opening one");

        $products = $this->products();

        $this->assertCount(1, $products, 'one delivered product, and neither staging nor nothing');
        $this->assertSame(basename((string) $products[0]), $run->file_name, 'the row names the file that landed');
        $this->assertSame((int) filesize((string) $products[0]), $run->file_bytes, 'the byte count is the file on disk');
        $this->assertFileDoesNotExist(ExportLibrary::forThisInstall()->staging(),
            'staging emptied once the rename delivered the file');
    }

    /**
     * A transfer that refuses is a refusal row, with the sentence kept and no product
     * anywhere, not even a half finished one in staging.
     */
    public function test_a_refused_transfer_refuses_with_its_reason_and_nothing_delivered(): void
    {
        $user = User::factory()->create();
        $this->stageFreshExportFromRefusing('the fresh export could not be fetched: the stream gave 503');

        (new RunLiveExport($user->id))->handle(app(TachibkExport::class));

        $run = LiveExportRun::query()->sole();

        $this->assertSame(LiveExportRun::REFUSED, $run->state);
        $this->assertStringContainsString('the stream gave 503', (string) $run->refusal_reason);
        $this->assertNull($run->file_name, 'a refused run delivered no file, so it has no name to carry');
        $this->assertNull($run->progress_note, 'a refusal clears the note, which described work that no longer exists');
        $this->assertNotNull($run->finished_at);
        $this->assertCount(0, $this->products(), 'a refused run records no product');
        $this->assertFileDoesNotExist(ExportLibrary::forThisInstall()->staging());
    }

    /**
     * Anything that is not one of the run's own refusals fails the row and then rethrows,
     * so `failed_jobs` keeps the trace.
     *
     * The throw is planted in the download, a seam no code translates: the difference
     * between the two failure families is exactly the exception's kind and the row's
     * state, whatever raised it underneath.
     */
    public function test_an_unexpected_error_fails_the_row_and_rethrows(): void
    {
        $user = User::factory()->create();
        $this->stageFreshExportThatBreaksPlainly();

        $threw = false;

        try {
            (new RunLiveExport($user->id))->handle(app(TachibkExport::class));
        } catch (RuntimeException $caught) {
            $threw = true;

            $this->assertSame('the transfer gave up on its own terms', $caught->getMessage(), "the stack that reaches failed_jobs is the run's own");
        }

        $this->assertTrue($threw, 'the job rethrows, so failed_jobs keeps the trace');

        $run = LiveExportRun::query()->sole();

        $this->assertSame(LiveExportRun::FAILED, $run->state);
        $this->assertStringContainsString('the transfer gave up on its own terms', (string) $run->refusal_reason);
        $this->assertSame('This run stopped on an error: the transfer gave up on its own terms', $run->progress_note);
        $this->assertNull($run->file_name);
        $this->assertCount(0, $this->products(), 'a failed run records no product');
    }

    /**
     * The reader who pressed can be gone when the worker picks the job up. The export
     * merges only that reader's arrangement, so unlike the backup there is no run without
     * them: no row, no product, and the log names the orphaned dispatch.
     */
    public function test_a_vanished_reader_leaves_no_run_at_all(): void
    {
        Log::spy();

        // A user that factory never created, by id, so nothing else about the test matters.
        (new RunLiveExport(999999))->handle(app(TachibkExport::class));

        Log::shouldHaveReceived('info')
            ->with('A live run was queued for a reader who no longer exists.', ['user_id' => 999999])
            ->once();

        $this->assertSame(0, LiveExportRun::query()->count(), 'no row, because the run cannot answer for a reader who is not there');
        $this->assertCount(0, $this->products());
    }
}
