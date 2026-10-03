<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Suwayomi\SourceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A truncated catalogue response must not uninstall anything.
 *
 * This is the defect behind a reader's report that a search for a title Asura Scans
 * carries returned nothing from Asura Scans. Suwayomi listed all 82 sources as installed,
 * our mirror said 73, and the fan-out only dispatches to sources the mirror believes are
 * installed, so nine sources were silently excluded from every search.
 *
 * The flip was written to treat any source missing from one response as uninstalled. That
 * is indistinguishable from a reader uninstalling an extension, and section 14.5 of the
 * engineering notes has said so since the mirror was first built. What changed is that
 * completeness is knowable: the server exposes `totalCount`.
 *
 * One fake, driven by two properties, rather than a `Http::fake` per test. The first
 * version re-declared the closure in each test and lost a branch in the process, which
 * cost more time than the duplication saved.
 */
class SourceCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * How many source nodes the server will return.
     *
     * @var array<int, int>
     */
    private array $nodes = [0, 1, 2];

    /**
     * What `totalCount` will claim.
     */
    private ?int $total = 3;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $body = (string) $request->body();

            // The gateway logs in before anything else, and a bare `data: []` there
            // leaves it without a token so every later call throws.
            if (str_contains($body, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => 'token-1',
                    'refreshToken' => 'r',
                ]]]);
            }

            if (str_contains($body, 'query Sources')) {
                return Http::response([
                    'data' => ['sources' => [
                        'totalCount' => $this->total,
                        'nodes' => array_map(fn (int $i): array => [
                            'id' => (string) (900000 + $i),
                            'name' => "Source {$i}",
                            'displayName' => "Source {$i}",
                            'lang' => 'en',
                            'contentWarning' => 'SAFE',
                            'homeUrl' => 'https://example.test',
                            'iconUrl' => null,
                            'supportsLatest' => true,
                            'isConfigurable' => false,
                            'extension' => ['pkgName' => "com.example.s{$i}", 'isInstalled' => true],
                        ], $this->nodes),
                    ]],
                ]);
            }

            if (str_contains($body, 'query Stores')) {
                return Http::response(['data' => ['extensionStores' => ['nodes' => []]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    /**
     * A source already in the mirror, from an earlier good sync.
     */
    private function existing(int $sourceId, bool $installed = true): void
    {
        Source::query()->create([
            'suwayomi_source_id' => $sourceId,
            'name' => "Source {$sourceId}",
            'display_name' => "Source {$sourceId}",
            'extension_pkg_name' => 'com.example.existing',
            'lang' => 'en',
            'is_installed' => $installed,
            'supports_latest' => true,
            'last_synced_at' => '2026-10-01 23:38:11',
        ]);
    }

    private function installedCount(): int
    {
        return Source::query()->where('is_installed', true)->count();
    }

    public function test_a_complete_response_records_every_source(): void
    {
        app(SourceCatalog::class)->syncSources();

        $this->assertSame(3, $this->installedCount());
    }

    public function test_a_truncated_response_uninstalls_nothing(): void
    {
        $this->existing(900003);

        // The server still holds four and answered with three.
        $this->nodes = [0, 1, 2];
        $this->total = 4;

        app(SourceCatalog::class)->syncSources();

        $this->assertSame(
            4,
            $this->installedCount(),
            'A short response is not evidence of an uninstall. This is what marked Asura Scans and eight others uninstalled.'
        );

        $this->assertTrue(
            Source::query()->where('suwayomi_source_id', 900003)->first()->is_installed,
            'The absent source stays installed.'
        );
    }

    public function test_a_complete_response_still_records_a_genuine_uninstall(): void
    {
        $this->existing(900009);

        // Three remain and the server says three, so the fourth really did leave.
        $this->nodes = [0, 1, 2];
        $this->total = 3;

        app(SourceCatalog::class)->syncSources();

        $this->assertFalse(
            Source::query()->where('suwayomi_source_id', 900009)->first()->is_installed,
            'A reader uninstalling an extension still flips the row, because a complete response is short by exactly what left.'
        );

        $this->assertSame(3, $this->installedCount());
    }

    public function test_a_response_with_no_total_count_uninstalls_nothing(): void
    {
        $this->existing(900009);

        // No total means no way to know the response was complete, so presence is all
        // that can be trusted.
        $this->nodes = [0, 1, 2];
        $this->total = null;

        app(SourceCatalog::class)->syncSources();

        $this->assertSame(
            4,
            $this->installedCount(),
            'Without a total there is nothing to measure absence against.'
        );
    }

    public function test_a_source_present_in_a_truncated_response_is_still_refreshed(): void
    {
        $this->existing(900000, installed: false);

        $this->nodes = [0, 1, 2];
        $this->total = 4;

        app(SourceCatalog::class)->syncSources();

        $this->assertTrue(
            Source::query()->where('suwayomi_source_id', 900000)->first()->is_installed,
            'Presence is still trusted when the response is short. The guard only governs what absence may conclude.'
        );
    }
}
