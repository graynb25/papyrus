<?php

namespace App\AniList;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One lookup against AniList's public GraphQL API.
 *
 * ## No key, and that is the point
 *
 * `graphql.anilist.co` answers unauthenticated queries. Measured from this box: HTTP
 * 200 on a bare `Media(search:)` with no headers beyond `Accept`. AniList's own API
 * supports OAuth and client credentials for higher limits, and none of that is
 * needed for a lookup.
 *
 * ## The rate limit is 30 a minute, and it is enforced
 *
 * Measured from the response headers: `x-ratelimit-limit: 30`, with
 * `x-ratelimit-remaining` counting down and `x-ratelimit-reset` naming the window.
 * A 429 arrives with a `Retry-After`.
 *
 * This is the constraint that shapes everything upstream. One fan-out produces 1,080
 * raw hits and 91 distinct titles, so resolving them is 91 requests, which at 30 a
 * minute is over three minutes. That is why lookups happen in a job that re-books
 * itself, and why the cache is keyed on the title, and why the render path never
 * calls this class at all.
 *
 * ## A refusal is not an absence
 *
 * The distinction is the whole reason this class exists rather than a bare
 * `Http::post()`. Measured, the hard way: a probe that treated every non-200 as "no
 * match" put a 125-member group of hits into the no-match bucket, because the tail of
 * its sweep was being rate limited. Seventy-one of ninety-one titles came back
 * "unmatched" and the verdict was unreadable. So a 429 throws
 * {@see AniListRefusedException} and is retried by the caller, and only a clean 200
 * with a null `Media` counts as a title AniList does not have.
 */
class Client
{
    private const ENDPOINT = 'https://graphql.anilist.co';

    /**
     * The endpoint, overridable so a refusal is testable.
     *
     * The default is measured from this box. It is a constant rather than only a
     * constant because pointing it somewhere unreachable is the only honest way to
     * exercise the failure path, and a client whose endpoint cannot be moved cannot
     * have that path checked.
     */
    private function endpoint(): string
    {
        return rtrim((string) config('papyrus.anilist.url', self::ENDPOINT), '/');
    }

    /**
     * The query.
     *
     * Field choices, all measured:
     *
     *  - `synonyms` is the one that matters. Measured: searching
     *    "The Berserker's Second Playthrough" returns romaji
     *    "Game Sok 2-hoecha Gwangjeonsa" and native "게임 속 2회차 광전사", and the
     *    English title appears only here.
     *  - `title { romaji english native }` because no single one is reliable: the
     *    English is null for a series with no official translation, and the romaji
     *    for one published in English is a transliteration of the Japanese.
     *  - `staff` because it is the original author, which no source reports
     *    consistently. Measured: "Original Story: Insaengmanghaesseum, Story & Art:
     *    Isang" against sources variously reporting "Life Ruined", "Isang",
     *    "Isang, Life Ruined" and an empty string.
     *  - `isAdult` recorded but never acted on. See the model.
     *  - `chapters` is AniList's own count, which is a different number from a
     *    scanlation's, so it is stored as reference data and not used as a total.
     */
    private const LOOKUP = <<<'GQL'
        query Lookup($search: String) {
            Media(search: $search, type: MANGA) {
                id
                idMal
                format
                countryOfOrigin
                chapters
                status
                isAdult
                title {
                    romaji
                    english
                    native
                }
                synonyms
                staff(perPage: 6) {
                    edges {
                        role
                        node {
                            name { full }
                        }
                    }
                }
            }
        }
        GQL;

    /**
     * Look a title up.
     *
     * @return array<string, mixed>|null null when AniList has no such title, which
     *                                   is an answer and not a failure
     *
     * @throws AniListRefusedException on a rate limit or a transport failure
     */
    public function lookup(string $title): ?array
    {
        $title = trim($title);

        if ($title === '') {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                /*
                 * AniList asks callers to identify themselves, and a self-hosted app
                 * should. It also makes a rate limit legible to whoever is looking
                 * at the API's side of it.
                 */
                'User-Agent' => 'Papyrus (self-hosted manga reader)',
            ])
                ->timeout((int) config('papyrus.anilist.timeout', 15))
                ->retry(
                    (int) config('papyrus.anilist.retries', 2),
                    (int) config('papyrus.anilist.retry_delay_ms', 1500),
                    fn ($attempt) => $this->isTransient($attempt),
                    throw: false,
                )
                ->post($this->endpoint(), [
                    'query' => self::LOOKUP,
                    'variables' => ['search' => $title],
                ]);
        } catch (ConnectionException $e) {
            throw new AniListRefusedException(
                'Could not reach AniList: '.$e->getMessage(),
                $this->retryAfter(0),
                $e,
            );
        }

        if ($response->status() === 404) {
            /*
             * Not a refusal, and only here. Measured on 2026-10-02 against the live API: a
             * search that matches nothing answers HTTP 404 with a "Not Found." error and
             * Media null. A title AniList has never heard of is the ordinary case, not an
             * error, and reading it as a refusal makes the caller retry a question that has
             * already been answered.
             *
             * It was worse than a wasted retry. `ImportAniListProgress` backs off and
             * re-books on a refusal, so a library whose first title is not on AniList never
             * got past it: the job released, retried, hit the same 404, and the page sat on
             * "Checking 0 of 0 series" for ever. The rate limit is 429 and a dead token is
             * 401, both handled below, and `forUser()` keeps treating 404 as an error
             * because a 404 there really is one.
             *
             * The tests never caught it because the fakes answer an unmatched search with a
             * 200 carrying Media null, which is a shape this endpoint does not produce. A
             * fake has to be copied from a real response, not from what would be tidier.
             */
            return null;
        }

        if ($response->status() === 429 || ! $response->successful()) {
            throw new AniListRefusedException(
                'AniList refused the request (HTTP '.$response->status().').',
                $this->retryAfter((int) $response->header('retry-after')),
            );
        }

        $media = $response->json()['data']['Media'] ?? null;

        if (! is_array($media)) {
            // A clean 200 with no Media. AniList answered the question and the
            // answer is no, so this is null and not an exception.
            return null;
        }

        return $media;
    }

    /**
     * Seconds to wait before trying again, from the header or the rate limit reset.
     */
    private function retryAfter(int $header): int
    {
        if ($header > 0) {
            return min($header, (int) config('papyrus.anilist.max_backoff_seconds', 65));
        }

        return (int) config('papyrus.anilist.max_backoff_seconds', 65);
    }

    /**
     * Run a document as a specific AniList user.
     *
     * The tracking write path. Same endpoint as a lookup, different authority:
     * the reader's token decides whose list `SaveMediaListEntry` touches.
     *
     * A rejected token is its own exception and not a refusal. AniList answers
     * an expired token with HTTP 401, and no amount of retrying or backing off
     * is ever going to improve it; the caller has to ask the reader to connect
     * again. A 429 still means wait.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> the `data` payload
     *
     * @throws AniListTokenRejected when the token is dead
     * @throws AniListRefusedException on a rate limit or a transport failure
     */
    public function forUser(string $accessToken, string $document, array $variables = []): array
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'Papyrus (self-hosted manga reader)',
                'Authorization' => 'Bearer '.$accessToken,
            ])
                ->timeout((int) config('papyrus.anilist.timeout', 15))
                ->post($this->endpoint(), [
                    'query' => $document,
                    'variables' => (object) $variables,
                ]);
        } catch (ConnectionException $e) {
            throw new AniListRefusedException(
                'Could not reach AniList: '.$e->getMessage(),
                $this->retryAfter(0),
                $e,
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new AniListTokenRejected('AniList rejected the saved token.');
        }

        if ($response->status() === 429 || ! $response->successful()) {
            throw new AniListRefusedException(
                'AniList refused the request (HTTP '.$response->status().').',
                $this->retryAfter((int) $response->header('retry-after')),
            );
        }

        $errors = $response->json('errors');

        foreach ((array) $errors as $error) {
            $message = (string) ($error['message'] ?? '');

            // AniList reports a dead token as a GraphQL error on a 200 in some
            // deployments, with the 401 reserved for the raw token endpoints.
            if (str_contains(strtolower($message), 'invalid token') || str_contains(strtolower($message), 'unauthorized')) {
                throw new AniListTokenRejected('AniList rejected the saved token.');
            }
        }

        if (! empty($errors)) {
            throw new AniListRefusedException(
                'AniList refused the mutation: '.(string) ($errors[0]['message'] ?? 'unknown error'),
                $this->retryAfter(0),
            );
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    /**
     * Whether an attempt is worth repeating.
     *
     * A 429 and a transport blip are. A 200 is not, and neither is a 400, which would
     * mean the query is wrong and repeating it wastes the budget.
     */
    private function isTransient(mixed $attempt): bool
    {
        $response = $attempt instanceof Response ? $attempt : null;

        if ($response === null) {
            return true;
        }

        return $response->status() === 429 || $response->serverError();
    }
}
