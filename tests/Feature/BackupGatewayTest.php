<?php

namespace Tests\Feature;

use App\Suwayomi\BackupGateway;
use App\Suwayomi\FailureKind;
use App\Suwayomi\SuwayomiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The backup mutation and its file download, one at a time, against fakes that mirror the
 * shapes measured against a live v2.4.2366 server: the mutation answered a root-relative
 * `/api/graphql/files/backup/<name>.tachibk` path and the file GET answered 200 bytes in an
 * octet stream.
 */
class BackupGatewayTest extends TestCase
{
    use MintsTokens;

    private const BACKUP_PATH = '/api/graphql/files/backup/org.suwayomi.tachidesk._2026-10-20_13-40.tachibk';

    /** Something a gzip standing in for a real backup would look like byte-wise. */
    private const BACKUP_BODY = "HTTP-bytes \x1f\x8b\x08 then more";

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('papyrus.suwayomi.url', 'http://127.0.0.1:4567');
    }

    public function test_it_answers_the_url_the_backup_mutation_returns(): void
    {
        Http::fake($this->fake());

        $this->assertSame(self::BACKUP_PATH, $this->gateway()->createBackup());
    }

    public function test_the_flags_ride_the_mutation(): void
    {
        Http::fake($this->fake());

        $this->gateway()->createBackup(['includeManga' => true, 'includeChapters' => false]);

        Http::assertSent(function (Request $request): bool {
            $body = (string) $request->body();

            return str_contains($body, '"includeManga":true')
                && str_contains($body, '"includeChapters":false')
                && str_contains($body, 'mutation CreateBackup');
        });
    }

    public function test_a_mutation_answer_without_a_url_refuses_loudly(): void
    {
        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($body, 'mutation CreateBackup')) {
                return Http::response(['data' => ['createBackup' => ['url' => '']]]);
            }

            return Http::response(['data' => []]);
        });

        try {
            $this->gateway()->createBackup();
            $this->fail('The gateway answered a mutation that carried no url.');
        } catch (SuwayomiException $e) {
            $this->assertStringContainsString('without a url', $e->getMessage());
            $this->assertSame(FailureKind::Unknown, $e->kind);
        }
    }

    public function test_the_download_streams_the_file_to_the_sink(): void
    {
        Http::fake($this->fake());

        $sink = $this->scratchPath('backup-sink').DIRECTORY_SEPARATOR.'suwayomi.tachibk';

        $this->gateway()->download(self::BACKUP_PATH, $sink);

        $this->assertFileExists($sink);
        $this->assertSame(self::BACKUP_BODY, (string) file_get_contents($sink));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://127.0.0.1:4567'.self::BACKUP_PATH;
        });
    }

    public function test_a_401_download_refuses_as_unauthenticated(): void
    {
        // The 401 is scoped to the file GET. A blanket 401 would refuse the login the
        // Gateway makes first, and the test would then pass on AuthManager's throw rather
        // than on this download branch.
        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($request->url(), '/files/backup/')) {
                return Http::response('denied', 401);
            }

            return Http::response(['data' => []]);
        });

        try {
            $this->gateway()->download(self::BACKUP_PATH, $this->scratchPath('refused').DIRECTORY_SEPARATOR.'x');
            $this->fail('The gateway streamed a 401 body into the sink.');
        } catch (SuwayomiException $e) {
            $this->assertSame(FailureKind::Unauthenticated, $e->kind);
        }
    }

    public function test_a_failed_download_names_the_status(): void
    {
        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($request->url(), '/files/backup/')) {
                return Http::response([], 503);
            }

            return Http::response(['data' => []]);
        });

        try {
            $this->gateway()->download(self::BACKUP_PATH, $this->scratchPath('failed').DIRECTORY_SEPARATOR.'x');
            $this->fail('The gateway streamed a 503 body into the sink.');
        } catch (SuwayomiException $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
            $this->assertSame(FailureKind::Unavailable, $e->kind);
        }
    }

    public function test_an_unreachable_server_refuses_as_unavailable(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException('connection refused'));

        try {
            $this->gateway()->download(self::BACKUP_PATH, $this->scratchPath('unreachable').DIRECTORY_SEPARATOR.'x');
            $this->fail('The gateway treated a refused connection as a download.');
        } catch (SuwayomiException $e) {
            $this->assertStringContainsString('Could not reach Suwayomi', $e->getMessage());
            $this->assertSame(FailureKind::Unavailable, $e->kind);
        }
    }

    public function test_a_stream_that_paints_nothing_without_ending_refuses_as_stalled(): void
    {
        // eof() stays false and every read comes back empty: the shape a real Guzzle
        // stream never produces by itself, and the one the drain exists to refuse.
        $stream = $this->stream(fn (int $length): string => '');

        try {
            $this->drain($stream, $this->scratchPath('stalled').DIRECTORY_SEPARATOR.'x');
            $this->fail('The drain accepted a stream that paints nothing and is not at its end.');
        } catch (SuwayomiException $e) {
            $this->assertStringContainsString('stalled: the stream painted nothing', $e->getMessage());
            $this->assertSame(FailureKind::Unavailable, $e->kind);
        }
    }

    public function test_a_stream_that_breaks_mid_transfer_names_the_transport(): void
    {
        $stream = $this->stream(fn (int $length): string => throw new RuntimeException('socket went away'));

        try {
            $this->drain($stream, $this->scratchPath('broke').DIRECTORY_SEPARATOR.'x');
            $this->fail('The drain treated a broken stream as a finished transfer.');
        } catch (SuwayomiException $e) {
            $this->assertStringContainsString('broke partway', $e->getMessage());
            $this->assertStringContainsString('socket went away', $e->getMessage());
            $this->assertSame(FailureKind::Unavailable, $e->kind);
        }
    }

    /**
     * One fake that answers the two measured shapes. A second Http::fake() in a test does not
     * replace the first registration: the setUp fake keeps answering, which is trap 116.
     */
    private function fake(): \Closure
    {
        return function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($body, 'mutation CreateBackup')) {
                return Http::response(['data' => ['createBackup' => ['url' => self::BACKUP_PATH]]]);
            }

            if (str_contains($request->url(), '/files/backup/')) {
                return Http::response(self::BACKUP_BODY, 200, ['Content-Type' => 'application/octet-stream']);
            }

            return Http::response(['data' => []]);
        };
    }

    /**
     * A hand made body. A faked response's body is a real Psr7\Stream, whose empty read
     * always answers eof() true, so the drain's stalled shape cannot be staged through
     * Http::fake(): the stream has to be one that can lie.
     */
    private function stream(\Closure $read): StreamInterface
    {
        return new class($read) implements StreamInterface
        {
            /** @var \Closure(int): string */
            private \Closure $read;

            public function __construct(\Closure $read)
            {
                $this->read = $read;
            }

            public function __toString(): string
            {
                return '';
            }

            public function close(): void {}

            public function detach(): ?int
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function eof(): bool
            {
                return false;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void {}

            public function rewind(): void {}

            public function write(string $string): int
            {
                throw new RuntimeException('the drain never writes to the response body');
            }

            public function read(int $length): string
            {
                return ($this->read)($length);
            }

            public function getContents(): string
            {
                return '';
            }

            public function getMetadata(?string $key = null)
            {
                return null;
            }
        };
    }

    /** The drain is private on purpose; the reflection template is the one HealthTest uses. */
    private function drain(StreamInterface $body, string $sink): void
    {
        $method = new \ReflectionMethod(BackupGateway::class, 'drain');
        $method->setAccessible(true);

        $method->invoke(app(BackupGateway::class), $body, $sink);
    }

    private function gateway(): BackupGateway
    {
        return app(BackupGateway::class);
    }
}
