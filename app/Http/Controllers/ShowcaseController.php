<?php

namespace App\Http\Controllers;

use App\Covers\CoverStore;
use App\Models\SeriesState;
use App\Support\Deployment;
use App\Suwayomi\AuthManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The login screen's showcase: the reader's own collection as ambient art.
 *
 * ## Why this is public at all
 *
 * Every other cover path is auth-gated, and a guest's browser cannot hold a
 * Suwayomi token. So the wall needs its own door, and a public door on a
 * multi-tenant app means a stranger could read someone's library. That is
 * exactly why it is gated to `single` deployment mode and switchable off:
 * a single-reader install is the one case where the collection has one owner
 * and the login screen showing it leaks nothing that is not already implied.
 * In `shared` or `public` mode, or with the switch off, both endpoints 404
 * and the login page falls back to generative art without a trace of a real
 * collection.
 *
 * The payload carries the manga id and the cover URL, and no title. A wall of
 * art is what the login screen is for; a readable list of the reader's series
 * is more than the effect needs, and a guest who can reach the listener should
 * not be able to read the collection as a list.
 *
 * ## Cost shape
 *
 * One grouped query resolves the primary reader and one query lists their
 * library rows. The payload carries no titles, so it asks Suwayomi for
 * nothing; each cover is fetched from the cover route, which streams one
 * thumbnail and caches it publicly. The payload is cached for
 * `showcase.cache_seconds`, so a browser refresh of the login screen costs no
 * Suwayomi round trip at all; a cover added a minute ago joins the wall when
 * the cache turns over, which is the right trade for a page nobody stares at
 * under deadline.
 */
class ShowcaseController extends Controller
{
    /**
     * The version suffix is deliberate: a payload cached before a shape change
     * must not outlive it. The title key was removed under v2.
     */
    private const CACHE_KEY = 'showcase:payload:v2';

    /**
     * Thumbnail cache window. Public, because the reader is signed out here
     * and a shared cache is what keeps a room full of guests cheap. The window
     * is an hour for source art and ten minutes for custom covers, which can
     * be replaced under a stable URL.
     */
    private const SOURCE_CACHE = 'public, max-age=3600';

    private const CUSTOM_CACHE = 'public, max-age=600';

    public function __invoke(Request $request, Deployment $deployment): mixed
    {
        $this->ensureVisible($deployment);

        return response()->json($this->wall());
    }

    /**
     * One cover of the showcase, custom art first, the source's own otherwise.
     *
     * Streams rather than buffers, on the same grounds as the page proxy: this
     * is an image path and must never become a memory sink. An unresolvable
     * cover is a quiet 404, which the login wall answers by hiding the slot.
     *
     * An id outside the wall is refused before Suwayomi is asked anything, so
     * the route cannot be walked to count the reader's library or fetch art for
     * series the login screen never shows. The refusal is the same 404 an id the
     * server does not know receives, which keeps the answer free of whether that
     * id exists.
     */
    public function cover(Request $request, int $manga, Deployment $deployment, CoverStore $covers, AuthManager $auth): mixed
    {
        $this->ensureVisible($deployment);

        if ($manga <= 0) {
            abort(404);
        }

        abort_unless(in_array($manga, $this->visibleIds(), true), 404);

        $userId = $this->primaryUserId();

        if ($userId !== null) {
            $custom = $covers->find($userId, $manga);
            $file = $custom === null ? null : $covers->read($custom);

            if ($file !== null) {
                return response($file['contents'], 200, [
                    'Content-Type' => $file['mime'],
                    'Cache-Control' => self::CUSTOM_CACHE,
                    'X-Content-Type-Options' => 'nosniff',
                    'Content-Length' => (string) strlen($file['contents']),
                ]);
            }
        }

        return $this->streamThumbnail($manga, $auth);
    }

    /**
     * The cached wall payload, the one source both endpoints read.
     *
     * The cover route reuses this rather than running its own query, so the ids
     * it accepts cannot drift from the ids the payload advertises. A payload
     * cached before a library change keeps both answers in step, which is the
     * same ten-minute window the wall already had.
     *
     * @return array{covers: array<int, array{manga: int, url: string}>}
     */
    private function wall(): array
    {
        return $this->normaliseWall(Cache::remember(
            self::CACHE_KEY,
            (int) config('papyrus.showcase.cache_seconds', 300),
            fn (): array => $this->payload(),
        ));
    }

    /**
     * The cached payload, with any older or malformed shape reduced to what the wall can show.
     *
     * The cache can hold an older payload shape after an upgrade. A cover without an integer
     * manga id or a string URL is dropped, and a malformed payload becomes an empty wall instead
     * of an error. The `mixed` parameter is deliberate: it keeps the checks meaningful instead of
     * letting the callback's return type make them look redundant.
     *
     * @return array{covers: array<int, array{manga: int, url: string}>}
     */
    private function normaliseWall(mixed $cached): array
    {
        if (! is_array($cached) || ! is_array($cached['covers'] ?? null)) {
            return ['covers' => []];
        }

        $covers = [];

        foreach ($cached['covers'] as $cover) {
            if (! is_array($cover)) {
                continue;
            }

            $manga = $cover['manga'] ?? null;
            $url = $cover['url'] ?? null;

            if (is_int($manga) && is_string($url)) {
                $covers[] = ['manga' => $manga, 'url' => $url];
            }
        }

        return ['covers' => $covers];
    }

    /**
     * The ids the wall advertises, and therefore the only ids a cover may name.
     *
     * An empty list is the fail-closed answer: no library means no cover, not a
     * route that falls through and asks Suwayomi anyway.
     *
     * @return list<int>
     */
    private function visibleIds(): array
    {
        return array_values(array_map(
            static fn (array $cover): int => (int) $cover['manga'],
            $this->wall()['covers'],
        ));
    }

    /**
     * The gate both endpoints share. It fails closed.
     */
    private function ensureVisible(Deployment $deployment): void
    {
        abort_unless((bool) config('papyrus.showcase.enabled', true), 404);
        abort_unless($deployment->isSingle(), 404);
    }

    /**
     * The reader whose collection the wall shows.
     *
     * The one with the most in-library series. On a single-reader install that
     * is unambiguous; if several accounts ever exist, it is still the sensible
     * answer, because the alternative is a config key naming a user id.
     *
     * The query runs without the user scope on purpose: there is no
     * authenticated reader here, the scope would fail closed, and every row
     * would vanish (the same shape as trap 127 in the engineering notes).
     * This endpoint is the one place that reads across accounts deliberately.
     */
    private function primaryUserId(): ?int
    {
        $id = SeriesState::query()
            ->withoutUserScope()
            ->where('in_library', true)
            ->groupBy('user_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('user_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The wall's rows: the primary reader's collection, favourites first.
     *
     * Then recently read, then recently added. Rows whose series the server
     * has never heard of stay in the list, because a custom cover can still
     * exist for one; the login page hides whatever the cover route 404s on.
     *
     * @return array{covers: array<int, array{manga: int, url: string}>}
     */
    private function payload(): array
    {
        $userId = $this->primaryUserId();

        if ($userId === null) {
            return ['covers' => []];
        }

        $states = SeriesState::query()
            ->forUser($userId)
            ->where('in_library', true)
            ->orderByRaw('favourited_at IS NULL, favourited_at DESC')
            ->orderByRaw('last_read_at IS NULL, last_read_at DESC')
            ->orderByDesc('added_at')
            ->limit(max(1, (int) config('papyrus.showcase.count', 18)))
            ->get(['suwayomi_manga_id']);

        $ids = $states->map(fn (SeriesState $state): int => (int) $state->suwayomi_manga_id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return ['covers' => []];
        }

        $rows = [];

        foreach ($ids as $id) {
            $rows[] = [
                'manga' => $id,
                'url' => route('showcase.cover', ['manga' => $id]),
            ];
        }

        return ['covers' => $rows];
    }

    /**
     * Stream a source thumbnail with a public cache header.
     *
     * The path is fixed here rather than taken from the request, so this
     * endpoint cannot be steered anywhere else in Suwayomi; it is a thumbnail
     * door, never a second proxy.
     */
    private function streamThumbnail(int $manga, AuthManager $auth): StreamedResponse
    {
        $url = rtrim((string) config('papyrus.suwayomi.url'), '/')."/api/v1/manga/{$manga}/thumbnail";

        try {
            $response = $auth->attachToken(
                Http::withOptions(['stream' => true])
                    ->timeout((int) config('papyrus.suwayomi.timeout', 28))
            )->send('GET', $url);
        } catch (Throwable) {
            /*
             * Anything thrown here - a dead connection, a token that could not
             * be renewed under the load of twenty covers arriving at once - is
             * the same fact for this endpoint: this cover is not available. A
             * 404 keeps the wall graceful, where an unhandled 500 would put an
             * error page in the browser console for every failing slot.
             */
            abort(404);
        }

        if (! $response->successful()) {
            abort(404);
        }

        $body = $response->toPsrResponse()->getBody();

        $headers = [
            'Content-Type' => $response->header('Content-Type') ?: 'application/octet-stream',
            'Cache-Control' => self::SOURCE_CACHE,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (($length = $response->header('Content-Length')) !== '') {
            $headers['Content-Length'] = $length;
        }

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(64 * 1024);
                flush();
            }
        }, 200, $headers);
    }
}
