<?php

namespace Tests\Feature;

use App\Backup\ReaderHalfCapture;
use App\Backup\ReaderHalfRefused;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * The shared reader half seam, on its own and without `RefreshDatabase`.
 *
 * `ReaderHalfCapture` now owns the one VACUUM INTO statement two callers use: the
 * `papyrus:snapshot` command and the in app backup's queued run. The command's own suite proves
 * the statement carries data; this class proves the seam's own two promises that the command
 * cannot prove on its behalf, because the command checks the guard early and never rides the
 * internal one a queued caller will hit:
 *
 * - refusing, by name, inside a transaction, and writing nothing there;
 * - a target whose name carries an apostrophe, which is legal on every platform this ships to
 *   and would be a syntax error had the path ever been interpolated.
 */
class ReaderHalfCaptureTest extends TestCase
{
    public function test_it_writes_a_file_that_opens_as_a_database(): void
    {
        $capture = new ReaderHalfCapture;
        $target = $this->scratchPath(prefix: 'reader-half-into').DIRECTORY_SEPARATOR.'half.sqlite';

        $capture->capture($target);

        $this->assertFileExists($target);
        $this->assertGreaterThan(0, filesize($target));

        $copy = new PDO('sqlite:'.$target);

        $this->assertSame(
            0,
            (int) $copy->query("select count(*) from sqlite_master where type='table' and name='definitely_not_mine'")
                ->fetchColumn(),
            'the copy must open as a real database, not merely be a file with the right name',
        );
    }

    public function test_it_takes_a_target_whose_name_carries_an_apostrophe(): void
    {
        $capture = new ReaderHalfCapture;
        $target = $this->scratchPath(prefix: 'reader-half-apos').DIRECTORY_SEPARATOR."reader-half-'copy.sqlite";

        $capture->capture($target);

        $this->assertFileExists($target);
    }

    /**
     * The refusal a queued job sits behind. The command guards early and would pass here even
     * with the middle of the seam broken, so the seam is asserted directly.
     */
    public function test_it_refuses_inside_a_transaction_and_writes_nothing(): void
    {
        $capture = new ReaderHalfCapture;
        $target = $this->scratchPath(prefix: 'reader-half-txn').DIRECTORY_SEPARATOR.'half.sqlite';

        $caught = null;

        DB::transaction(function () use (&$caught, $capture, $target): void {
            try {
                $capture->capture($target);
            } catch (ReaderHalfRefused $e) {
                $caught = $e;
            }
        });

        $this->assertInstanceOf(ReaderHalfRefused::class, $caught, 'the seam itself must refuse, not only a caller that guards early');
        $this->assertStringContainsString('inside a transaction', $caught->getMessage());
        $this->assertFileDoesNotExist($target, 'a refusal writes nothing');
    }
}
