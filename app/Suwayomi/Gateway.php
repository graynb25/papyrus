<?php

namespace App\Suwayomi;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * The single door to Suwayomi.
 *
 * Everything the app knows about the outside world goes through execute(). It
 * owns the three things that are easy to get subtly wrong:
 *
 *  - the 30s budget. The server enforces a hardcoded 30s execution timeout, so
 *    we stay under it and raise our own Timeout rather than letting the socket
 *    die with no useful context.
 *  - the auth retry. One re-authentication, once, then a typed exception.
 *  - error normalisation. A GraphQL `errors` array becomes a Query failure with
 *    the raw payload preserved, never a silent null.
 *
 * The auth retry is not only about HTTP 401. Measured on v2.3.2243: an expired
 * access token on a `@RequireAuth` field comes back as **HTTP 200** with the
 * body's `errors` array holding "Exception while fetching data (/extensions) :
 * Unauthorized" followed by an UnauthorizedException stack trace. The status is
 * 200 because graphql-java catches the throw inside the resolver and reports it
 * as a field error. Checking only the status code therefore misses every
 * expiry, which is how a dead token stayed in the cache for 45 minutes at a
 * time and every authenticated call after it failed until the cache was
 * cleared by hand.
 *
 * Latency from the last call is exposed for the source health ledger, which
 * subscribes in Phase 1.
 */
class Gateway
{
    /**
     * Marker for "the token was rejected", wherever it shows up.
     *
     * Matched on the Kotlin class name rather than the word "Unauthorized",
     * since the message is a wrapped exception plus a stack trace and the bare
     * word also appears in unrelated text. suwayomi.tachidesk.server.user is
     * the package that owns UserType.requireUser, the call that throws.
     */
    private const AUTH_ERROR = 'suwayomi.tachidesk.server.user.UnauthorizedException';

    private ?float $lastLatencyMs = null;

    public function __construct(
        private readonly Transport $transport,
        private readonly AuthManager $auth,
    ) {}

    /**
     * Run a GraphQL document and return its `data` payload.
     *
     * @param  array<string, mixed>  $variables
     * @param  int|null  $timeout  Per-call budget override, in seconds.
     * @return array<string, mixed>
     *
     * @throws SuwayomiException
     */
    public function execute(string $document, array $variables = [], ?int $timeout = null): array
    {
        $started = microtime(true);

        $response = $this->send($document, $variables, $timeout);

        if ($this->rejectedAuth($response)) {
            // A rejected token usually means the server restarted or rotated
            // its secret, so re-login rather than trying to refresh.
            $this->auth->forget();

            $response = $this->send($document, $variables, $timeout);
        }

        $elapsedMs = (microtime(true) - $started) * 1000;
        $this->lastLatencyMs = $elapsedMs;

        return $this->unwrap($response, $elapsedMs);
    }

    /**
     * Whether this response means our bearer token was refused.
     *
     * HTTP 401 is the obvious case and is rare. The common one is the 200 plus
     * GraphQL error described on the class, so both are checked here.
     */
    private function rejectedAuth(Response $response): bool
    {
        if ($response->status() === 401) {
            return true;
        }

        $errors = $response->json('errors');

        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (is_array($error) && str_contains((string) ($error['message'] ?? ''), self::AUTH_ERROR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Page image URLs come back from fetchChapterPages as root-relative
     * /api/v1/... paths that ignore any configured subpath. We always rebuild
     * them against the public prefix nginx actually serves from.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    public function resolvePageUrls(array $paths, ?string $format = null, ?int $width = null): array
    {
        $prefix = rtrim((string) config('papyrus.suwayomi.public_path_prefix', '/suwayomi'), '/');

        return array_map(function (string $path) use ($prefix, $format, $width): string {
            // Strip any origin the server believed it was mounted at, then
            // reduce the path to its /api/v1-relative remainder so we can
            // re-attach it under the prefix nginx actually serves from.
            $path = preg_replace('#^https?://[^/]+#', '', $path) ?? $path;
            $path = ltrim($path, '/');
            $path = preg_replace('#^api/v1/#', '', $path) ?? $path;

            $query = [];

            if ($format !== null && $format !== '') {
                $query['format'] = $format;
            }

            if ($width !== null && $width > 0) {
                $query['width'] = $width;
            }

            $suffix = $query === [] ? '' : '?'.http_build_query($query);

            return $prefix.'/api/v1/'.$path.$suffix;
        }, $paths);
    }

    public function lastLatencyMs(): ?float
    {
        return $this->lastLatencyMs;
    }

    /**
     * @param  array<string, mixed>  $variables
     *
     * @throws SuwayomiException
     */
    private function send(string $document, array $variables, ?int $timeout = null): Response
    {
        try {
            return $this->transport->post(
                ['query' => $document, 'variables' => (object) $variables],
                $this->auth->token(),
                $timeout,
            );
        } catch (SuwayomiException $e) {
            // A connection-level failure is a timeout as far as callers care.
            throw $e->isTimeout()
                ? $e
                : new SuwayomiException($e->getMessage(), $e->kind, $e->errors, $e->elapsedMs, $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SuwayomiException
     */
    private function unwrap(Response $response, float $elapsedMs): array
    {
        if ($this->rejectedAuth($response)) {
            // Still rejected after one fresh login, so this is not an expiry.
            // It is credentials the server will not accept, and saying so is
            // more useful to the operator than a 4KB stack trace.
            throw SuwayomiException::unauthenticated();
        }

        if ($response->serverError()) {
            throw new SuwayomiException(
                "Suwayomi returned HTTP {$response->status()}.",
                FailureKind::Unavailable,
                elapsedMs: $elapsedMs,
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new SuwayomiException(
                'Suwayomi returned a non-JSON body. Is SUWAYOMI_URL pointing at the server root rather than the GraphQL endpoint?',
                FailureKind::Unavailable,
                elapsedMs: $elapsedMs,
            );
        }

        if (! empty($body['errors'])) {
            $errors = $body['errors'];

            Log::debug('Suwayomi GraphQL error', ['errors' => $errors, 'elapsed_ms' => $elapsedMs]);

            throw SuwayomiException::query($errors, $elapsedMs);
        }

        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw new SuwayomiException(
                'Suwayomi returned neither data nor errors.',
                FailureKind::Unknown,
                elapsedMs: $elapsedMs,
            );
        }

        return $data;
    }
}
