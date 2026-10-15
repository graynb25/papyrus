<?php

namespace Tests\Feature;

use App\Downloads\CbzExporter;
use App\Downloads\CbzExportRefused;
use App\Models\User;
use App\Suwayomi\SuwayomiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Support\MintsTokens;
use Tests\TestCase;
use ZipArchive;

/**
 * The CBZ export, driven through its route.
 *
 * ## Why the fake sends no Content-Length
 *
 * The real server does not, and a fake that adds one would test a design that cannot run.
 * Measured against v2.4.2366, Jetty answers a page with `Server`, `Date`, `Content-Type`,
 * `Cache-Control` and `Connection` and nothing else: `HEAD` reports no length, a ranged GET
 * reports no `Content-Range`, and a plain GET is chunked. So the fake here sends the same five
 * headers, which is what forced the design away from a pre-flight and onto building the
 * archive before sending it. A fake that reported a length would have let that regression back
 * in unnoticed, which is trap 131 in the shape of a helpful test double.
 *
 * ## Why the pages are real bytes
 *
 * The reader's page names come from the media type, and the archive is verified by reading it
 * back with `ZipArchive`. Both only mean something if the entries carry something a reader
 * could open, so the fake serves a real RIFF/WEBP header rather than a string of 'a'.
 */
class CbzExportTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private const ASURA = '6247824327199706550';

    private const CHAPTER = 1;

    /**
     * A minimal but genuine WebP: "RIFF" + size + "WEBP".
     *
     * Enough for a reader to identify the format, which is the property the page names depend
     * on. Sixteen bytes per page keeps the fixture small while still crossing a chunk boundary.
     */
    private const PAGE_BYTES = 16;

    /**
     * The page URLs the fake reports, settable per test.
     *
     * A property rather than a second `Http::fake()` in the test that needs it, because
     * `Http::fake()` called twice does not replace the first registration: the setUp fake keeps
     * answering and the test exercises a scenario nobody set up. That is trap 116, which this
     * file's own comment on the fake describes and which the first version of the no-pages test
     * walked straight into.
     *
     * @var array<int, string>
     */
    private array $pages = [
        '/api/v1/manga/76/chapter/1/page/0',
        '/api/v1/manga/76/chapter/1/page/1',
        '/api/v1/manga/76/chapter/1/page/2',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        Config::set('papyrus.downloads.cbz_export_max_bytes', 4 * 1024 * 1024);

        $this->fakeSuwayomi();
    }

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($body, 'fetchChapterPages')) {
                return Http::response(['data' => ['fetchChapterPages' => [
                    'chapter' => ['id' => (string) self::CHAPTER, 'name' => 'Chapter 1', 'pageCount' => count($this->pages)],
                    'pages' => $this->pages,
                ]]]);
            }

            // A page image. Five headers and no length, which is what Jetty actually sends.
            if (str_contains($request->url(), '/page/')) {
                return Http::response(
                    str_repeat('RIFF', self::PAGE_BYTES / 4),
                    200,
                    [
                        'Content-Type' => 'image/webp',
                        'Cache-Control' => 'max-age=86400',
                        'Server' => 'Jetty(12.1.12)',
                    ]
                );
            }

            return Http::response(['data' => []]);
        });
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(string $url): array
    {
        return ['name' => rawurlencode('A Series - Chapter 1')] + $url;
    }

    public function test_it_returns_a_cbz(): void
    {
        $response = $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.comicbook+zip');
    }

    public function test_the_archive_holds_one_entry_per_page(): void
    {
        /*
         * `streamedContent()`, not `getContent()`.
         *
         * A StreamedResponse has no body until the callback runs, and `getContent()` returns
         * an empty string for one. The first version of this read the body that way and
         * concluded the archive had no entries in it: `numFiles` was 0 and Content-Length
         * was 0, and the export it had just proved returned 200 was in fact empty. Four
         * failures, one cause, and the app was working the whole time.
         */
        $body = (string) $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz')
            ->assertOk()
            ->streamedContent();

        $zip = new ZipArchive;
        $file = $this->temporaryFile('cbz');
        file_put_contents($file, $body);

        try {
            $this->assertTrue($zip->open($file) === true, 'The response body is not a readable archive.');
            $this->assertSame(3, $zip->numFiles);
            $this->assertSame('001.webp', $zip->getNameIndex(0));
            $this->assertSame('003.webp', $zip->getNameIndex(2));

            // The bytes are the page's, so a reader could actually open this.
            $this->assertSame(
                str_repeat('RIFF', self::PAGE_BYTES / 4),
                $zip->getFromIndex(0)
            );
        } finally {
            $zip->close();
            @unlink($file);
        }
    }

    public function test_the_content_length_is_the_archive_and_not_an_estimate(): void
    {
        $response = $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz')->assertOk();

        $length = (int) $response->headers->get('Content-Length');
        $actual = strlen((string) $response->streamedContent());

        $this->assertSame(
            $actual,
            $length,
            'The browser is shown this number as a progress bar, so it has to be the archive.'
        );
    }

    public function test_the_filename_is_the_one_the_caller_asked_for(): void
    {
        $response = $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz?'.http_build_query([
            'name' => 'A Series - Chapter 1',
        ]));

        $response->assertOk();
        $this->assertStringContainsString(
            'filename="A Series - Chapter 1.cbz"',
            (string) $response->headers->get('Content-Disposition')
        );
    }

    public function test_a_filename_carrying_a_quote_is_stripped_rather_than_injected(): void
    {
        $response = $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz?'.http_build_query([
            'name' => 'evil".html',
        ]));

        $response->assertOk();

        $disposition = (string) $response->headers->get('Content-Disposition');

        // This value reaches a header, so a quote inside the filename is header injection
        // rather than a filename. Checked on the value between the quotes, not on the whole
        // header: the header legitimately contains quotes of its own around the value.
        $this->assertMatchesRegularExpression('/filename="([^"]*)"/', $disposition);

        preg_match('/filename="([^"]*)"/', $disposition, $found);

        $this->assertStringNotContainsString('"', $found[1]);
        $this->assertStringNotContainsString("\n", $found[1]);
        $this->assertStringNotContainsString("\r", $found[1]);
        $this->assertStringEndsWith('.cbz', $found[1]);
        $this->assertStringContainsString('evil', $found[1]);
    }

    public function test_a_chapter_over_the_cap_is_refused_rather_than_truncated(): void
    {
        Config::set('papyrus.downloads.cbz_export_max_bytes', 64);

        // A redirect back to where the control was, not a truncated file.
        $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz')
            ->assertRedirect();

        $this->assertSame(
            CbzExportRefused::TOO_LARGE,
            session('cbzRefusalReason')
        );

        $this->assertIsString(session('cbzRefusal'));
        $this->assertStringContainsString('export limit', (string) session('cbzRefusal'));
    }

    public function test_a_refusal_says_how_big_the_chapter_is_and_what_the_limit_is(): void
    {
        Config::set('papyrus.downloads.cbz_export_max_bytes', 64);

        $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz');

        $message = (string) session('cbzRefusal');

        // "Too large" on its own is not actionable. The two numbers are what let a reader
        // decide between raising the limit and accepting that this chapter will not export.
        $this->assertStringContainsString('bytes', $message);
        $this->assertMatchesRegularExpression('/\d/', $message);
    }

    public function test_a_refusal_leaves_no_scratch_file_behind(): void
    {
        Config::set('papyrus.downloads.cbz_export_max_bytes', 64);

        $before = $this->scratchFiles();

        $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz');

        $this->assertSame(
            $before,
            $this->scratchFiles(),
            'A refused export must not leave a partial archive in the temp directory.'
        );
    }

    public function test_a_successful_export_also_leaves_no_scratch_file_behind(): void
    {
        $before = $this->scratchFiles();

        $response = $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz')->assertOk();

        // The body has to actually be read before this means anything. The archive is built and
        // then sent, so asserting immediately after the request would look at a directory in
        // which the file legitimately still exists and call it a leak.
        $this->assertNotSame('', (string) $response->streamedContent());

        $this->assertSame(
            $before,
            $this->scratchFiles(),
            'The scratch file is removed once the body has been sent.'
        );
    }

    public function test_it_is_closed_to_signed_out_visitors(): void
    {
        auth()->logout();

        $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz')->assertRedirect('/login');
    }

    public function test_a_chapter_with_no_pages_is_an_error_rather_than_an_empty_archive(): void
    {
        // An empty zip opens cleanly and contains nothing, which reads to a reader as a
        // successful export of a chapter that turned out to be blank.
        $this->pages = [];

        $this->withoutExceptionHandling();

        $this->expectException(SuwayomiException::class);

        $this->get('/downloads/chapter/'.self::CHAPTER.'/cbz');
    }

    public function test_the_cap_of_zero_falls_back_to_the_documented_default(): void
    {
        // A zero cap would refuse every export, which is a typo rather than an intent.
        Config::set('papyrus.downloads.cbz_export_max_bytes', 0);

        $this->assertSame(268_435_456, app(CbzExporter::class)->maxBytes());
    }

    /**
     * @return array<int, string>
     */
    private function scratchFiles(): array
    {
        $files = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-cbz-*.cbz') ?: [];

        sort($files);

        return $files;
    }
}
