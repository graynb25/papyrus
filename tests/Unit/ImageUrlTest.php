<?php

namespace Tests\Unit;

use App\Support\ImageUrl;
use Tests\TestCase;

/**
 * The proxy is the security boundary for image requests, so its allowlist is
 * asserted here as well as in the controller. A URL that renders must be one the
 * controller will actually fetch, and a path outside the allowlist must not reach
 * a template at all.
 */
class ImageUrlTest extends TestCase
{
    public function test_it_wraps_a_chapter_page(): void
    {
        $url = ImageUrl::page(76, 1, 0);

        $this->assertStringContainsString('/suwayomi/api/v1/manga/76/chapter/1/page/0', $url);
        $this->assertStringNotContainsString('127.0.0.1', $url, 'The upstream host must never reach the browser.');
    }

    public function test_it_wraps_a_cover_thumbnail(): void
    {
        $this->assertStringContainsString('/suwayomi/api/v1/manga/76/thumbnail', ImageUrl::thumbnail(76));
    }

    public function test_a_missing_thumbnail_returns_null_rather_than_a_broken_url(): void
    {
        $this->assertNull(ImageUrl::thumbnail(0));
    }

    public function test_it_appends_a_transcode_format(): void
    {
        $this->assertStringEndsWith('?format=webp', ImageUrl::page(76, 1, 0, 'webp'));
        $this->assertStringEndsWith('?format=avif', ImageUrl::thumbnail(76, 'avif'));
    }

    public function test_it_appends_a_width_for_responsive_images(): void
    {
        $this->assertStringContainsString('width=320', ImageUrl::thumbnail(76, 'webp', 320));
        $this->assertStringContainsString('width=640', ImageUrl::page(76, 1, 0, 'webp', 640));
    }

    public function test_no_format_means_no_query_string(): void
    {
        $this->assertStringNotContainsString('?', ImageUrl::page(76, 1, 0, null));
        $this->assertStringNotContainsString('?', ImageUrl::page(76, 1, 0, ''));
    }

    /**
     * The server sends three different shapes and all of them appear in practice:
     * a root-relative path from search results, an absolute URL from a source, and
     * an app URL from a second pass through our own proxy.
     */
    public function test_it_accepts_the_three_shapes_the_server_sends(): void
    {
        $expected = 'manga/76/thumbnail';

        foreach ([
            '/api/v1/'.$expected,
            'http://127.0.0.1:4567/api/v1/'.$expected,
            'https://suwayomi.internal/api/v1/'.$expected,
            $expected,
        ] as $value) {
            $url = ImageUrl::fromServerValue($value);

            $this->assertNotNull($url, "Rejected: {$value}");
            $this->assertStringContainsString('/suwayomi/api/v1/'.$expected, $url);
        }
    }

    /**
     * Anything outside the allowlist returns null. The controller refuses it too,
     * but returning null here means it never reaches an img tag in the first
     * place, which is a stronger position than refusing the request.
     */
    public function test_it_refuses_paths_outside_the_allowlist(): void
    {
        $refused = [
            // The CBZ export builds the whole zip in memory server side (#2353).
            '/api/v1/download/manga/76',
            // Nothing under api/v1 at all.
            '/api/v1/source/1/icon',
            '/api/v1/extension/install',
            // A traversal attempt dressed up as a page.
            'manga/76/chapter/1/page/0/../../../download',
            // Non-numeric ids.
            'manga/abc/thumbnail',
            // A page with a missing segment.
            'manga/76/chapter/1',
            // An absolute upstream URL to something else entirely.
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1:4567/',
        ];

        foreach ($refused as $value) {
            $this->assertNull(ImageUrl::fromServerValue($value), "Accepted: {$value}");
        }
    }

    public function test_it_refuses_empty_and_absent_values(): void
    {
        $this->assertNull(ImageUrl::fromServerValue(null));
        $this->assertNull(ImageUrl::fromServerValue(''));
        $this->assertNull(ImageUrl::fromServerValue(false));
        $this->assertNull(ImageUrl::fromServerValue(123));
        $this->assertNull(ImageUrl::fromServerValue([]));
    }

    public function test_the_default_format_comes_from_config(): void
    {
        config(['papyrus.reader.default_image_format' => 'webp']);
        $this->assertSame('webp', ImageUrl::defaultFormat());

        config(['papyrus.reader.default_image_format' => '']);
        $this->assertNull(ImageUrl::defaultFormat());
    }

    /**
     * Source ids exceed 2^53 but manga and chapter ids are 32-bit, so this is
     * about the path shape rather than integer width.
     */
    public function test_it_handles_the_largest_32_bit_ids(): void
    {
        $url = ImageUrl::page(2147483647, 2147483647, 2147483647);

        $this->assertStringContainsString('manga/2147483647/chapter/2147483647/page/2147483647', $url);
    }

    /**
     * Page 0 is the first page, so zero must be allowed while a negative index
     * must not be. Clamping instead would hide a bug as a plausible page.
     */
    public function test_page_zero_is_valid_and_negative_pages_are_not(): void
    {
        $this->assertNotNull(ImageUrl::page(76, 1, 0));
        $this->assertNull(ImageUrl::page(76, 1, -1));
        $this->assertNull(ImageUrl::page(76, 0, 0));
        $this->assertNull(ImageUrl::page(0, 1, 0));
    }
}
