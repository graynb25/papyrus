<?php

namespace Tests\Feature\Sources;

use App\Live\Sources\SourcesPageData;
use App\Models\ExtensionStore;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\SourceHealth;
use App\Models\User;
use App\Models\UserSource;
use App\Suwayomi\ExtensionManager;
use App\Suwayomi\SuwayomiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The sources page's reads, exercised without mounting the page.
 *
 * The fake server honours the installed, content, language and name filters so
 * the catalogue's rows, total and hidden count are measured against the same
 * request shape the page sends. The library, local folder, mirror, browse
 * grouping, health ledger, pins and language pills are read through their real
 * services with only the upstream HTTP boundary faked.
 */
class SourcesPageDataTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /**
     * The extension nodes the fake will serve.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $nodes = [];

    /**
     * The manga nodes the fake answers a batched library read with, keyed by id.
     *
     * @var array<int, array{source_id: string, source_name: string, title: string}>
     */
    private array $libraryNodes = [];

    /** When true, the extension reads fail as a dead upstream would. */
    private bool $extensionsShouldFail = false;

    /** When true, the library read fails as a dead upstream would. */
    private bool $libraryShouldFail = false;

    /** The last page size the extension list was asked for. */
    private int $lastFirst = 0;

    /** The last offset the extension list was asked for. */
    private int $lastOffset = 0;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->root = $this->scratchPath();
        config(['papyrus.local_source.path' => $this->root]);

        $this->fakeSuwayomi();
    }

    private function data(): SourcesPageData
    {
        return app(SourcesPageData::class);
    }

    // Stores and trust ----------------------------------------------------

    public function test_stores_are_read_by_name_and_trust_is_per_reader(): void
    {
        $alpha = ExtensionStore::create([
            'index_url' => 'https://alpha.test/index.pb',
            'name' => 'Alpha',
            'signing_key' => 'alpha-key',
        ]);

        ExtensionStore::create([
            'index_url' => 'https://beta.test/index.pb',
            'name' => 'Beta',
            'signing_key' => 'beta-key',
        ]);

        $this->assertSame(['Alpha', 'Beta'], $this->data()->stores()->pluck('name')->all());

        app(ExtensionManager::class)->trust($this->user, $alpha);

        $this->assertSame([$alpha->id], $this->data()->trustedIds());

        $other = User::factory()->create();
        $this->actingAs($other);

        $this->assertSame([], $this->data()->trustedIds(), 'one reader trusting a store must not trust it for the next');
    }

    // Catalogue -----------------------------------------------------------

    public function test_the_catalogue_reports_its_rows_total_and_hidden_count(): void
    {
        $this->nodes = [
            $this->extension('pkg.safe', 'Safe', installed: true, rating: Source::SAFE),
            $this->extension('pkg.adult', 'Adult', installed: true, rating: Source::NSFW),
            $this->extension('pkg.free', 'Free', installed: false, rating: Source::SAFE),
        ];

        $data = $this->data();

        $gateOn = $data->catalogue(['installed' => true, 'content' => []], 'installed', 1, true);

        $this->assertSame(2, $data->total($gateOn));
        $this->assertSame(['pkg.safe', 'pkg.adult'], $data->extensions($gateOn)->pluck('pkgName')->all());
        $this->assertSame(0, $gateOn['hidden']);
        $this->assertNull($gateOn['error']);

        $gateOff = $data->catalogue(['installed' => true, 'content' => [Source::SAFE, Source::MIXED]], 'installed', 1, false);

        $this->assertSame(1, $data->total($gateOff));
        $this->assertSame(['pkg.safe'], $data->extensions($gateOff)->pluck('pkgName')->all());
        $this->assertSame(1, $gateOff['hidden'], 'the adult extension is named as hidden rather than counted as absent');
        $this->assertNull($gateOff['error']);
    }

    public function test_the_catalogue_reads_the_requested_page(): void
    {
        $this->nodes = [$this->extension('pkg.safe', 'Safe')];

        $this->data()->catalogue([], 'all', 2, false);

        $this->assertSame(50, $this->lastFirst);
        $this->assertSame(50, $this->lastOffset);
    }

    public function test_the_catalogue_names_a_failure_and_stops_after_one_try(): void
    {
        $this->nodes = [$this->extension('pkg.safe', 'Safe')];
        $this->extensionsShouldFail = true;

        $catalogue = $this->data()->catalogue([], 'all', 1, false);

        $this->assertSame('Suwayomi returned HTTP 500.', $catalogue['error']);
        $this->assertSame(0, $catalogue['total']);
        $this->assertSame(0, $catalogue['hidden']);
        $this->assertTrue($catalogue['extensions']->isEmpty());
    }

    public function test_the_automatic_load_count_is_one_small_read(): void
    {
        $this->nodes = [
            $this->extension('pkg.one', 'One'),
            $this->extension('pkg.two', 'Two'),
        ];

        $this->assertSame(2, $this->data()->extensionCount());

        $this->extensionsShouldFail = true;

        $this->expectException(SuwayomiException::class);

        $this->data()->extensionCount();
    }

    // Holdings ------------------------------------------------------------

    public function test_holdings_are_counted_per_source_biggest_first(): void
    {
        $this->libraryNodes = [
            1 => ['source_id' => '10', 'source_name' => 'Example', 'title' => 'One'],
            2 => ['source_id' => '10', 'source_name' => 'Example', 'title' => 'Two'],
            3 => ['source_id' => '20', 'source_name' => 'Other', 'title' => 'Three'],
        ];

        foreach ([1, 2, 3] as $mangaId) {
            SeriesState::createFor($this->user->id, [
                'suwayomi_manga_id' => $mangaId,
                'in_library' => true,
                'title' => $this->libraryNodes[$mangaId]['title'],
            ]);
        }

        $holdings = $this->data()->holdings();

        $this->assertNull($holdings['error']);
        $this->assertSame(['10', '20'], $holdings['rows']->pluck('source_id')->all());
        $this->assertSame([2, 1], $holdings['rows']->pluck('count')->all());
        $this->assertSame(['Example', 'Other'], $holdings['rows']->pluck('display_name')->all());
    }

    public function test_holdings_name_a_library_failure_and_stay_readable(): void
    {
        $this->libraryShouldFail = true;

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => 1,
            'in_library' => true,
            'title' => 'One',
        ]);

        $holdings = $this->data()->holdings();

        $this->assertSame('Suwayomi returned HTTP 500.', $holdings['error']);
        $this->assertTrue($holdings['rows']->isEmpty());
    }

    // Local source -------------------------------------------------------

    public function test_the_local_reads_report_the_folder_and_its_summary(): void
    {
        File::makeDirectory($this->root.DIRECTORY_SEPARATOR.'Paper Lantern', 0o777, true);
        File::put($this->root.DIRECTORY_SEPARATOR.'Paper Lantern'.DIRECTORY_SEPARATOR.'volume.cbz', 'x');

        $data = $this->data();

        $this->assertTrue($data->localVisible());

        $rows = $data->localRows();
        $this->assertCount(1, $rows);
        $this->assertSame('Paper Lantern', $rows[0]['folder']);
        $this->assertTrue($rows[0]['has_archive']);

        $this->assertSame(
            ['series' => 1, 'on_disk' => 1, 'missing' => 0, 'unscanned' => 1, 'folders' => 1],
            $data->localSummary(),
        );
    }

    public function test_a_missing_local_folder_is_not_visible_and_reads_nothing(): void
    {
        config(['papyrus.local_source.path' => $this->root.DIRECTORY_SEPARATOR.'missing']);

        $data = $this->data();

        $this->assertFalse($data->localVisible());
        $this->assertSame([], $data->localRows());
        $this->assertSame(
            ['series' => 0, 'on_disk' => 0, 'missing' => 0, 'unscanned' => 0, 'folders' => 0],
            $data->localSummary(),
        );
    }

    // Unmirrored extensions ----------------------------------------------

    public function test_unmirrored_extensions_skip_mirrored_rows_and_the_gated_nsfw(): void
    {
        Source::create([
            'suwayomi_source_id' => 1,
            'name' => 'Mirrored',
            'display_name' => 'Mirrored',
            'extension_pkg_name' => 'pkg.mirrored',
            'lang' => 'en',
            'content_warning' => Source::SAFE,
            'is_installed' => true,
        ]);

        $this->nodes = [
            $this->extension('pkg.mirrored', 'Mirrored', installed: true),
            $this->extension('pkg.missing', 'Missing Safe', installed: true),
            $this->extension('pkg.adult', 'Missing Adult', installed: true, rating: Source::NSFW),
        ];

        $data = $this->data();

        $this->assertSame(
            [
                ['name' => 'Missing Safe', 'pkg' => 'pkg.missing'],
                ['name' => 'Missing Adult', 'pkg' => 'pkg.adult'],
            ],
            $data->unmirroredExtensions(true),
        );

        $this->assertSame(
            [['name' => 'Missing Safe', 'pkg' => 'pkg.missing']],
            $data->unmirroredExtensions(false),
            'the gate already names the hidden adult extension under the catalogue',
        );
    }

    public function test_unmirrored_extensions_is_empty_when_the_server_cannot_be_asked(): void
    {
        $this->nodes = [$this->extension('pkg.missing', 'Missing', installed: true)];
        $this->extensionsShouldFail = true;

        $this->assertSame([], $this->data()->unmirroredExtensions(true));
    }

    // Browse grouping and health -----------------------------------------

    public function test_browse_groups_installed_sources_by_host_with_health_and_order(): void
    {
        $this->source('Example (EN)', 1, 'https://example.test');
        $this->source('Example (JA)', 2, 'https://example.test/manga', icon: '/api/v1/extension/icon/ja');
        $this->source('Local One', 4, null);
        $this->source('Other', 3, 'https://other.test');
        $this->source('Uninstalled', 5, 'https://hidden.test', installed: false);
        $this->source('Adult One', 6, 'https://adult.test', rating: Source::NSFW);

        SourceHealth::create([
            'source_id' => 1,
            'name' => 'Example EN',
            'lang' => 'en',
            'consecutive_failures' => 0,
            'total_successes' => 1,
            'total_failures' => 0,
            'is_dead' => false,
        ]);

        SourceHealth::create([
            'source_id' => 3,
            'name' => 'Other',
            'lang' => 'en',
            'consecutive_failures' => 2,
            'total_successes' => 0,
            'total_failures' => 2,
            'is_dead' => false,
        ]);

        $groups = $this->data()->browseSites(false);

        $this->assertSame(
            ['example.test', null, 'other.test'],
            array_column($groups, 'host'),
            'the biggest site first, then the local bucket, then the one variant site',
        );

        $this->assertSame([2, 1, 1], array_map(static fn (array $group): int => count($group['sources']), $groups));
        $this->assertSame('Example', $groups[0]['site'], 'the language suffix is stripped from the site name');
        $this->assertSame('https://example.test', $groups[0]['health_site']);
        $this->assertSame('/api/v1/extension/icon/ja', $groups[0]['icon'], 'the first variant that has an icon is borrowed');
        $this->assertSame(['Healthy', null, 'Not answering'], array_map(
            static fn (array $group): ?string => $group['health']['label'] ?? null,
            $groups,
        ));

        $this->assertSame(
            ['id' => 1, 'source_id' => 1, 'name' => 'Example (EN)', 'lang' => 'en', 'latest' => true, 'icon' => null],
            $groups[0]['sources'][0],
        );

        $gated = $this->data()->browseSites(true);
        $this->assertContains('adult.test', array_column($gated, 'host'));
        $this->assertNotContains('hidden.test', array_column($gated, 'host'), 'only installed sources are doors that work');
    }

    public function test_browse_uses_the_health_page_key_for_a_source_with_no_home_url(): void
    {
        $this->source('Local One', 4, null);

        $groups = $this->data()->browseSites(false);

        $this->assertCount(1, $groups);
        $this->assertSame('On this machine', $groups[0]['site']);
        $this->assertNull($groups[0]['host']);
        $this->assertSame('unknown', $groups[0]['health_site']);
        $this->assertNull($groups[0]['health']);
    }

    // Pins ---------------------------------------------------------------

    public function test_pins_are_per_reader_and_a_card_reads_the_same_sources_it_writes(): void
    {
        $this->source('Example EN', 1, 'https://example.test');
        $this->source('Example JA', 2, 'https://example.test/ja');
        $this->source('Other', 3, 'https://other.test');
        $this->source('Local One', 4, null);

        $data = $this->data();

        $this->assertSame([], $data->pinnedIds());

        UserSource::setPinnedFor($this->user->id, [1], true);

        $this->assertSame([1], $data->pinnedIds());
        $this->assertTrue($data->isPinnedSite('example.test', $data->pinnedIds()));
        $this->assertFalse($data->isPinnedSite('other.test', $data->pinnedIds()));
        $this->assertSame([1, 2], $data->cardSourceIds('example.test'));
        $this->assertSame([4], $data->cardSourceIds('local'));

        $other = User::factory()->create();
        $this->actingAs($other);

        $this->assertSame([], $data->pinnedIds(), 'a pin belongs to the reader who pressed it');
    }

    // Languages ----------------------------------------------------------

    public function test_catalogue_languages_name_count_and_keep_the_catalogue_casing(): void
    {
        $this->nodes = [
            $this->extension('pkg.en.one', 'English One', lang: 'en'),
            $this->extension('pkg.en.two', 'English Two', lang: 'en'),
            $this->extension('pkg.ja.one', 'Japanese One', lang: 'ja'),
            $this->extension('pkg.pt.one', 'Portuguese One', lang: 'pt-BR'),
        ];

        $languages = $this->data()->catalogueLanguages([], ['pt-BR']);

        $this->assertSame(['en', 'ja', 'pt-BR'], array_column($languages, 'code'));
        $this->assertSame([2, 1, 1], array_column($languages, 'count'));
        $this->assertSame(['English', 'Japanese', 'Portuguese (Brazil)'], array_column($languages, 'name'));
        $this->assertSame([true, true, false], array_column($languages, 'on'));

        $caseMissed = $this->data()->catalogueLanguages([], ['pt-br']);

        $this->assertTrue(
            $caseMissed[2]['on'],
            'the catalogue and the server match case sensitively, so a folded code switches nothing off',
        );
    }

    public function test_catalogue_languages_are_empty_when_the_server_cannot_be_read(): void
    {
        $this->nodes = [$this->extension('pkg.en.one', 'English One', lang: 'en')];
        $this->extensionsShouldFail = true;

        $this->assertSame([], $this->data()->catalogueLanguages([], []));
    }

    // Fixtures -----------------------------------------------------------

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');
            $variables = $this->requestVariables($request);

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($query, 'query Many')) {
                if ($this->libraryShouldFail) {
                    return Http::response('The server is not answering.', 500);
                }

                $nodes = [];

                foreach ((array) ($variables['ids'] ?? []) as $id) {
                    $node = $this->libraryNodes[(int) $id] ?? null;

                    if ($node === null) {
                        continue;
                    }

                    $nodes[] = [
                        'id' => (string) (int) $id,
                        'title' => $node['title'],
                        'thumbnailUrl' => null,
                        'status' => 'ONGOING',
                        'initialized' => true,
                        'source' => [
                            'id' => $node['source_id'],
                            'name' => $node['source_name'],
                            'lang' => 'en',
                        ],
                        'chapters' => ['totalCount' => 30, 'nodes' => []],
                    ];
                }

                return Http::response(['data' => ['mangas' => ['nodes' => $nodes]]]);
            }

            if (str_contains($query, 'extensions(')) {
                if ($this->extensionsShouldFail) {
                    return Http::response('The server is not answering.', 500);
                }

                if (array_key_exists('first', $variables)) {
                    $this->lastFirst = (int) $variables['first'];
                    $this->lastOffset = (int) ($variables['offset'] ?? 0);
                }

                $filtered = $this->filteredNodes($variables);

                return Http::response(['data' => ['extensions' => [
                    'totalCount' => count($filtered),
                    'nodes' => $filtered,
                ]]]);
            }

            return Http::response(['data' => ['ok' => true]]);
        });
    }

    /**
     * The nodes the fake will serve, honouring the filters the page sends.
     *
     * @param  array<string, mixed>  $variables
     * @return array<int, array<string, mixed>>
     */
    private function filteredNodes(array $variables): array
    {
        $installed = null;
        $hasUpdate = null;
        $languagesOut = [];
        $ratingsIn = [];
        $name = null;

        foreach ($variables['filter']['and'] ?? [] as $clause) {
            $field = $clause['isInstalled'] ?? null;

            if (is_array($field)) {
                $installed = (bool) ($field['equalTo'] ?? false);

                continue;
            }

            $field = $clause['hasUpdate'] ?? null;

            if (is_array($field)) {
                $hasUpdate = (bool) ($field['equalTo'] ?? false);

                continue;
            }

            $lang = $clause['lang'] ?? null;

            if (is_array($lang) && is_array($lang['notIn'] ?? null)) {
                $languagesOut = $lang['notIn'];

                continue;
            }

            $rating = $clause['contentWarning'] ?? null;

            if (is_array($rating) && is_array($rating['in'] ?? null)) {
                $ratingsIn = $rating['in'];

                continue;
            }

            $nameClause = $clause['name']['includesInsensitive'] ?? null;

            if (is_string($nameClause)) {
                $name = $nameClause;
            }
        }

        return array_values(array_filter(
            $this->nodes,
            function (array $node) use ($installed, $hasUpdate, $languagesOut, $ratingsIn, $name): bool {
                if ($installed !== null && (bool) ($node['isInstalled'] ?? false) !== $installed) {
                    return false;
                }

                if ($hasUpdate !== null && (bool) ($node['hasUpdate'] ?? false) !== $hasUpdate) {
                    return false;
                }

                if ($languagesOut !== [] && in_array((string) ($node['lang'] ?? ''), $languagesOut, true)) {
                    return false;
                }

                if ($ratingsIn !== [] && ! in_array((string) ($node['contentWarning'] ?? ''), $ratingsIn, true)) {
                    return false;
                }

                if ($name !== null && stripos((string) ($node['name'] ?? ''), $name) === false) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function requestVariables(Request $request): array
    {
        $body = $request->body();

        if (! is_string($body) || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? ($decoded['variables'] ?? []) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function extension(
        string $pkg,
        string $name,
        bool $installed = false,
        string $rating = Source::SAFE,
        string $lang = 'en',
        bool $hasUpdate = false,
    ): array {
        return [
            'pkgName' => $pkg,
            'name' => $name,
            'versionName' => '1.2.3',
            'contentWarning' => $rating,
            'lang' => $lang,
            'isInstalled' => $installed,
            'hasUpdate' => $hasUpdate,
            'isObsolete' => false,
            'iconUrl' => null,
            'storeIndexUrl' => 'https://example.test/index.pb',
        ];
    }

    private function source(
        string $name,
        int $sourceId,
        ?string $homeUrl,
        string $lang = 'en',
        bool $installed = true,
        string $rating = Source::SAFE,
        ?string $icon = null,
    ): Source {
        return Source::create([
            'suwayomi_source_id' => $sourceId,
            'name' => $name,
            'display_name' => $name,
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.'.$lang.'.example',
            'lang' => $lang,
            'content_warning' => $rating,
            'home_url' => $homeUrl,
            'icon_url' => $icon,
            'is_installed' => $installed,
            'supports_latest' => true,
        ]);
    }
}
