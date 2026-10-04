<?php

namespace App\Http\Controllers;

use App\Downloads\CbzExporter;
use App\Downloads\CbzExportRefused;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a chapter as a CBZ.
 *
 * ## Why the refusal is a page and not a 413
 *
 * A refusal is raised before the response body starts, so there is nothing to attach a status
 * code to at the moment it is decided. The alternatives were both worse: refusing with a 413
 * and a JSON body hands a browser that asked for a file an error document it cannot save, and
 * letting the download start and stopping at the cap hands over a truncated archive that the
 * reader's unzip tool calls corrupt. A redirect back to the download centre with the reason in
 * the session is the one outcome where the reader lands somewhere they can read what happened
 * and do something about it.
 *
 * The reader is redirected rather than shown a bare error because they arrived from a page:
 * a control that fails should return them to the page the control was on.
 */
class CbzExportController extends Controller
{
    public function __invoke(Request $request, int $chapter, CbzExporter $exporter): Response
    {
        try {
            return $exporter->stream($chapter, $this->filename($request, $chapter));
        } catch (CbzExportRefused $refused) {
            return back()
                ->with('cbzRefusal', $refused->getMessage())
                ->with('cbzRefusalReason', $refused->reason);
        }
    }

    /**
     * The name the browser saves it under.
     *
     * Taken from the query string when the caller supplies one, so a series and chapter title
     * arrive as something a reader recognises in their downloads folder. Sanitised twice: once
     * here, by the exporter, which strips what a header cannot carry, and once by the filename
     * the route builds, because a name arriving from a query is a reader's text and not ours.
     */
    private function filename(Request $request, int $chapter): string
    {
        $supplied = $request->query('name');

        if (is_string($supplied) && trim($supplied) !== '') {
            $clean = trim((string) preg_replace('/[^\w\-\'. ]+/u', ' ', $supplied));

            if ($clean !== '') {
                return $clean.'.cbz';
            }
        }

        return 'chapter-'.$chapter.'.cbz';
    }
}
