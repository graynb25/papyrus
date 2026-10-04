<?php

namespace App\Http\Controllers;

use App\AniList\Tracking;
use App\Support\ReadingState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records how far into a chapter the reader got.
 *
 * Separate from the reader itself because it is the one write that happens on a
 * timer rather than on a navigation. The browser debounces it while scrolling and
 * uses sendBeacon on page close, so this receives two shapes:
 *
 *   - a JSON body from fetch, during reading
 *   - a form post from sendBeacon, on navigation away
 *
 * Both are handled, because a beacon that is silently rejected means the reader
 * loses their place on exactly the pages they cared about.
 *
 * The response is empty and fast. It is fired while someone is reading, and
 * anything slower than the write is a cost paid in their attention.
 */
class ReaderProgressController extends Controller
{
    public function __invoke(
        Request $request,
        int $manga,
        int $chapter,
        ReadingState $reading,
    ): JsonResponse {
        $page = $this->pageFrom($request);
        $total = $this->totalFrom($request);

        if ($page === null) {
            return response()->json(['ok' => false, 'reason' => 'no page'], 422);
        }

        $wasRead = (bool) $reading->findChapter($request->user(), $chapter)?->is_read;

        $chapterState = $reading->recordProgress($request->user(), $manga, $chapter, $page, $total);

        /*
         * A chapter finishing is the one event AniList tracking cares about, and
         * the transition is the only moment it is worth queueing: the endpoint
         * fires every few seconds while a chapter scrolls, and pushing per ping
         * would be one mutation per page for the same answer.
         *
         * Incognito is checked here rather than relied on to have stopped the write. When the
         * source is incognito `recordProgress()` returns an unsaved model, so `is_read` is
         * null and the condition is already false, but a guard that works by accident is a
         * guard that breaks the moment the model changes. A push to a third party is the one
         * thing in this class that leaves the machine, so it asks the question directly.
         */
        if (! $wasRead && $chapterState->is_read && ! $reading->suppresses($request->user(), $manga)) {
            app(Tracking::class)->scheduleSync($request->user(), $manga);
        }

        return response()->json(['ok' => true, 'page' => $page]);
    }

    /**
     * The current page, from JSON or a form post.
     */
    private function pageFrom(Request $request): ?int
    {
        $raw = $request->input('page');

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $page = (int) $raw;

        // A negative page is a client bug and a page above the chapter is a stale
        // one, from a source whose chapter got shorter. Neither is worth storing.
        return $page < 0 ? null : $page;
    }

    /**
     * The chapter's page count, when the browser knows it.
     *
     * This is the count fetchChapterPages produced, and it is the first moment it
     * exists: Suwayomi reports -1 for every chapter whose pages have not been
     * fetched. Without it, "read" cannot be inferred from a page number.
     */
    private function totalFrom(Request $request): ?int
    {
        $raw = $request->input('total');

        if ($raw === null || ! is_numeric($raw)) {
            return null;
        }

        $total = (int) $raw;

        return $total > 0 ? $total : null;
    }
}
