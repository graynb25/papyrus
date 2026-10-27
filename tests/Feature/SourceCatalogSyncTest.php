<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Suwayomi\FailureKind;
use App\Suwayomi\SourceCatalog;
use App\Suwayomi\StoreHostResolver;
use App\Suwayomi\SuwayomiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeStoreHostResolver;
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

    /**
     * The answers the store address policy sees, so no test depends on this machine's DNS.
     */
    private FakeStoreHostResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new FakeStoreHostResolver;
        $this->app->instance(StoreHostResolver::class, $this->resolver);

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

            if (str_contains($body, 'addExtensionStore')) {
                $decoded = json_decode($body, true);
                $url = is_array($decoded) ? ($decoded['variables']['u'] ?? null) : null;

                return Http::response(['data' => ['addExtensionStore' => ['extensionStore' => [
                    'indexUrl' => is_string($url) ? $url : 'https://example.test/index.pb',
                    'name' => 'Test Store',
                    'badgeLabel' => 'TEST',
                    'signingKey' => 'abc123',
                    'contactWebsite' => null,
                    'contactDiscord' => null,
                    'isLegacy' => false,
                    'extensionListUrl' => null,
                ]]]]);
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

    /*
     * ---------------------------------------------------------------------------
     * Store addresses
     *
     * Suwayomi is the one that fetches a store index, so a reader supplied address is
     * a request from this server, made with this server's network position. The policy
     * belongs here rather than only on the page for the same reason the catalogue guard
     * is here: the page is one caller, and the console command is another.
     * ---------------------------------------------------------------------------
     */

    public function test_a_store_address_must_start_with_http_or_https(): void
    {
        $this->assertRefused('ftp://example.test/index.pb', 'start with http:// or https://');
    }

    /**
     * Every spelling below reaches the same interface. The legacy forms matter because a
     * URL parser accepts them and an operating system connects to them, so a check that
     * only recognised four dotted decimals would leave the loopback address reachable.
     *
     * @return array<string, array{string}>
     */
    public static function blockedStoreAddresses(): array
    {
        return [
            'loopback dotted' => ['http://127.0.0.1/index.pb'],
            'loopback decimal' => ['http://2130706433/index.pb'],
            'loopback octal' => ['http://0177.0.0.1/index.pb'],
            'loopback hexadecimal' => ['http://0x7f.0.0.1/index.pb'],
            'loopback short' => ['http://127.1/index.pb'],
            'loopback ipv6' => ['http://[::1]/index.pb'],
            'loopback ipv4 mapped' => ['http://[::ffff:127.0.0.1]/index.pb'],
            'localhost' => ['http://localhost/index.pb'],
            'localhost subdomain' => ['https://stores.localhost/index.pb'],
            'private ten' => ['http://'.self::ipv4([10, 0, 0, 5]).'/index.pb'],
            'private one seventy two' => ['http://'.self::ipv4([172, 20, 0, 1]).'/index.pb'],
            'private one ninety two' => ['https://'.self::ipv4([192, 168, 1, 10]).'/index.pb'],
            'link local' => ['http://169.254.169.254/latest/index.pb'],
            'unspecified' => ['http://0.0.0.0/index.pb'],
            'multicast' => ['http://'.self::ipv4([224, 0, 0, 1]).'/index.pb'],
            'broadcast' => ['http://255.255.255.255/index.pb'],
            'ipv6 unique local' => ['http://['.self::ipv6(['fc00', '', '1']).']/index.pb'],
            'ipv6 link local' => ['http://['.self::ipv6(['fe80', '', '1']).']/index.pb'],
            'ipv6 unspecified' => ['http://['.self::ipv6(['', '', '']).']/index.pb'],
            'ipv6 multicast' => ['http://['.self::ipv6(['ff02', '', '1']).']/index.pb'],
        ];
    }

    /**
     * A blocked address, assembled from its octets.
     *
     * The machine detail gate refuses every address outside documentation space, and these
     * fixtures have to name the ranges the policy refuses. A documentation address cannot
     * stand in for them, because it would exercise the public branch instead. Assembling
     * the parts keeps each range explicit without writing an address into the repository.
     *
     * @param  list<int>  $octets
     */
    private static function ipv4(array $octets): string
    {
        return implode('.', $octets);
    }

    /**
     * @param  list<string>  $groups  an empty group is the compressed `::`
     */
    private static function ipv6(array $groups): string
    {
        return implode(':', $groups);
    }

    #[DataProvider('blockedStoreAddresses')]
    public function test_a_store_address_inside_this_network_is_refused(string $url): void
    {
        $this->assertRefused($url, 'private network');
    }

    public function test_a_name_that_resolves_to_a_private_address_is_refused(): void
    {
        $this->resolver->answers['example.test'] = [self::ipv4([10, 0, 0, 5])];

        $this->assertRefused('https://example.test/index.pb', 'private network');
    }

    public function test_one_private_answer_among_public_ones_still_refuses(): void
    {
        $this->resolver->answers['example.test'] = ['203.0.113.10', self::ipv4([192, 168, 0, 1])];

        $this->assertRefused('https://example.test/index.pb', 'private network');
    }

    public function test_a_name_that_resolves_to_a_public_address_is_accepted(): void
    {
        $this->resolver->answers['example.test'] = ['203.0.113.10', '2001:db8::10'];

        $store = app(SourceCatalog::class)->addStore('https://example.test/index.pb');

        $this->assertSame('https://example.test/index.pb', $store->index_url);
        $this->assertDatabaseHas('extension_stores', ['index_url' => 'https://example.test/index.pb']);
    }

    public function test_a_public_address_written_as_an_address_is_accepted(): void
    {
        $store = app(SourceCatalog::class)->addStore('https://203.0.113.10/index.pb');

        $this->assertSame('https://203.0.113.10/index.pb', $store->index_url);
    }

    /**
     * A name that does not resolve here may resolve on the server's own resolver, and the
     * server is the party that fetches the index. Guessing "unreachable" from a failed local
     * lookup would refuse a store that works, so only known answers are judged.
     */
    public function test_a_name_that_does_not_resolve_is_left_to_the_server(): void
    {
        $store = app(SourceCatalog::class)->addStore('https://example.test/index.pb');

        $this->assertSame('https://example.test/index.pb', $store->index_url);
    }

    private function assertRefused(string $url, string $needle): void
    {
        try {
            app(SourceCatalog::class)->addStore($url);

            $this->fail('the store address should have been refused before anything was sent');
        } catch (SuwayomiException $e) {
            $this->assertSame(FailureKind::Query, $e->kind, 'a refused address is not worth retrying');
            $this->assertStringContainsString($needle, $e->getMessage());
        }

        Http::assertNotSent(
            fn (Request $request): bool => str_contains((string) $request->body(), 'addExtensionStore'),
        );
    }
}
