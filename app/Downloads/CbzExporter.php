<?php

namespace App\Downloads;

use App\Suwayomi\AuthManager;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Builds a CBZ from a chapter's pages and streams it to the browser.
 *
 * ## Why this exists rather than a proxy to Suwayomi's own
 *
 * Suwayomi serves `/api/v1/chapter/{id}/download`, and the proxy refuses it on purpose:
 * measured, it builds the whole archive in memory server-side before it answers
 * (Suwayomi-Server#2353). A 400-page webtoon chapter is then a memory spike of whatever the
 * uncompressed images weigh, which is the same class of problem as the proxy's own reason for
 * refusing it. Refusing the route and then reimplementing the behaviour in this app would be
 * an odd answer, so the archive is assembled here instead and never exists whole in memory.
 *
 * ## Why the guard is not a pre-flight
 *
 * The first design checked the cap *before* sending anything, by asking each page for its
 * `Content-Length` and refusing if the sum was over the limit. That is the right shape and it
 * does not work here, because Suwayomi never sends a length for a page image. Measured on
 * v2.4.2366 against a live page:
 *
 * ```
 * HEAD          200, Content-Type text/plain, no length, no Accept-Ranges
 * Range: 0-0    200, no Content-Range
 * GET           Server, Date, Content-Type, Cache-Control, Connection - and nothing else
 * ```
 *
 * Jetty is answering with chunked transfer encoding. So every page reads as "unknown", the
 * guard could only ever refuse, and the pre-flight that established this cost **20.5s** against
 * 1.9s for the whole export. A guard whose premise is false is not a conservative guard, it is
 * a feature that does not work.
 *
 * ## What is done instead
 *
 * The archive is built into a temporary file and the cap is checked against the running total
 * as it goes. If the total passes the limit the file is deleted and the reader is told, with
 * nothing having been sent. If it does not, the file is rewound and streamed, and it now
 * carries a real `Content-Length` rather than an estimate, so the browser can show a progress
 * bar instead of an indeterminate spinner.
 *
 * The properties that matter are unchanged: memory stays flat at one chunk per page because
 * {@see CbzWriter} streams to the file handle rather than accumulating, and the reader never
 * receives a truncated archive, because a refusal happens before the first byte of the
 * response body. Measured on chapter 1 of manga 76: 22 pages, a 19.3 MB archive, 1.9s to
 * build, peak memory 32 MB.
 *
 * ## The cost of that choice
 *
 * The download starts after the build rather than during it, so a 256 MB export is a wait
 * rather than a progress bar. That is the trade the guard forces: an export that cannot know
 * its own size cannot promise not to exceed a limit until it has been made, and refusing after
 * the fact means handing over a corrupt file. The wait is bounded by the cap, which is the
 * point of having one.
 *
 * ## What the page names are
 *
 * The extension comes from the `Content-Type` Suwayomi actually sent, not from what was asked
 * for. Measured: `serveConversions` is off, and `webp`, `avif` and `jpg` all return
 * byte-identical `image/webp`, so naming every page `.jpg` would write webp bytes under a
 * jpeg extension. Readers key off the extension, so the file has to say what it is.
 *
 * The number is zero-padded to three digits, which is not decoration: a reader sorts the
 * entries by name, and without padding `10.webp` sorts between `1.webp` and `2.webp`, so the
 * archive opens on the wrong page.
 */
class CbzExporter
{
    /**
     * How much is read at a time.
     *
     * 64 KiB, matching the proxy, so what this holds is fixed whatever the page weighs: a
     * 4 MiB page costs the same 64 KiB buffer as a 40 KiB one.
     */
    private const CHUNK_BYTES = 64 * 1024;

    /**
     * Extension by media type, for the page names.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly SeriesGateway $series,
        private readonly AuthManager $auth,
    ) {}

    /**
     * The cap, in bytes.
     *
     * Read from config on every call rather than captured in the constructor, so a deployed
     * instance picks up a config change on the next request and a test does not need a fresh
     * container to try a different limit.
     */
    public function maxBytes(): int
    {
        $configured = (int) config('papyrus.downloads.cbz_export_max_bytes', 268_435_456);

        // A zero or negative cap would refuse everything, which is a configuration mistake
        // rather than an intent. The documented default keeps a typo from making the feature
        // look broken.
        return $configured > 0 ? $configured : 268_435_456;
    }

    /**
     * The response: the archive, built, checked, then sent.
     *
     * @param  string  $filename  the name the browser will save it under
     *
     * @throws CbzExportRefused when the guard says no, which the caller turns into a page
     *                          rather than a truncated download
     */
    public function stream(int $chapterId, string $filename): StreamedResponse
    {
        $archive = $this->build($chapterId);

        return response()->stream(function () use ($archive): void {
            $handle = fopen($archive['path'], 'rb');

            if ($handle === false) {
                @unlink($archive['path']);

                throw new RuntimeException('The archive vanished between being built and being sent.');
            }

            try {
                while (! feof($handle)) {
                    $chunk = fread($handle, self::CHUNK_BYTES);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    flush();
                }
            } finally {
                fclose($handle);

                /*
                 * Removed here rather than in the response's `setCallback`, which is the
                 * obvious place and the wrong one.
                 *
                 * The callback runs when the response has been sent, which is exactly the
                 * moment a reader who cancels stops listening: an aborted download is the case
                 * most worth cleaning up after and the one a send-time callback is least likely
                 * to reach. A `finally` around the send runs on that path too, and it is the
                 * only cleanup that can be tested at all. `setCallback` never fires under a
                 * test, so a test asserting "nothing was left behind" would have passed against
                 * a version that leaks on every single export.
                 */
                @unlink($archive['path']);
            }
        }, 200, $this->headers($archive, $filename));
    }

    /**
     * Build the archive on disk, refusing if it passes the cap.
     *
     * @return array{path: string, bytes: int, entries: int}
     *
     * @throws CbzExportRefused when the running total passes {@see maxBytes()}
     */
    public function build(int $chapterId): array
    {
        $urls = $this->pageUrls($chapterId);
        $max = $this->maxBytes();

        $path = $this->scratchPath($chapterId);
        $handle = fopen($path, 'w+b');

        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary file to build the archive in.');
        }

        try {
            $writer = new CbzWriter($handle);

            foreach ($urls as $index => $url) {
                /*
                 * Checked per page rather than once at the end, so a refusal happens while the
                 * cost is still small. The archive's overhead is about 90 bytes a page, so the
                 * running total is within a fraction of a percent of the final size and the cap
                 * is never enforced against a number that is wildly wrong.
                 */
                if ($writer->bytesWritten() > $max) {
                    throw CbzExportRefused::tooLarge($chapterId, $writer->bytesWritten(), $max);
                }

                $writer->add(
                    CbzWriter::pageName($index + 1, $this->extensionFor($url)),
                    fn (): iterable => $this->pageChunks($url)
                );
            }

            $writer->close();

            $bytes = (int) filesize($path);

            // Once more, now that the central directory is in: it is what makes the file a zip
            // and it is written after the last page, so the per-page check never saw it.
            if ($bytes > $max) {
                throw CbzExportRefused::tooLarge($chapterId, $bytes, $max);
            }

            return ['path' => $path, 'bytes' => $bytes, 'entries' => $writer->entryCount()];
        } catch (Throwable $e) {
            /*
             * The partial file goes now rather than in the caller's cleanup, because the
             * failure paths are not the same: a refusal must not leave a 200 MB scratch file
             * behind, and the caller's cleanup does not run for anything thrown from here.
             */
            @unlink($path);

            throw $e;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array{path: string, bytes: int, entries: int}  $archive
     * @return array<string, string>
     */
    private function headers(array $archive, string $filename): array
    {
        return [
            'Content-Type' => 'application/vnd.comicbook+zip',
            'Content-Disposition' => 'attachment; filename="'.$this->safeFilename($filename).'"',
            // Real, because the archive exists and has been measured. The first design sent an
            // estimate under a different header name, which was worse twice over: the number
            // was wrong, and the browser had to be told not to trust it.
            'Content-Length' => (string) $archive['bytes'],
            // The page count, for a caller that would rather know than open the file.
            'X-Archive-Entries' => (string) $archive['entries'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ];
    }

    /**
     * The page's bytes, as they arrive.
     *
     * A generator rather than a buffer, which is the property the whole class is built around:
     * this function's return value is never materialised, so a 4 MiB page costs 64 KiB.
     *
     * @return iterable<string>
     */
    private function pageChunks(string $pageUrl): iterable
    {
        $response = $this->open($pageUrl);

        if (! $response->successful()) {
            throw new SuwayomiException('A page stopped being available part way through the export.');
        }

        $body = $response->toPsrResponse()->getBody();

        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);

                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            // Closed either way. A generator abandoned part way through, which is what a size
            // refusal or a client disconnect looks like, must not leave the upstream connection
            // hanging.
            $body->close();
        }
    }

    /**
     * The extension for a page, from what the server says it is.
     */
    private function extensionFor(string $pageUrl): string
    {
        $response = $this->open($pageUrl);

        // Read and discarded, because the question is about the page and the answer is in its
        // headers. The body is closed rather than left to the collector, so a forty-page
        // chapter does not hold forty idle connections open while the next is asked about.
        $response->toPsrResponse()->getBody()->close();

        $media = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return self::EXTENSIONS[$media] ?? 'bin';
    }

    /**
     * @return array<int, string>
     */
    private function pageUrls(int $chapterId): array
    {
        $pages = $this->series->pages($chapterId)['pages'];

        if ($pages === []) {
            throw new SuwayomiException("Chapter {$chapterId} has no pages to export.");
        }

        return array_values(array_filter(array_map('strval', $pages)));
    }

    /**
     * A streaming GET, which is what keeps a body out of memory.
     *
     * @return Response
     */
    private function open(string $pageUrl)
    {
        try {
            return Http::withToken($this->auth->token())
                ->withOptions(['stream' => true])
                ->timeout((int) config('papyrus.suwayomi.timeout', 28))
                ->send('GET', $this->absolute($pageUrl));
        } catch (ConnectionException $e) {
            throw SuwayomiException::unavailable($e->getMessage(), $e);
        }
    }

    /**
     * A page URL as Suwayomi returns it, which is a rooted path rather than an address.
     *
     * Exactly one slash has to survive the join. The first version used `':'` here, on the
     * assumption that the returned path omitted a separator the way this app's own proxy
     * builds one; it does not, and the result was
     * `http://127.0.0.1:4567:/api/v1/...`. Correcting that to a bare concatenation then gave
     * `http://127.0.0.1:4567api/v1/...`, because trimming the trailing slash off the host and
     * the leading slash off the path removes the separator along with them. Both attempts were
     * found by running the exporter; neither was visible by reading it.
     */
    private function absolute(string $pageUrl): string
    {
        if (str_starts_with($pageUrl, 'http://') || str_starts_with($pageUrl, 'https://')) {
            return $pageUrl;
        }

        return rtrim((string) config('papyrus.suwayomi.url'), '/').'/'.ltrim($pageUrl, '/');
    }

    /**
     * Where the archive is built.
     *
     * Under the temp directory rather than beside the covers and uploads, because this is a
     * scratch file that is not meant to survive. In `storage/app` it would sit among files
     * that something else owns, and nothing would ever clean it up.
     */
    private function scratchPath(int $chapterId): string
    {
        return rtrim(sys_get_temp_dir(), '/\\')
            .DIRECTORY_SEPARATOR
            .'papyrus-cbz-'.$chapterId.'-'.bin2hex(random_bytes(6)).'.cbz';
    }

    /**
     * A filename the browser will accept and the header parser will not be harmed by.
     *
     * Quotes and newlines are removed rather than escaped: this goes into a header, and a
     * filename carrying a quote is header injection rather than a download.
     */
    /**
     * A filename the browser will accept and the header parser will not be harmed by.
     *
     * Two characters are allowed that a plain `[^\w\-. ]` would strip, and both are worth it.
     * The apostrophe, because it is not the delimiter: the value is wrapped in double quotes,
     * so a single quote inside it is harmless, and stripping it turns
     * "The Berserker's Second Playthrough" into "The Berserker s Second Playthrough", which is
     * a worse file in the reader's downloads folder. And the unicode word characters the `/u`
     * modifier already reaches, so a title that is not ASCII keeps its own letters.
     *
     * What is stripped is anything that could end the quoted string or start a header: double
     * quotes, CR and LF above all. A filename carrying one of those is header injection rather
     * than a download, and no reader has a legitimate series title containing one.
     */
    private function safeFilename(string $filename): string
    {
        $clean = trim((string) preg_replace('/["\r\n]+/u', '', preg_replace('/[^\w\-\'. ]+/u', '', $filename) ?? ''));

        return $clean === '' ? 'chapter.cbz' : $clean;
    }
}
