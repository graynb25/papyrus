<?php

namespace App\Http\Controllers;

use App\Library\ChapterNavigator;
use App\Support\ChapterNotes;
use App\Support\ImageUrl;
use App\Support\ReaderSettings;
use App\Support\ReadingState;
use App\Support\SessionOverride;
use App\Suwayomi\Gateway;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the reader shell, then the browser does the rest.
 *
 * ## Why this is a controller and not a Livewire page
 *
 * Measured: `fetchChapterPages` took 2.0s for a 22 page chapter, and it is the
 * only call that produces a real page count. It happens exactly once, when a
 * chapter is opened.
 *
 * A Livewire round trip per page turn would put that cost on every scroll. So the
 * server sends the page list once and the reader moves between pages with no
 * network at all: a plain <img> per page, and scrolling. Progress is posted
 * back when a page comes into view, debounced, so a reader who scrolls does not
 * generate a request per pixel.
 *
 * The images come from the same-origin proxy, so the browser never holds a
 * Suwayomi token and the upstream host never sees the reader.
 *
 * ## Reading state is ours
 *
 * Suwayomi persists is_read and last_page_read, but it is single-tenant and
 * resolves every request to Admin(1), so its state is not per-user. Everything
 * here is recorded through {@see ReadingState} and is authoritative.
 */
class ReaderController extends Controller
{
    public function __invoke(
        Request $request,
        int $manga,
        int $chapter,
        SeriesGateway $series,
        Gateway $gateway,
        ReadingState $reading,
    ): View|Response {
        // Opening a chapter moves the series pointer, so "continue reading" is
        // right even if the reader only glanced at the page and left.
        $reading->openChapter($request->user(), $manga, $chapter);

        $chapterState = $reading->findChapter($request->user(), $chapter);

        try {
            $pages = $series->pages($chapter);
        } catch (SuwayomiException $e) {
            // 502 rather than 500: the request was well formed and we are the ones
            // who could not reach the upstream, so this is a bad gateway.
            return response()->view('reader.error', [
                'mangaId' => $manga,
                'chapterId' => $chapter,
                'message' => $e->getMessage(),
                'isTimeout' => $e->isTimeout(),
            ], 502);
        }

        /*
         * The server reported a real page count inside fetchChapterPages, so this
         * is the first moment the count is knowable. Before this, pageCount is -1
         * for every chapter and no progress percentage can be computed.
         */
        if ($pages['page_count'] > 0) {
            $reading->recordProgress(
                $request->user(),
                $manga,
                $chapter,
                (int) ($chapterState->last_page_read ?? 0),
                $pages['page_count'],
            );
        }

        return view('reader.show', [
            'mangaId' => $manga,
            'chapterId' => $chapter,

            // The server's own paths, reprefixed for the proxy. They are passed
            // through rather than rebuilt from the chapter id because the page
            // endpoint addresses chapters by source order, not by id: measured
            // live, fetchChapterPages for chapter 3178 returned
            // /api/v1/manga/116/chapter/2/page/0, and the id-shaped URL 404s.
            // The allowlist still holds: the proxy validates the path it is
            // asked to stream, so nothing but a matching page URL renders.
            'pages' => $gateway->resolvePageUrls($pages['pages'], ImageUrl::defaultFormat()),

            'pageCount' => $pages['page_count'],
            'resumeAt' => (int) ($chapterState->last_page_read ?? 0),
            'format' => ImageUrl::defaultFormat(),
            'neighbours' => $this->neighbours($series, $manga, $chapter),

            // Resolved once here rather than in the template, so the clamping and
            // the colour validation run server side. The browser is handed values
            // it can render, never values it has to trust.
            /*
             * The session layer is read here rather than inside ReaderSettings,
             * because that class is also called from commands and jobs where no
             * session exists and a stored override would be wrong.
             */
            'navigator' => app(ChapterNavigator::class)->forSeries(
                $request->user(),
                $manga,
                $chapter,
            ),

            /*
             * The note on this chapter, as a string rather than a model, because the
             * reader is a plain document with no framework and the note is only ever
             * rendered into a textarea. A model here would be a second thing the view
             * could get wrong.
             */
            'note' => app(ChapterNotes::class)->find($request->user(), $chapter)->body ?? '',

            'settings' => app(ReaderSettings::class)->resolve(
                $request->user(),
                $manga,
                app(SessionOverride::class)->read(),
            ),

            'hasSessionOverride' => app(SessionOverride::class)->active(),
        ]);
    }

    /**
     * Store a settings override for this series.
     *
     * Separate from the page render so the reader's own controls can save without
     * a full page reload, and so the series page can set the mode from the same
     * place the reader reads it from.
     */
    public function updateSettings(Request $request, int $manga, ReaderSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.mode' => ['nullable', 'in:vertical,paged'],
            'settings.width' => ['nullable', 'numeric', 'min:45', 'max:100'],
            'settings.gap' => ['nullable', 'numeric', 'min:0', 'max:64'],
            'settings.zoom' => ['nullable', 'numeric', 'min:0.5', 'max:3'],
            'settings.background' => ['nullable', 'string', 'max:32'],
            'settings.long_strip' => ['nullable', 'boolean'],
            'settings.fit_height' => ['nullable', 'boolean'],
            'settings.hide_tap_zones' => ['nullable', 'boolean'],
            'settings.brightness' => ['nullable', 'numeric', 'min:0.2', 'max:2'],
            'settings.contrast' => ['nullable', 'numeric', 'min:0.3', 'max:2'],
            'settings.hue' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'settings.invert' => ['nullable', 'boolean'],
            'settings.grayscale' => ['nullable', 'boolean'],
        ]);

        $settings->setSeriesOverride($request->user(), $manga, $validated['settings']);

        return response()->json(['ok' => true]);
    }

    /**
     * Filters, for the duration of this visit unless the reader says otherwise.
     *
     * A separate route from `updateSettings` because the default is the opposite. A
     * reader dragging a brightness slider is almost certainly adjusting for the light
     * they are in right now, so writing it to the series would follow them to a desk
     * they are not sitting at. The existing route keeps writing durably, because that
     * is what "save settings" has always meant here.
     *
     * The response carries the clamped values rather than the submitted ones, so the
     * script sets what the server resolved. A client that sent 5 for brightness would
     * otherwise paint a value the server refused.
     */
    public function updateFilters(Request $request, int $manga, ReaderSettings $settings, SessionOverride $session): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.brightness' => ['nullable', 'numeric', 'min:0.2', 'max:2'],
            'settings.contrast' => ['nullable', 'numeric', 'min:0.3', 'max:2'],
            'settings.hue' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'settings.invert' => ['nullable', 'boolean'],
            'settings.grayscale' => ['nullable', 'boolean'],
            'settings.width' => ['nullable', 'numeric', 'min:45', 'max:100'],
            'settings.zoom' => ['nullable', 'numeric', 'min:0.5', 'max:3'],

            /*
             * Explicitly a boolean, and the only way out of the session layer. A
             * missing key means false, so a reader who does not send it gets a
             * session-scoped change, which is the safe default.
             */
            'remember' => ['nullable', 'boolean'],
        ]);

        $wants = $validated['settings'];
        $remember = (bool) ($validated['remember'] ?? false);

        if ($remember) {
            // Durable, and the session copy goes, so the two never disagree about
            // which value is in effect.
            $settings->setSeriesOverride($request->user(), $manga, $wants);
            $session->clear();
        } else {
            $session->write($wants);
        }

        $resolved = $settings->resolve(
            $request->user(),
            $manga,
            $remember ? [] : $session->read(),
        );

        return response()->json([
            'ok' => true,
            'remembered' => $remember,
            'settings' => $resolved,
        ]);
    }

    /**
     * Drop every session-scoped filter, back to the durable settings.
     */
    public function clearFilters(Request $request, int $manga, ReaderSettings $settings, SessionOverride $session): JsonResponse
    {
        $session->clear();

        return response()->json([
            'ok' => true,
            'settings' => $settings->resolve($request->user(), $manga),
        ]);
    }

    /**
     * The reader's note on the chapter they are looking at.
     *
     * One endpoint for writing and clearing, because the reader's gesture is the same
     * either way: an empty body means forget it. See the route comment.
     */
    public function updateNote(Request $request, int $manga, int $chapter, ChapterNotes $notes): JsonResponse
    {
        /*
         * `nullable` is load-bearing, and its absence is a trap worth naming.
         *
         * Laravel's `string` rule rejects an empty string: `trim('') === ''` counts as
         * not a string. So `['present', 'string']` cannot express "clearing this is a
         * legitimate request", and the clear came back as a 422 that read as a bug.
         * `nullable` is what lets an empty body through, and it works because
         * ConvertEmptyStringsToNull turns `''` into null before validation.
         *
         * The bound is here because the column is text and a note is a memo. 10,000
         * characters is far past anything a person types in front of a page of art, and
         * it keeps the column from being usable as free storage.
         */
        $validated = $request->validate([
            'body' => ['present', 'nullable', 'string', 'max:10000'],
        ]);

        $result = $notes->save(
            $request->user(),
            $manga,
            $chapter,
            (string) $validated['body'],
        );

        return response()->json($result);
    }

    /**
     * The chapters either side of this one, by the source's own ordering.
     *
     * Read from the series rather than passed in, because a deep link into a
     * chapter carries no list and the reader still needs somewhere to go. It is
     * one cheap query: `manga(id:).chapters` takes no arguments and returns the
     * whole list in the response we already need for the series page.
     *
     * Ascending order is what a reader means by "next chapter", so descending
     * order means the arithmetic reverses. Mihon behaves the same way.
     *
     * @return array{previous: int|null, next: int|null}
     */
    private function neighbours(SeriesGateway $gateway, int $mangaId, int $chapterId): array
    {
        $chapters = $gateway->find($mangaId)['chapters']['nodes'] ?? [];

        if ($chapters === []) {
            return ['previous' => null, 'next' => null];
        }

        usort($chapters, static function (array $a, array $b): int {
            $byOrder = ($a['sourceOrder'] ?? 0) <=> ($b['sourceOrder'] ?? 0);

            return $byOrder !== 0
                ? $byOrder
                : (($a['chapterNumber'] ?? 0) <=> ($b['chapterNumber'] ?? 0));
        });

        $ids = array_map(static fn (array $c): int => (int) $c['id'], $chapters);
        $index = array_search($chapterId, $ids, true);

        if ($index === false) {
            // The chapter is not in the list, so it came from a stale link or a
            // source that changed underneath us. Neither neighbour is knowable.
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $index > 0 ? $ids[$index - 1] : null,
            'next' => isset($ids[$index + 1]) ? $ids[$index + 1] : null,
        ];
    }
}
