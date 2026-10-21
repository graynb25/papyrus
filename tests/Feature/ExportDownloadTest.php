<?php

namespace Tests\Feature;

use App\Export\ExportLibrary;
use App\Export\TachibkExport;
use App\Jobs\RunLiveExport;
use App\Models\LiveExportRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\BuildsTachibkBackups;
use Tests\Support\MintsTokens;
use Tests\Support\StagesTachibkExports;
use Tests\TestCase;

/**
 * The download seam: what the URL of one finished export run offers, and what it refuses.
 *
 * ## What the tests are about
 *
 * Not the export, which TachibkExportTest owns end to end, nor the row, which
 * RunLiveExportJobTest owns. The two checks between a finished run and its bytes: a run
 * answers with the file its receipt names, and four shapes answer nothing, a run still
 * going, a run nobody ever ran, a name that left the exports folder, and a product whose
 * bytes stopped matching the count the receipt carries. Each refusal is a 404 rather than
 * an error page, because a missing thing is an answer the browser should show plainly and
 * the export run rows say why the thing is missing.
 *
 * The arrangement is {@see StagesTachibkExports::arrangeTheRoundTrip}'s, so
 * the served product is one a receipt proven at its source names, and the scratch files
 * this suite leaves behind are cleaned up in tearDown.
 */
class ExportDownloadTest extends TestCase
{
    use BuildsTachibkBackups;
    use MintsTokens;
    use StagesTachibkExports;

    private string $scratch;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->answers = [];

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-export-download-'.getmypid().'-'.uniqid();

        File::ensureDirectoryExists($this->scratch);

        /*
         * A real file-backed database, so the exports folder the download resolves beside it
         * is this scratch directory and not the checkout's own tree, the same shape
         * RunLiveExportJobTest runs on.
         */
        $database = $this->scratch.DIRECTORY_SEPARATOR.'app.sqlite';

        File::put($database, '');

        config(['database.connections.sqlite.database' => $database]);

        DB::purge('sqlite');

        $this->artisan('migrate', ['--force' => true])->run();

        $this->fakeTheExportLedgerQueries();

        $this->reader = User::factory()->create();
        $this->actingAs($this->reader);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /**
     * The completed run a pressed export leaves behind, so every download below has a real
     * receipt to serve, not one a test invented.
     */
    private function exportRun(): LiveExportRun
    {
        $this->arrangeTheRoundTrip($this->reader);

        (new RunLiveExport($this->reader->getKey()))->handle(app(TachibkExport::class));

        return LiveExportRun::query()->sole();
    }

    private function productFor(LiveExportRun $run): string
    {
        return ExportLibrary::forThisInstall()->directory().DIRECTORY_SEPARATOR.$run->file_name;
    }

    /**
     * The happy shape: the row's receipt names a file that is on disk, at the byte count
     * the row claims, and the browser takes it away under that very name.
     */
    public function test_a_finished_run_serves_its_product_under_its_own_name(): void
    {
        $run = $this->exportRun();
        $product = $this->productFor($run);

        $this->assertFileExists($product, 'the receipt names a file that is there, or nothing below can be served');

        $response = $this->get(route('backups.export', ['run' => $run]));

        $response->assertOk();
        $this->assertSame('application/x-gzip', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString(
            (string) $run->file_name,
            (string) $response->headers->get('Content-Disposition'),
            'the browser saves under the row name, because that is the whole honest naming this seam carries',
        );
        $this->assertSame($run->file_bytes, (int) $response->headers->get('Content-Length'));

        /*
         * The bytes served are the product behind the row, not merely a file that exists.
         * A BinaryFileResponse answers a plain getContent with false, so the read goes
         * through the File object the response itself carries.
         */
        $this->assertSame(
            (string) file_get_contents($product),
            (string) $response->baseResponse->getFile()->getContent(),
        );
    }

    /**
     * A run that is still going has no product to answer for yet. The fixture names a real
     * file at the size a receipt would carry, deliberately: a still-going run that named
     * nothing would be refused by any of the four checks past this one, and the one
     * honest thing this answer is about is that the URL asks whether the run finished
     * before it asks anything else.
     */
    public function test_a_run_that_is_still_going_answers_nothing(): void
    {
        $run = $this->exportRun();

        $row = LiveExportRun::create([
            'started_by_user_id' => $this->reader->getKey(),
            'state' => LiveExportRun::RUNNING,
            'file_name' => $run->file_name,
            'file_bytes' => $run->file_bytes,
            'started_at' => now(),
        ]);

        $this->get(route('backups.export', ['run' => $row]))->assertNotFound();
    }

    public function test_a_run_nobody_ever_ran_answers_nothing(): void
    {
        $this->get(route('backups.export', ['run' => 99]))->assertNotFound();
    }

    /**
     * The name check is the thing being proved, so a file sits exactly where a name that
     * left the folder would resolve to. The receipt matches it byte for byte, meaning the
     * one honest reason this answers nothing is the name, never the size.
     */
    public function test_a_name_that_left_the_folder_is_refused_even_though_a_file_answers_it(): void
    {
        $outside = dirname(ExportLibrary::forThisInstall()->directory()).DIRECTORY_SEPARATOR.'stranger.tachibk';

        File::put($outside, 'not an export this install produced');

        $row = LiveExportRun::create([
            'started_by_user_id' => $this->reader->getKey(),
            'state' => LiveExportRun::COMPLETE,
            'file_name' => '..'.DIRECTORY_SEPARATOR.basename($outside),
            'file_bytes' => filesize($outside),
            'started_at' => now(),
        ]);

        $this->get(route('backups.export', ['run' => $row]))->assertNotFound();
    }

    public function test_a_product_deleted_from_its_folder_is_not_served(): void
    {
        $run = $this->exportRun();

        File::delete($this->productFor($run));

        $this->get(route('backups.export', ['run' => $run]))->assertNotFound();
    }

    /**
     * The byte count is checked against the receipt, so a file that exists and names
     * itself the same still answers nothing: it is a file served under a claim that has
     * stopped being true.
     */
    public function test_a_product_that_stopped_matching_its_receipt_is_not_served(): void
    {
        $run = $this->exportRun();

        File::append($this->productFor($run), 'bytes that arrived after the receipt was written');

        $this->get(route('backups.export', ['run' => $run]))->assertNotFound();
    }

    public function test_a_reader_who_is_not_signed_in_is_sent_to_sign_in(): void
    {
        auth()->logout();

        $this->get(route('backups.export', ['run' => 99]))->assertRedirect();
    }
}
