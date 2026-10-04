<?php

namespace App\Http\Controllers;

use App\Covers\CoverStore;
use App\Models\SeriesState;
use App\Support\Deployment;
use App\Suwayomi\AuthManager;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
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
 * ## Cost shape
 *
 * One grouped query resolves the primary reader, one query lists their
 * library rows, and titles come from the same `fetchMany` batch the library
 * page uses (measured 246ms for 300 ids; this asks for at most
 * `papyrus.showcase.count`). No call is made per cover. The payload is
 * cached for `showcase.cache_seconds`, so a browser refresh of the login
 * screen costs no Suwayomi round trip at all; a cover added a minute ago
 * joins the wall when the cache turns over, which is the right trade for a
 * page nobody stares at under deadline.
 */
class ShowcaseController extends Controller
{
    private const CACHE_KEY = 'showcase:payload:v1';

    /**
     * Thumbnail cache window. Public, because the reader is signed out here
     * and a shared cache is what keeps a room full of guests cheap. The window
     * is an hour for source art and ten minutes for custom covers, which can
     * be replaced under a stable URL.
     */
    private const SOURCE_CACHE = 'public, max-age=3600';

    private const CUSTOM_CACHE = 'public, max-age=600';

    public function __invoke(Request $request, Deployment $deployment, SeriesGateway $gateway, CoverStore $covers): mixed
    {
        $this->ensureVisible($deployment);

        $payload = Cache::remember(
            self::CACHE_KEY,
            (int) config('papyrus.showcase.cache_seconds', 300),
            fn (): array => $this->payload($gateway, $covers),
        );

        return response()->json($payload);
    }

    /**
     * One cover of the showcase, custom art first, the source's own otherwise.
     *
     * Streams rather than buffers, on the same grounds as the page proxy: this
     * is an image path and must never become a memory sink. An unresolvable
     * cover is a quiet 404, which the login wall answers by hiding the slot.
     */
    public function cover(Request $request, int $manga, Deployment $deployment, CoverStore $covers, AuthManager $auth): mixed
    {
        $this->ensureVisible($deployment);

        if ($manga <= 0) {
            abort(404);
        }

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
     * @return array{covers: array<int, array{manga: int, title: string, url: string}>}
     */
    private function payload(SeriesGateway $gateway, CoverStore $covers): array
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

        $titles = $this->titles($gateway, $ids);

        $rows = [];

        foreach ($ids as $id) {
            $rows[] = [
                'manga' => $id,
                'title' => (string) ($titles[$id]['title'] ?? ''),
                'url' => route('showcase.cover', ['manga' => $id]),
            ];
        }

        return ['covers' => $rows];
    }

    /**
     * Titles in one batch, and only that. The map is keyed by manga id and a
     * dropped id is normal: the server silently omits what it does not know.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function titles(SeriesGateway $gateway, array $ids): array
    {
        try {
            return $gateway->fetchMany($ids);
        } catch (SuwayomiException) {
            // The wall renders art first and titles when it can. A server
            // that is down still leaves the login page a beautiful place.
            return [];
        }
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
            $response = Http::withToken($auth->token())
                ->withOptions(['stream' => true])
                ->timeout((int) config('papyrus.suwayomi.timeout', 28))
                ->send('GET', $url);
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
