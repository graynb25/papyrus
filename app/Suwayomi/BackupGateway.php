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
 */
class BackupGateway
{
    public function __construct(
        private readonly Gateway $gateway,
        private readonly AuthManager $auth,
    ) {}

    /**
     * Ask Suwayomi to write its own fresh backup, and answer the path to fetch it from.
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
            GQL, ['input' => ['flags' => $flags]]);

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
}
