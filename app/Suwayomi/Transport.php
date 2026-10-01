<?php

namespace App\Suwayomi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Raw, unauthenticated GraphQL transport.
 *
 * Deliberately separate from {@see Gateway} so the login mutation can run
 * before a token exists without a circular dependency. Knows nothing about
 * auth, retries, or GraphQL error semantics - it moves bytes.
 */
class Transport
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  int|null  $timeout  Overrides the default budget. Search needs a
     *                             per-source ceiling well under the server's
     *                             own 30s cap, so one slow upstream cannot
     *                             consume the whole run.
     */
    public function post(array $payload, ?string $bearerToken = null, ?int $timeout = null): Response
    {
        $request = Http::baseUrl(rtrim((string) config('papyrus.suwayomi.url'), '/'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('papyrus.suwayomi.connect_timeout', 5))
            ->timeout($timeout ?? (int) config('papyrus.suwayomi.timeout', 28));

        if ($bearerToken !== null) {
            $request = $request->withToken($bearerToken);
        }

        try {
            return $request->post((string) config('papyrus.suwayomi.graphql_path', '/api/graphql'), $payload);
        } catch (ConnectionException $e) {
            throw SuwayomiException::unavailable($e->getMessage(), $e);
        }
    }
}
