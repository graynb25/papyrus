<?php

namespace App\Suwayomi;

use App\Support\Suwayomi\FileUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * The manga half of an in-app backup, asked of Suwayomi while the services keep running.
 *
 * The plan is the launcher pair's own: Suwayomi writes its backup itself with
 * {@see createBackup()}, which answers a rooted `/api/graphql/files/backup/<name>.tachibk`
 * path, and {@see download()} streams that file to a local sink before anything is moved.
 * Restoring later is Suwayomi's own `restoreBackup` mutation, never a file copy: the file
 * handed to a restore is the upload shape the schema types as a NON_NULL `Upload`, which is
 * not what a copy of the live H2 file would be.
 *
 * ## Measured, not assumed
 *
 * The mutation and its answer were run once against a live v2.4.2366 server:
 * `createBackup(input: { flags: ... })` answered
 * `{"url": "/api/graphql/files/backup/org.suwayomi.tachidesk._2026-10-20_13-40.tachibk",
 * "clientMutationId": null}` and the GET of that url answered 200 with a gzip whose body
 * decompressed to a protobuf headline. The url is root-relative, the leading slash included,
 * so it joins onto `papyrus.suwayomi.url` and never onto a public path prefix: this is a
 * server-to-server transfer, not one the browser is asked to make.
 *
 * `PartialBackupFlagsInput` carries seven optional booleans (includeChapters, includeManga,
 * includeHistory, includeCategories, includeClientData, includeServerSettings, includeTracking)
 * and `CreateBackupInput` carries `clientMutationId` plus the flags, both optional, so an
 * empty flags array is a valid input. A misspelled flag is not silently ignored: GraphQL
 * rejects the unknown field and the failure surfaces as a query error through the Gateway.
 *
 * The file GET answered without a token on a server with auth disabled, but the token is
 * attached the same way the page exporter attaches one, because every server of the
 * AuthMode.UI_LOGIN kind refuses a bare request.
 *
 * ## The restore side, measured against a live server
 *
 * Suwayomi also answers a REST route for exactly this upload:
 * `POST /api/v1/backup/import` with the whole `.tachibk` as the raw body under
 * `application/x-gzip` and the bearer token in the header. On a v2.4.2366 server the
 * measured answer was HTTP 200 in 751 ms for a 92 KB file, with the body
 * `{"missingSources":[],"missingTrackers":[],"mangasMissingSources":[]}`. The flight is
 * synchronous: the POST does not return until the server is finished, which is why this
 * method can answer the import's own summary and a restore can ask the next question
 * already knowing the manga half settled.
 *
 * The server also carries `query { restoreStatus(id: "legacy") { state mangaProgress
 * totalManga } }`, the same surface its own web UI polls during a restore. On a server
 * process where no restore has ever run, the answer is null, and after one has run the
 * answer persists for the life of the process. Null therefore means "the server has not
 * restarted its reporting", not "the restore was refused", and everything here says that
 * in the caller's own words rather than inventing a third meaning.
 */
class BackupGateway
{
    /**
     * How long an import of one's whole library is allowed to take.
     *
     * The measured flight for a 92 KB file was three quarters of a second, but the file a
     * real library exports is two orders of magnitude bigger and the server does its own
     * matching and importing inside the answer. A round number an hour under the job's own
     * ceiling is the honest shape: the client waits almost until the job would be killed.
     */
    public const IMPORT_TIMEOUT = 3000;

    public function __construct(
        private readonly Gateway $gateway,
        private readonly AuthManager $auth,
    ) {}

    /**
     * Ask Suwayomi to write its own fresh backup, and answer the path to fetch it from.
     *
     * ## The empty flags are an object, not a list
     *
     * The flags ride the mutation as a GraphQL input object, and an empty PHP array
     * json-encodes as `[]`, a JSON list, which the server refuses before the resolver
     * ever runs: measured against a live v2.4.2366 on both seats, `{"input":{"flags":[]}}`
     * answers `Variable 'input' has an invalid value: Expected type 'Map' but was
     * 'ArrayList'.` The same call with `flags` as an empty object, or with no flags
     * key at all, answers with a url. The cast is therefore the shape of the call
     * itself, not a tidy-up: without it this method cannot ask a real server for
     * anything, and every caller of it (the in-app backup's manga half and the
     * outward export) would be refused by any real server, every time.
     *
     * @param  array<string, bool>  $flags
     * @return string The root-relative path to download.
     *
     * @throws SuwayomiException
     */
    public function createBackup(array $flags = []): string
    {
        $data = $this->gateway->execute(<<<'GQL'
            mutation CreateBackup($input: CreateBackupInput!) {
                createBackup(input: $input) { url }
            }
            GQL, ['input' => ['flags' => (object) $flags]]);

        $url = $data['createBackup']['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new SuwayomiException(
                'Suwayomi answered the backup mutation without a url to fetch the backup from.',
                FailureKind::Unknown,
            );
        }

        return $url;
    }

    /**
     * Stream the backup file to a local sink, chunk by chunk.
     *
     * @throws SuwayomiException
     */
    public function download(string $path, string $sink, ?int $timeout = null): void
    {
        try {
            $response = $this->auth->attachToken(
                Http::withOptions(['stream' => true])
                    ->timeout($timeout ?? (int) config('papyrus.suwayomi.timeout', 28))
            )->send('GET', FileUrl::absolute($path));
        } catch (ConnectionException $e) {
            throw SuwayomiException::unavailable($e->getMessage(), $e);
        }

        if ($response->status() === 401) {
            throw SuwayomiException::unauthenticated();
        }

        if ($response->status() !== 200) {
            throw new SuwayomiException(
                "Suwayomi answered the backup download with HTTP {$response->status()} for {$path}.",
                FailureKind::Unavailable,
            );
        }

        $this->drain($response->toPsrResponse()->getBody(), $sink);
    }

    /**
     * Paint a response body into a sink file a chunk at a time.
     *
     * A backup can outgrow memory, so nothing here accumulates the body. The caller owns
     * the sink's directory and its cleanup: a transfer that breaks partway leaves a
     * partial file behind for it to discard.
     *
     * eof() is asked once per decision. The loop's own reading of it is remembered by
     * static analysis as unchanged by read(), which is why the stall is judged from a
     * single evaluation after the loop rather than a second one inside it.
     *
     * @throws SuwayomiException
     */
    private function drain(StreamInterface $body, string $sink): void
    {
        $handle = fopen($sink, 'wb');

        if ($handle === false) {
            throw new RuntimeException("The backup sink could not be opened for writing: {$sink}");
        }

        try {
            do {
                try {
                    $chunk = $body->read(64 * 1024);
                } catch (Throwable $e) {
                    throw new SuwayomiException(
                        "The backup transfer broke partway through: {$e->getMessage()}",
                        FailureKind::Unavailable,
                        previous: $e,
                    );
                }

                if ($chunk === '') {
                    break;
                }

                if (fwrite($handle, $chunk) === false) {
                    throw new RuntimeException("The backup sink refused a write: {$sink}");
                }
            } while (true);
        } finally {
            fclose($handle);
        }

        // An empty read is the end of the transfer only when the stream itself says it is
        // at its end. A stream that painted nothing while eof() stays false is stalled,
        // and the loop would otherwise walk it forever.
        if (! $body->eof()) {
            throw new SuwayomiException('The backup transfer stalled: the stream painted nothing.', FailureKind::Unavailable);
        }
    }

    /**
     * Send Suwayomi one of its own `.tachibk` exports back along the REST import route.
     *
     * The body is the file itself, read straight off the disk one stream at a time, under
     * the content type the route measures to expect: the whole file, raw, not a form field.
     * The flight is synchronous, so when this answers, the server-side restore has already
     * happened, whatever it made of the file.
     *
     * @param  int|null  $timeout  Seconds the upload may run for; null is {@see IMPORT_TIMEOUT}.
     * @return array{missingSources: list<string>, missingTrackers: list<string>, mangasMissingSources: list<string>}
     *                                                                                                                The import's own report, with each of the three lists it always carries normalised
     *                                                                                                                into plain strings.
     *
     * @throws SuwayomiException
     */
    public function importBackup(string $file, ?int $timeout = null): array
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            throw new SuwayomiException("The manga half could not be opened to send to Suwayomi: {$file}", FailureKind::Unknown);
        }

        try {
            $response = $this->auth->attachToken(
                Http::withOptions(['timeout' => $timeout ?? self::IMPORT_TIMEOUT])
            )->send('POST', FileUrl::absolute('/api/v1/backup/import'), [
                'headers' => ['Content-Type' => 'application/x-gzip'],
                'body' => $handle,
            ]);
        } catch (ConnectionException $e) {
            throw SuwayomiException::unavailable($e->getMessage(), $e);
        } finally {
            fclose($handle);
        }

        if ($response->status() === 401) {
            throw SuwayomiException::unauthenticated();
        }

        if ($response->status() !== 200) {
            throw new SuwayomiException(
                "Suwayomi answered the backup import with HTTP {$response->status()}.",
                FailureKind::Unavailable,
            );
        }

        $answered = $response->json();

        if (! is_array($answered)) {
            throw new SuwayomiException(
                'Suwayomi answered the backup import with something that is not a JSON object, '
                .'so whether anything was imported is unknown. It said: '
               .mb_substr((string) $response->body(), 0, 300),
                FailureKind::Unknown,
            );
        }

        $summary = [
            'missingSources' => [],
            'missingTrackers' => [],
            'mangasMissingSources' => [],
        ];

        $keys = array_keys($summary);

        foreach ($keys as $key) {
            $summary[$key] = self::stringList($answered[$key] ?? null, $key);
        }

        return $summary;
    }

    /**
     * Ask the server what its own restore is doing, in its own vocabulary.
     *
     * @return array{state: string, mangaProgress: int, totalManga: int}|null
     *                                                                        Null when the server reports no restore, which is the measured answer of a
     *                                                                        server process where none ever ran.
     *
     * @throws SuwayomiException
     */
    public function restoreStatus(): ?array
    {
        $data = $this->gateway->execute(<<<'GQL'
            query {
                restoreStatus(id: "legacy") {
                    state
                    mangaProgress
                    totalManga
                }
            }
            GQL);

        if (! array_key_exists('restoreStatus', $data)) {
            throw new SuwayomiException(
                'Suwayomi answered the restore status query without the field, '
                .'so what a restore in it is doing cannot be read.',
                FailureKind::Unknown,
            );
        }

        $status = $data['restoreStatus'];

        if ($status === null) {
            return null;
        }

        if (! is_string($status['state'] ?? null)
            || ! is_int($status['mangaProgress'] ?? null)
            || ! is_int($status['totalManga'] ?? null)
        ) {
            throw new SuwayomiException(
                'Suwayomi answered the restore status query with a status whose fields '
                .'cannot all be read. It said: '.json_encode($status),
                FailureKind::Unknown,
            );
        }

        return [
            'state' => $status['state'],
            'mangaProgress' => $status['mangaProgress'],
            'totalManga' => $status['totalManga'],
        ];
    }

    /**
     * One of the import answer's lists, as a plain list of strings, or a refusal naming what
     * answered instead, because an import report a reader cannot read back is not a report.
     *
     * @return list<string>
     *
     * @throws SuwayomiException
     */
    private static function stringList(mixed $value, string $key): array
    {
        if ($value === null) {
            $value = [];
        }

        if (! is_array($value)) {
            throw new SuwayomiException(
                "Suwayomi answered the backup import with {$key} holding something other than "
                .'a list, so the import report cannot be read. It said: '.json_encode($value),
                FailureKind::Unknown,
            );
        }

        return array_map(static fn (mixed $item): string => (string) $item, array_values($value));
    }
}
