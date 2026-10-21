<?php

namespace App\Http\Controllers;

use App\Backup\BackupLibrary;
use App\Export\ExportLibrary;
use App\Models\LiveExportRun;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves one finished export as the download its run row offers.
 *
 * ## Why the URL is keyed on the run and never on the file
 *
 * The exports folder is a product folder, and nothing in it answers for itself except the
 * run rows: a row is written only once the merged file is whole, and it carries the name,
 * the byte count and the receipt the page renders. A download keyed by file name would
 * serve anything that turned up in the folder, half staged or a stranger's; keyed by run,
 * the only files reachable are the ones a finished run has already put its receipt on. The
 * name the browser saves under is the row's own name for its product, which is the whole
 * of the honest naming this seam carries.
 *
 * ## The two checks between the row and the bytes
 *
 * The name is checked to be a plain name, because a row's own writer takes it from
 * basename() and a name that left the folder would turn the download into a read of
 * whatever the path resolves to. The size is checked against the receipt, because a file
 * whose bytes no longer match the count the row and the page both claim is a file served
 * under a claim that stopped being true; {@see BackupLibrary} makes the same
 * check on the pair listing with digests, and this is that check sized to what a run row
 * carries.
 */
class ExportDownloadController extends Controller
{
    public function __invoke(int $run): Response
    {
        $row = LiveExportRun::query()->find($run);

        if ($row === null || $row->state !== LiveExportRun::COMPLETE || $row->file_name === null) {
            abort(404, 'No finished export answers for that run.');
        }

        if (basename($row->file_name) !== $row->file_name) {
            abort(404, 'A file name that has left the exports folder is refused, so nothing is served for it.');
        }

        $file = ExportLibrary::forThisInstall()->directory().DIRECTORY_SEPARATOR.$row->file_name;

        if (! is_file($file)) {
            abort(404, 'The export this run names is not in the exports folder anymore.');
        }

        if ((int) filesize($file) !== (int) $row->file_bytes) {
            abort(404, 'The export this run names no longer matches the byte count its receipt carries, so it is not served.');
        }

        return response()->download($file, $row->file_name, [
            'Content-Type' => 'application/x-gzip',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
