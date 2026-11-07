<?php

namespace Tests\Feature;

use App\Models\ExtensionStore;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\SourceHealth;
use App\Models\User;
use App\Models\UserSource;
use App\Support\AdultContent;
use App\Support\SourceNotices;
use App\Suwayomi\ExtensionManager;
use App\Suwayomi\StoreHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeStoreHostResolver;
use Tests\Support\MintsTokens;
use Tests\TestCase;

class SourcesPageTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /**
     * The answers the store address policy sees, so no test depends on this machine's DNS.
     */
    private FakeStoreHostResolver $resolver;

    /**
     * The nodes the fake will serve.
     *
     * A property rather than a closure argument because a second Http::fake()
     * does not replace the first: Laravel keeps the earliest matching stub, so
     * re-faking inside a test silently serves the original data instead.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $nodes = [];

    /**
     * Whether the update mutation should answer with a failure. A property the
     * one fake reads, because a second Http::fake() does not replace the first.
     */
    private bool $updateShouldFail = false;

    /**
     * The sources the catalogue query will serve, for the mirroring tests.
     *
     * Empty by default so the tests that predate mirroring are unaffected: with no nodes and
     * a `totalCount` of zero, `SourceCatalog::syncSources()` takes its incomplete branch and
     * marks nothing uninstalled, because the guard requires a non-empty result as well as a
     * matching count.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $sourceNodes = [];

    /**
     * Whether the catalogue query should fail, to prove a failed mirror is survivable.
     */
    private bool $catalogShouldFail = false;

    /**
     * Whether the extension list should answer with a server error.
     *
     * The F26 shape: Suwayomi could not be read at all, and the page must render
     * with the failure named rather than answering 500 or drawing an empty list.
     */
    private bool $extensionListShouldFail = false;

    /**
     * The manga nodes the fake answers a library read with, keyed by manga id.
     *
     * The Migrate tab is the first part of this page that reads the reader's
     * library, so before it nothing here needed a `query Many` branch: the
     * generic `ok` answer below was all a library read ever came back to.
     *
     * @var array<int, array{source_id: string, source_name: string, title: string}>
     */
    private array $libraryNodes = [];

    /**
     * Whether the library read should answer with a server error, so the
     * Migrate view's survivability is exercised rather than assumed.
     */
    private bool $libraryShouldFail = false;

    /**
     * How many times the server was asked to fetch its own catalogue.
     *
     * `fetchExtensions` is the mutation behind the manual Refresh catalogue
     * button, and the auto-load tests count it because the extension list is
     * served by this fake no matter what the real server remembered, so the
     * rows appearing proves nothing on its own.
     */
    private int $fetchCount = 0;

    /**
     * Whether the catalogue fetch should answer with a server error, to prove
     * the store still added and the failure still has words on the page.
     */
    private bool $fetchShouldFail = false;

    /**
     * What the fetchExtensions mutation replies with, as pkgNames to count.
     *
     * The default is what every earlier test measured against: a reply
     * carrying three extensions the page can then list. The empty reply is
     * the live shape for a first fetch, measured on 2026-10-17: Suwayomi
     * answers fetchExtensions before its own fetch has populated anything,
     * which is what the page's note about an accepted but unpopulated fetch
     * has to stay truthful over.
     *
     * @var array<int, string>
     */
    private array $fetchRepliesWith = [
        'eu.kanade.tachiyomi.extension.en.one',
        'eu.kanade.tachiyomi.extension.all.two',
        'eu.kanade.tachiyomi.extension.en.three',
    ];

    /**
     * Whether the server holds no extensions until its fetch has been accepted.
     *
     * The cold install too: reads that follow the accepted fetch can land
     * either side of the fetch itself, since the reply and the population are
     * not the same event upstream. Off by default, where the initial nodes of
     * the fake stand for a server that already holds its catalogue.
     */
    private bool $catalogueArrivesAfterFetch = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => User::ROLE_INSTALL_OWNER]);

        $this->actingAs($this->user);

        $this->resolver = new FakeStoreHostResolver;
        $this->app->instance(StoreHostResolver::class, $this->resolver);

        $this->fakeSuwayomi();
    }

    /**
     * The nodes the fake will serve, honouring the `isInstalled`, `lang` and
     * `contentWarning` filters.
     *
     * The real server filters server-side, and the drift notice asks for the installed ones
     * only. A fake that ignored the filter would hand the notice every uninstalled extension
     * in the catalogue and it would accuse the reader of missing sources they never added.
     *
     * The language filter is honoured the same way and for the same reason: the whole
     * claim of the language selector is that the list it shows is the list the server
     * would send, and a fake that ignored the filter would let a page that sends the
     * wrong filter look exactly like one that sends the right one.
     *
     * The rating filter is honoured for the same reason and one more: the whole claim
     * of the adult content gate is that the rating in the address bar cannot reach an
     * adult rated extension. A fake that ignored it would let a page that sends no
     * rating filter look exactly like one that sends the right one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filteredNodes(Request $request): array
    {
        /*
         * Decoded from the raw body rather than read off `Request::data()`.
         *
         * `data()` hands back stdClass for nested JSON, so `['variables']['filter']['and']`
         * is "Cannot use object of type stdClass as array". json_decode with assoc makes the
         * whole shape arrays, which is what the indexes below assume.
         *
         * The filter is not read at all when the body will not decode, so a stub that cannot
         * see the filter serves the unfiltered list exactly as it did before this existed.
         */
        $variables = $this->requestVariables($request);

        $installed = null;
        $languagesOut = [];
        $ratingsIn = [];

        foreach ($variables['filter']['and'] ?? [] as $clause) {
            $field = $clause['isInstalled'] ?? null;

            if (is_array($field)) {
                $installed = (bool) ($field['equalTo'] ?? false);

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
            }
        }

        return array_values(array_filter(
            $this->nodes,
            function (array $n) use ($installed, $languagesOut, $ratingsIn): bool {
                if ($installed !== null && (bool) ($n['isInstalled'] ?? false) !== $installed) {
                    return false;
                }

                if ($languagesOut !== [] && in_array((string) ($n['lang'] ?? ''), $languagesOut, true)) {
                    return false;
                }

                if ($ratingsIn !== [] && ! in_array((string) ($n['contentWarning'] ?? ''), $ratingsIn, true)) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * A request's GraphQL variables as plain arrays.
     *
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
     * Choose what the catalogue endpoint returns for the rest of the test.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function serveExtensions(array $nodes): void
    {
        $this->nodes = $nodes !== [] ? $nodes : $this->defaultNodes();
    }

    /**
     * Choose what the source catalogue endpoint returns.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function serveSources(array $nodes): void
    {
        $this->sourceNodes = $nodes;
    }

    /**
     * One row of the server's source catalogue.
     *
     * @return array<string, mixed>
     */
    private function source(int $id, string $pkg, string $name, ?string $icon = null): array
    {
        return [
            'id' => (string) $id,
            'name' => $name,
            'displayName' => $name,
            'lang' => 'en',
            'contentWarning' => 'SAFE',
            'homeUrl' => 'https://example.test',
            'iconUrl' => $icon === null ? null : '/api/v1/extension/icon/'.$pkg,
            'supportsLatest' => true,
            'isConfigurable' => false,
            'extension' => ['pkgName' => $pkg, 'isInstalled' => true],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultNodes(): array
    {
        return [
            $this->extension('eu.kanade.tachiyomi.extension.all.example', 'Example', true, true),
            $this->extension('eu.kanade.tachiyomi.extension.en.other', 'Other', false, false),
        ];
    }

    private function fakeSuwayomi(): void
    {
        $this->nodes = $this->defaultNodes();

        Http::fake(function (Request $request) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => ['accessToken' => $this->accessToken(), 'refreshToken' => $this->refreshToken()]],
                ]);
            }

            /*
             * The batched library read, which is how the Migrate tab learns what
             * the reader's holdings are. Keyed on its own property rather than the
             * catalogue's, because a library read and an extension read are two
             * different facts about the same server, and no test before the tab
             * needed either.
             */
            if (str_contains($query, 'query Many')) {
                if ($this->libraryShouldFail) {
                    return Http::response('The server is not answering.', 500);
                }

                $nodes = [];

                foreach ((array) ($this->requestVariables($request)['ids'] ?? []) as $id) {
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

            if (str_contains($query, 'extensionStores')) {
                return Http::response(['data' => ['extensionStores' => ['nodes' => [[
                    'indexUrl' => 'https://example.test/index.pb',
                    'name' => 'Test Store',
                    'badgeLabel' => 'TEST',
                    'signingKey' => '0123456789abcdefFULLKEY',
                    'contactWebsite' => null,
                    'contactDiscord' => null,
                    'isLegacy' => false,
                    'extensionListUrl' => null,
                ]]]]]);
            }

            /*
             * `addStore` sends its mutation, and without a stub the server fake
             * answers the generic `ok` body, from which `addStore` throws
             * "accepted the store URL but returned no store". Every auto-load
             * test dies at that door instead of at the seam it tests, so the
             * mutation is answered with the store shape the server returns.
             */
            if (str_contains($query, 'addExtensionStore')) {
                $url = $this->requestVariables($request)['u'] ?? 'https://example.test/index.pb';

                return Http::response(['data' => ['addExtensionStore' => ['extensionStore' => [
                    'indexUrl' => $url,
                    'name' => 'Test Store',
                    'badgeLabel' => 'TEST',
                    'signingKey' => '0123456789abcdefSTOREKEY',
                    'contactWebsite' => null,
                    'contactDiscord' => null,
                    'isLegacy' => false,
                    'extensionListUrl' => null,
                ]]]]);
            }

            /*
             * Same counting trap 63 documents, one level up: the fetch count is
             * the auto-load tests' measurement, kept on a branch of its own
             * rather than read back from anywhere else.
             */
            if (str_contains($query, 'fetchExtensions')) {
                $this->fetchCount++;

                if ($this->fetchShouldFail) {
                    return Http::response('The server is not answering.', 500);
                }

                return Http::response(['data' => ['fetchExtensions' => ['extensions' => array_map(
                    fn (string $pkg): array => ['pkgName' => $pkg],
                    $this->fetchRepliesWith,
                )]]]);
            }

            /*
             * Order matters and it is not the obvious order.
             *
             * The catalogue document is `query Sources { sources { ... extension { pkgName
             * isInstalled } ... } }`, which contains the substring "extension" and so would
             * be caught by a branch testing for that. Anything that looks for the word first
             * silently serves the extension list to the catalogue query, and the mirror then
             * reads zero nodes from a payload with no `sources` key at all.
             *
             * So the catalogue is matched first, on `sources {`, which no extension query
             * contains.
             */
            if (str_contains($query, 'sources {')) {
                if ($this->catalogShouldFail) {
                    return Http::response(['errors' => [['message' => 'The server is not answering']]], 200);
                }

                return Http::response(['data' => ['sources' => [
                    'totalCount' => count($this->sourceNodes),
                    'nodes' => $this->sourceNodes,
                ]]]);
            }

            if (str_contains($query, 'extensions(')) {
                if ($this->extensionListShouldFail) {
                    return Http::response('The server is not answering.', 500);
                }

                /*
                 * The cold shape, decided at read time: nothing until the fetch
                 * has been accepted, the rows behind its next read. A flag read
                 * now is the whole difference, because which side of the fetch
                 * a read falls on is not knowable when the test is written.
                 */
                if ($this->catalogueArrivesAfterFetch && $this->fetchCount === 0) {
                    return Http::response(['data' => ['extensions' => [
                        'totalCount' => 0,
                        'nodes' => [],
                    ]]]);
                }

                return Http::response(['data' => ['extensions' => [
                    'totalCount' => count($this->filteredNodes($request)),
                    'nodes' => $this->filteredNodes($request),
                ]]]);
            }

            if (str_contains($query, 'updateExtension')) {
                if ($this->updateShouldFail) {
                    return Http::response(['errors' => [['message' => 'Download failed']]], 200);
                }

                return Http::response(['data' => ['updateExtension' => ['clientMutationId' => null]]]);
            }

            return Http::response(['data' => ['ok' => true]]);
        });
    }

    /**
     * A mirror row, the way a synced install answers.
     *
     * Distinct from {@see Source()}, which builds a node for the catalogue fake:
     * this is the `sources` table row the holdings tally joins against.
     */
    private function mirrorSource(string $name, string $sourceId): Source
    {
        return Source::create([
            'suwayomi_source_id' => $sourceId,
            'name' => $name,
            'display_name' => $name,
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.en.'.strtolower(str_replace(' ', '', $name)),
            'lang' => 'en',
            'home_url' => "https://{$name}.test",
            'content_warning' => Source::SAFE,
            'is_installed' => true,
            'supports_latest' => true,
        ]);
    }

    /**
     * One series on the server, as a library read answers it, with the reader's
     * own row beside it so the lens counts it.
     */
    private function libraryNode(int $mangaId, string $sourceId, string $title, string $sourceName): void
    {
        $this->libraryNodes[$mangaId] = [
            'source_id' => $sourceId,
            'source_name' => $sourceName,
            'title' => $title,
        ];

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => $mangaId,
            'in_library' => true,
            'title' => $title,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extension(string $pkg, string $name, bool $installed, bool $trusted, string $lang = 'en', string $rating = Source::SAFE): array
    {
        return [
            'pkgName' => $pkg,
            'name' => $name,
            'versionName' => '1.2.3',
            'contentWarning' => $rating,
            'lang' => $lang,
            'isInstalled' => $installed,
            'hasUpdate' => false,
            'isObsolete' => false,
            'iconUrl' => null,
            'storeIndexUrl' => $trusted ? 'https://example.test/index.pb' : 'https://untrusted.test/index.pb',
        ];
    }

    private function store(string $url = 'https://example.test/index.pb', ?string $key = 'abc123'): ExtensionStore
    {
        return ExtensionStore::create([
            'index_url' => $url,
            'name' => 'Test Store',
            'signing_key' => $key,
        ]);
    }

    private function page(): Testable
    {
        return Livewire::test('pages::sources');
    }

    public function test_it_requires_authentication(): void
    {
        auth()->logout();

        $this->get('/sources')->assertRedirect(route('login'));
    }

    public function test_the_page_explains_the_store_and_trust_path_in_reader_words(): void
    {
        $this->page()
            ->assertSee('Papyrus uses sources to find manga.')
            ->assertSee('Add a store, compare its signing key with the publisher\'s, then trust it before installing its extensions.');
    }

    /**
     * Sources is reached from the library and is not a dead end of its own.
     *
     * The sidebar keeps the same destination, but the sidebar is a different
     * gesture: a reader who followed a source from a series page wants the
     * shelf they came from, not a menu.
     */
    public function test_the_page_says_where_its_back_arrow_goes(): void
    {
        $this->page()->assertSee('Back to your library');
    }

    /**
     * F26. The sandbox run of 2026-10-15 passed all 58 checks while the guest's
     * Suwayomi was up and answering and this page showed the app's 500: the list
     * and the count both threw and nothing caught either.
     *
     * The callout stands in place of the list, because "Nothing installed yet"
     * over a server that could not be read is a lie the reader would act on.
     */
    public function test_the_page_renders_when_the_extension_list_cannot_be_read(): void
    {
        $this->store();

        $this->extensionListShouldFail = true;

        $this->get('/sources')
            ->assertOk()
            ->assertSee('The extension list could not be loaded')
            ->assertSee('Refresh catalogue above to try again.')
            ->assertDontSee('Suwayomi returned HTTP 500')
            ->assertDontSee('Nothing installed yet')
            ->assertDontSee('0 extensions');
    }

    public function test_a_manual_catalogue_refresh_hides_the_raw_upstream_error(): void
    {
        $this->fetchShouldFail = true;

        $this->page()
            ->call('refresh')
            ->assertDispatched('toast-show', function (string $name, array $params): bool {
                return ($params['slots']['heading'] ?? null) === 'Catalogue not refreshed'
                    && ($params['slots']['text'] ?? null) === 'Papyrus could not refresh the catalogue. Try again.'
                    && ($params['dataset']['variant'] ?? null) === 'danger';
            });
    }

    public function test_it_lists_stores_with_the_signing_key_shown_in_full(): void
    {
        $this->store(key: '0123456789abcdeffullkeyvalue');

        $this->page()
            ->assertSee('Test Store')
            ->assertSee('0123456789abcdeffullkeyvalue');
    }

    /**
     * The key is the entire point of the trust step, so a truncated fingerprint
     * would make it impossible to compare against the publisher.
     */
    public function test_it_does_not_truncate_the_signing_key(): void
    {
        $key = '0123456789abcdef5f3a91b2c7d84e0f1234567890abcdef1234567890abcdef';

        $this->store(key: $key);

        $html = $this->page()->html();

        $this->assertStringContainsString($key, $html);
        $this->assertStringNotContainsString(substr($key, 0, 20).'…', $html);
    }

    public function test_it_says_so_when_a_store_publishes_no_signing_key(): void
    {
        $this->store(key: null);

        $this->page()->assertSee('publishes no signing key');
    }

    public function test_it_offers_trust_before_an_installation_is_possible(): void
    {
        $this->store();
        $this->serveExtensions([$this->extension('eu.kanade.x', 'Example', false, false)]);

        $html = $this->page()
            ->set('view', 'available')
            ->assertSee('Trust this key')
            ->assertSee('Trust this extension’s store before installing it.')
            ->html();

        // The package name is listed, but there is no Install control for it.
        $this->assertStringContainsString('eu.kanade.x', $html);
        $this->assertStringNotContainsString('wire:click="install(', $html);
    }

    /**
     * Blade does not compile directives inside a component tag's attributes, so
     *
     * @js() written there reaches the browser verbatim and Alpine throws
     * "Invalid or unexpected token" the moment the control is clicked. Livewire's
     * test harness never runs Alpine, so nothing else in the suite would catch it.
     */
    public function test_no_directive_is_left_uncompiled_in_an_attribute(): void
    {
        $this->store();

        $html = $this->page()->html();

        $this->assertDoesNotMatchRegularExpression(
            '/(?:wire:|x-)[a-z.:-]+="[^"]*@[a-z]+\(/',
            $html,
            'A Blade directive inside a component tag attribute reaches the browser as text.',
        );
    }

    /**
     * The regression that only the browser found.
     *
     * With #[Url(as: 'content', except: '')] a fresh load of ?view=available carries
     * no content parameter, and Livewire hydrates the array property from the missing
     * value rather than keeping the declared default. The Available tab then reported
     * every extension, NSFW included, instead of the SAFE and MIXED subset that
     * measured 999 live.
     */
    public function test_the_safe_content_default_survives_a_fresh_load_of_a_deep_link(): void
    {
        $this->store();

        $this->page()
            ->set('view', 'available')
            ->assertSet('content', ['SAFE', 'MIXED']);
    }

    /**
     * The other half of the same regression.
     *
     * The Installed tab must carry no content filter at all, because an extension is
     * either on the server or it is not. Nothing is hidden today only because
     * a community store ships no NSFW extension among the installed set, so glancing at the
     * page would have called this fine.
     */
    public function test_the_installed_tab_carries_no_content_filter(): void
    {
        $this->store();

        $this->page()->assertSet('content', []);
    }

    /**
     * A hidden button is not a control, so the refusal has to hold at the action as
     * well. The lock icon explains it in the interface; this proves it is not merely
     * advisory, since anyone can call a Livewire method from the console.
     */
    public function test_installing_from_an_untrusted_store_is_refused_even_if_called_directly(): void
    {
        $this->store();
        $this->serveExtensions([$this->extension('eu.kanade.x', 'Example', false, false)]);

        $this->page()
            ->set('view', 'available')
            ->call('install', 'eu.kanade.x');

        Http::assertNotSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'updateExtension'),
        );

        $this->assertDatabaseCount('user_extension_stores', 0);
    }

    /**
     * Every extension in a store inherits that store's trust, so one untrusted store
     * turns the whole catalogue into padlocks. The gate is deliberate, so the test
     * is that the way past it is named rather than left to be inferred.
     */
    public function test_it_names_the_way_past_the_trust_gate(): void
    {
        $this->store();
        $this->serveExtensions([$this->extension('eu.kanade.x', 'Example', false, false)]);

        $this->page()
            ->set('view', 'available')
            ->assertSee('Nothing is installable until you trust a store');
    }

    public function test_the_prompt_is_gone_once_a_store_is_trusted(): void
    {
        $store = $this->store();

        $this->page()
            ->call('trust', $store->id)
            ->assertDontSee('Nothing is installable until you trust a store');
    }

    public function test_trusting_a_store_enables_its_install_button(): void
    {
        $store = $this->store();

        $this->page()
            ->call('trust', $store->id)
            ->assertSet('selected', []);

        $this->assertTrue(
            app(ExtensionManager::class)->isTrusted($this->user, $store->fresh()),
        );
    }

    public function test_trust_is_recorded_against_the_signed_in_user(): void
    {
        $store = $this->store();

        $this->page()->call('trust', $store->id);

        $this->assertDatabaseHas('user_extension_stores', [
            'user_id' => $this->user->id,
            'extension_store_id' => $store->id,
        ]);
    }

    public function test_withdrawing_trust_does_not_uninstall_anything(): void
    {
        $store = $this->store();

        $this->page()
            ->call('trust', $store->id)
            ->call('revokeTrust', $store->id);

        $this->assertDatabaseHas('user_extension_stores', [
            'user_id' => $this->user->id,
            'extension_store_id' => $store->id,
            'trusted_at' => null,
        ]);
    }

    public function test_a_bulk_install_ignores_ticked_rows_from_untrusted_stores(): void
    {
        $store = $this->store();

        $this->page()
            ->set('view', 'available')
            ->set('selected', ['eu.kanade.x', 'eu.kanade.y'])
            ->call('installSelected');

        // The list only offers an untrusted row, so nothing is installable and
        // the selection is cleared rather than acted on.
        $this->assertDatabaseCount('user_extension_stores', 0);
    }

    /**
     * The reason these cards exist.
     *
     * `iconUrl` was in this query's selection set from the first build and had never been
     * rendered, which is most of why the list read as generic: fifty rows of a name, a badge
     * and a package name with nothing to tell them apart.
     *
     * The URL is asserted in full because the endpoint needs the bearer token. Measured
     * against the live server, anonymous answers 401 with a twelve byte body and with a token
     * answers 200 image/png, so an `<img>` pointed straight at Suwayomi renders nothing and
     * looks exactly like an icon that was never wired up.
     */
    public function test_a_source_card_draws_its_icon_through_the_proxy(): void
    {
        $this->serveSources([$this->source(1, 'eu.kanade.tachiyomi.extension.en.example', 'Example', 'icon')]);

        $this->page()
            ->call('syncSources')
            ->assertOk()
            ->assertSee('/suwayomi/api/v1/extension/icon/eu.kanade.tachiyomi.extension.en.example', escape: false);
    }

    public function test_an_extension_card_draws_its_icon_through_the_proxy(): void
    {
        $this->nodes = [$this->withIcon(
            $this->extension('eu.kanade.tachiyomi.extension.en.example', 'Example', true, true),
        )];

        $this->page()
            ->assertOk()
            ->assertSee('/suwayomi/api/v1/extension/icon/eu.kanade.tachiyomi.extension.en.example', escape: false);
    }

    /**
     * Not every extension ships an icon, and one that does not must still leave a card of the
     * same shape. The frame reserves the square before the bytes arrive, so a grid of them
     * does not shove the layout sideways one at a time, and an icon that 404s removes itself
     * and leaves that frame rather than a broken image glyph.
     */
    public function test_a_source_without_an_icon_still_holds_its_space(): void
    {
        $this->serveSources([
            $this->source(1, 'eu.kanade.tachiyomi.extension.en.example', 'Example', 'icon'),
            $this->source(2, 'eu.kanade.tachiyomi.extension.en.bare', 'Bare'),
        ]);

        $page = $this->page()->call('syncSources')->assertOk();

        $page->assertSee('/extension/icon/eu.kanade.tachiyomi.extension.en.example', escape: false)
            ->assertDontSee('/extension/icon/eu.kanade.tachiyomi.extension.en.bare', escape: false);

        // Both names are still on the page, so the one without an icon is drawn rather than
        // quietly dropped.
        $page->assertSee('Bare');
    }

    /**
     * The layout, asserted so it cannot quietly go back to one item per full-width row.
     *
     * These are the breakpoints the library, browse and search grids already use, which is
     * also the three to five per row that was asked for.
     */
    public function test_the_lists_are_grids_and_not_one_row_each(): void
    {
        $this->serveSources([$this->source(1, 'eu.kanade.tachiyomi.extension.en.example', 'Example', 'icon')]);

        // Sync first. The site list only renders once something has been mirrored, so a bare
        // render puts only the extensions list on screen.
        //
        // Worth stating plainly, because this test asserted two grids and was counting one:
        // it would have gone green the moment the whole site list was deleted, which is the
        // opposite of what it claims to hold.
        $page = $this->page()->call('syncSources')->assertOk();

        $grids = substr_count($page->html(), 'sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5');

        $this->assertSame(
            2,
            $grids,
            'the site list and the extension list, which is what made this page the longest one in the app',
        );

        $this->assertStringNotContainsString(
            'divide-y divide-zinc-200',
            $page->html(),
            'the extensions list is back to one item per row',
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function withIcon(array $node, ?string $icon = '/api/v1/extension/icon/'): array
    {
        $node['iconUrl'] = $icon === null ? null : $icon.$node['pkgName'];

        return $node;
    }

    public function test_the_installed_tab_is_the_default(): void
    {
        $this->store();

        $this->page()
            ->assertSet('view', 'installed')
            ->assertSee('Example');
    }

    /**
     * NSFW gating belongs to the reader, and an already installed extension is
     * already installed whatever its rating, so the Installed tab must not
     * filter on content rating at all.
     */
    public function test_the_installed_tab_ignores_the_content_filter(): void
    {
        $this->store();

        $this->page()
            ->set('view', 'installed')
            ->assertSet('content', []);
    }

    public function test_switching_to_available_applies_the_safe_default(): void
    {
        $this->store();

        $this->page()
            ->set('view', 'available')
            ->assertSet('content', ['SAFE', 'MIXED']);
    }

    public function test_adding_a_store_requires_a_url(): void
    {
        $this->page()
            ->set('storeUrl', '')
            ->call('addStore')
            ->assertHasErrors(['storeUrl' => 'required']);
    }

    public function test_adding_a_store_rejects_something_that_is_not_a_url(): void
    {
        $this->page()
            ->set('storeUrl', 'not a url')
            ->call('addStore')
            ->assertHasErrors(['storeUrl' => 'url']);
    }

    /*
     * Adding and removing a store change what every reader on this server sees, so both
     * belong to the owner. A hidden control is not the rule, because a Livewire method is
     * callable from the browser console, which is why each half is asserted separately:
     * the reader does not see the control, and the reader cannot run the action.
     */

    public function test_the_owner_is_offered_the_store_form_and_the_remove_control(): void
    {
        $this->store();

        $html = $this->page()->html();

        $this->assertStringContainsString('wire:submit="addStore"', $html);
        $this->assertStringContainsString('removeStore(', $html);
    }

    public function test_a_reader_is_not_offered_the_store_form_or_the_remove_control(): void
    {
        $this->store();

        $this->actingAs(User::factory()->create());

        $html = $this->page()->html();

        $this->assertStringNotContainsString('wire:submit="addStore"', $html);
        $this->assertStringNotContainsString('removeStore(', $html);
    }

    public function test_a_reader_cannot_add_a_store_even_if_they_call_the_action(): void
    {
        $this->actingAs(User::factory()->create());

        $this->page()
            ->set('storeUrl', 'https://example.test/index.pb')
            ->call('addStore')
            ->assertForbidden();

        $this->assertDatabaseCount('extension_stores', 0);
    }

    public function test_a_reader_cannot_remove_a_store_even_if_they_call_the_action(): void
    {
        $store = $this->store();

        $this->actingAs(User::factory()->create());

        $this->page()
            ->call('removeStore', $store->index_url)
            ->assertForbidden();

        $this->assertDatabaseHas('extension_stores', ['id' => $store->id]);
    }

    /*
     * Installing and uninstalling change the shared Suwayomi process, so both belong to the
     * install owner whichever way the press reaches the page. A second account that trusts
     * a store still must not add to or remove from what every reader shares.
     */

    public function test_a_reader_cannot_uninstall_an_extension_even_if_they_call_the_action(): void
    {
        $store = $this->store();
        $pkg = 'eu.kanade.tachiyomi.extension.en.leaving';

        Source::query()->create([
            'suwayomi_source_id' => 2,
            'name' => 'Leaving',
            'display_name' => 'Leaving (EN)',
            'extension_pkg_name' => $pkg,
            'lang' => 'en',
            'is_installed' => true,
        ]);

        $this->serveExtensions([$this->extension($pkg, 'Leaving', true, true)]);

        $reader = User::factory()->create();

        $this->actingAs($reader);

        // The reader trusts the store too. Trust is per reader and is not the rule this
        // action is about, so the refusal below cannot be explained by an untrusted store.
        $this->page()->call('trust', $store->id);

        $this->page()
            ->call('uninstall', $pkg)
            ->assertForbidden();

        $this->assertDatabaseHas('sources', [
            'extension_pkg_name' => $pkg,
            'is_installed' => true,
        ]);

        Http::assertNotSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'updateExtension'),
        );
    }

    public function test_a_forged_pending_install_cannot_install_anything(): void
    {
        $store = $this->store();
        $pkg = 'eu.kanade.tachiyomi.extension.en.fresh';

        $this->serveExtensions([$this->extension($pkg, 'Fresh Scan', false, true)]);

        $this->page()->call('trust', $store->id);

        $component = $this->page()->set('view', 'available');

        $refused = false;

        try {
            $component->set('pendingInstall', [$pkg]);
        } catch (CannotUpdateLockedPropertyException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'Livewire refuses a pending install the page did not arm.');

        $component->call('installConfirmed');

        Http::assertNotSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'updateExtension'),
        );

        $this->assertDatabaseCount('sources', 0);
    }

    public function test_the_owner_can_still_install_and_uninstall(): void
    {
        $store = $this->store();

        $installed = 'eu.kanade.tachiyomi.extension.en.fresh';
        $staying = 'eu.kanade.tachiyomi.extension.en.staying';

        $this->serveExtensions([$this->extension($installed, 'Fresh Scan', false, true)]);
        $this->serveSources([$this->source(4242, $installed, 'Fresh Scan (EN)')]);

        $this->page()->call('trust', $store->id);

        $this->page()
            ->set('view', 'available')
            ->call('install', $installed)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sources', [
            'suwayomi_source_id' => 4242,
            'extension_pkg_name' => $installed,
            'is_installed' => true,
        ]);

        /*
         * The server now reports the extension as installed and lists one other source,
         * so the mirror read after the uninstall is complete. An empty catalogue would
         * take the incomplete branch and leave the leaving source marked installed.
         */
        $this->serveExtensions([$this->extension($installed, 'Fresh Scan', true, true)]);
        $this->serveSources([$this->source(4243, $staying, 'Staying (EN)')]);

        $this->page()
            ->call('uninstall', $installed)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sources', [
            'suwayomi_source_id' => 4242,
            'is_installed' => false,
        ]);
    }

    public function test_a_reader_is_not_offered_the_install_or_uninstall_controls(): void
    {
        $store = $this->store();

        $installed = 'eu.kanade.tachiyomi.extension.en.installed';
        $available = 'eu.kanade.tachiyomi.extension.en.available';

        $this->serveExtensions([
            $this->extension($installed, 'Installed', true, true),
            $this->extension($available, 'Available', false, true),
        ]);

        $reader = User::factory()->create();

        $this->actingAs($reader);

        $this->page()->call('trust', $store->id);

        $availableHtml = $this->page()->set('view', 'available')->html();

        $this->assertStringNotContainsString('wire:click="install(', $availableHtml);
        $this->assertStringNotContainsString('wire:model="selected"', $availableHtml);

        $installedHtml = $this->page()->set('view', 'installed')->html();

        $this->assertStringNotContainsString('uninstall(', $installedHtml);

        // The owner still gets both, so the controls were hidden rather than broken.
        $this->actingAs($this->user);

        $this->page()->call('trust', $store->id);

        $this->assertStringContainsString(
            'wire:click="install(',
            $this->page()->set('view', 'available')->html(),
        );
        $this->assertStringContainsString(
            'uninstall(',
            $this->page()->set('view', 'installed')->html(),
        );
    }

    /**
     * A store is fetched by Suwayomi, not by the browser, so an address on this machine asks
     * the server to fetch from itself or from the network behind it. The refusal is at the
     * catalog seam, which is why the page only has to show the sentence.
     */
    public function test_a_store_address_on_this_machine_is_refused_with_its_reason(): void
    {
        $this->page()
            ->set('storeUrl', 'http://127.0.0.1/index.pb')
            ->call('addStore')
            ->assertDispatched('toast-show', function (string $name, array $params): bool {
                return ($params['slots']['text'] ?? null) === 'That store address points at this server or a private network. Use a public address for the store.'
                    && ($params['dataset']['variant'] ?? null) === 'danger';
            });

        $this->assertDatabaseCount('extension_stores', 0);
    }

    public function test_it_reports_how_many_sources_a_sync_mirrored(): void
    {
        $this->page()->call('syncSources');

        $this->assertDatabaseHas('extension_stores', ['index_url' => 'https://example.test/index.pb']);
        $this->assertDatabaseHas('extension_stores', ['name' => 'Test Store']);
    }

    /**
     * Names are filtered in Suwayomi rather than in PHP, so a name typed into the
     * box must reach the server rather than narrowing what we already fetched.
     */
    public function test_the_name_filter_is_sent_to_the_server(): void
    {
        $this->store();

        $this->fakeSuwayomi();

        $this->page()
            ->set('view', 'available')
            ->set('name', 'berserk');

        Http::assertSent(function (Request $request): bool {
            $query = $request->data()['query'] ?? '';
            $variables = (array) ($request->data()['variables'] ?? []);

            if (! str_contains($query, 'extensions(')) {
                return false;
            }

            $and = $variables['filter']['and'] ?? [];

            return in_array(['name' => ['includesInsensitive' => 'berserk']], $and, true);
        });
    }

    public function test_paging_moves_the_offset(): void
    {
        $this->store();

        $this->page()
            ->set('view', 'available')
            ->call('nextPage')
            ->assertSet('page', 2);

        Http::assertSent(function (Request $request): bool {
            $query = $request->data()['query'] ?? '';
            $variables = (array) ($request->data()['variables'] ?? []);

            return str_contains($query, 'extensions(') && ($variables['offset'] ?? null) === 50;
        });
    }

    public function test_the_previous_button_is_disabled_on_the_first_page(): void
    {
        $this->store();

        $this->page()->call('previousPage')->assertSet('page', 1);
    }

    // Extension updates ------------------------------------------------------

    /**
     * The Updates tab drew a badge for months and offered no control anywhere
     * that acted on it, so the only route was the server's own WebUI. These
     * tests pin the wiring, including the trust gate, which an update needs
     * exactly as much as an install does: both execute a new signed artefact.
     */
    private function updatableExtension(): array
    {
        return array_merge(
            $this->extension('eu.kanade.tachiyomi.extension.en.example', 'Example', true, true),
            ['hasUpdate' => true],
        );
    }

    public function test_an_installed_extension_with_an_update_offers_update(): void
    {
        $this->store();
        app(ExtensionManager::class)->trust($this->user, ExtensionStore::query()->first());
        $this->serveExtensions([$this->updatableExtension()]);

        $component = $this->page()->assertSee('Update');

        $this->assertStringContainsString(
            'wire:click="update(',
            $component->html(),
            'The badge has a control beside it now.',
        );
    }

    public function test_the_update_control_is_hidden_behind_trust(): void
    {
        $this->store();
        // No trust granted.
        $this->serveExtensions([$this->updatableExtension()]);

        $html = $this->page()
            ->assertSee('Trust this extension’s store before updating it.')
            ->html();

        $this->assertStringNotContainsString('wire:click="update(', $html);
    }

    public function test_the_update_action_sends_the_server_s_own_mutation(): void
    {
        $this->store();
        app(ExtensionManager::class)->trust($this->user, ExtensionStore::query()->first());
        $this->serveExtensions([$this->updatableExtension()]);

        $this->page()
            ->call('update', 'eu.kanade.tachiyomi.extension.en.example')
            ->assertHasNoErrors();

        Http::assertSent(function (Request $request): bool {
            $query = $request->data()['query'] ?? '';

            return str_contains((string) $query, 'updateExtension')
                && str_contains((string) $query, 'update: true');
        });
    }

    public function test_update_all_walks_every_out_of_date_extension(): void
    {
        $this->store();
        app(ExtensionManager::class)->trust($this->user, ExtensionStore::query()->first());
        $this->serveExtensions([
            $this->updatableExtension(),
            array_merge(
                $this->extension('eu.kanade.tachiyomi.extension.en.second', 'Second', true, true),
                ['hasUpdate' => true],
            ),
        ]);

        $this->page()
            ->set('view', 'updates')
            ->call('updateAll')
            ->assertHasNoErrors();

        $mutations = count(Http::recorded(fn (Request $request): bool => str_contains(
            (string) ($request->data()['query'] ?? ''),
            'update: true',
        )));

        $this->assertSame(2, $mutations, 'Both out-of-date extensions get their own mutation.');
    }

    public function test_update_all_with_an_untrusted_store_updates_nothing(): void
    {
        $this->store();
        // Untrusted, so nothing on this page is allowed to execute its code.
        $this->serveExtensions([$this->updatableExtension()]);

        $this->page()->call('updateAll');

        $mutations = count(Http::recorded(fn (Request $request): bool => str_contains(
            (string) ($request->data()['query'] ?? ''),
            'update: true',
        )));

        $this->assertSame(0, $mutations);
    }

    public function test_a_failed_update_is_named_rather_than_swallowed(): void
    {
        $this->store();
        app(ExtensionManager::class)->trust($this->user, ExtensionStore::query()->first());
        $this->serveExtensions([$this->updatableExtension()]);

        $this->updateShouldFail = true;

        $component = $this->page()
            ->call('update', 'eu.kanade.tachiyomi.extension.en.example')
            ->assertHasNoErrors();

        $failures = $component->instance()->failures;

        $this->assertSame(
            'Download failed',
            $failures['eu.kanade.tachiyomi.extension.en.example'] ?? null,
            'A package that did not update is named with its reason.',
        );
    }

    /*
     * ---------------------------------------------------------------------------
     * The picker was stale after an install
     *
     * The report: add sources, look at the picker, see the old ones. The picker is built
     * from the local `Source` mirror, and installing an extension only tells the server, so
     * the mirror stayed where it was until somebody pressed Sync sources on another tab.
     * Measured on the box: 84 sources installed on the server, 82 in the mirror, the two
     * missing being extensions added at 11:43 and 11:44, and the mirror's newest row
     * timestamped 23:15 the night before.
     * ---------------------------------------------------------------------------
     */

    public function test_installing_an_extension_mirrors_its_source(): void
    {
        $store = $this->store();

        $pkg = 'eu.kanade.tachiyomi.extension.en.fresh';

        // Two preconditions, both of which make `install()` return before it reaches the
        // mirror, so both are visible here rather than as a puzzling empty table.
        //
        // Not installed: `canInstallName()` refuses to install what is already installed.
        // Trusted store: `isTrusted()` reads the reader's own trust rows, so an extension
        // whose publisher has not been trusted cannot be installed at all, which is the gate
        // `test_installing_from_an_untrusted_store_is_refused_even_if_called_directly` locks
        // down.
        $this->serveExtensions([$this->extension($pkg, 'Fresh Scan', false, true)]);
        $this->serveSources([$this->source(4242, $pkg, 'Fresh Scan (EN)')]);

        $component = $this->page();

        $component->call('trust', $store->id);

        /*
         * A second test instance on purpose.
         *
         * Trust is written by the first call, and `trustedIds` is a computed the first
         * instance cached from before that write. Chaining `->call('trust')->call('install')`
         * on one instance therefore evaluates `canInstallName()` against a cache that predates
         * the trust, gets false, and returns before mirroring anything.
         *
         * That is a Livewire caching fact, not a bug in the page: in a browser the reader
         * trusts a store, the page re-renders, and the next click reads fresh state.
         */
        /*
         * Three preconditions, each of which makes `install()` return before it reaches the
         * mirror, so each is visible here rather than as a puzzling empty table.
         *
         * The store has to be trusted, because `isTrusted()` reads this reader's trust rows.
         * The extension has to be listed, and the page opens on the Installed view, so an
         * extension that is not yet installed is only present in the Available one. And it has
         * to actually not be installed, since `canInstallName()` refuses to install what is
         * already there.
         *
         * The second of those is the subtle one: `canInstallName()` looks the package up in
         * `$this->extensions`, which is filtered server-side by the current view. On the
         * Installed view the list has nothing to find and the guard returns false, which looks
         * exactly like a mirroring failure and is not one at all.
         */
        $component = $this->page()
            ->set('view', 'available')
            ->call('install', $pkg)
            ->assertHasNoErrors();

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'sources {'),
        );

        $this->assertSame(
            1,
            Source::query()->where('suwayomi_source_id', 4242)->count(),
            'The catalogue query has to have been sent, so the fake answered with a source.',
        );

        /*
         * Without the mirror step this is the whole bug: the install succeeded, the server
         * has the source, and the picker cannot show it because nothing copied it across.
         */
        $this->assertDatabaseHas('sources', [
            'suwayomi_source_id' => 4242,
            'extension_pkg_name' => $pkg,
            'is_installed' => true,
        ]);
    }

    public function test_installing_makes_the_new_source_browsable_without_a_second_press(): void
    {
        $store = $this->store();

        $pkg = 'eu.kanade.tachiyomi.extension.en.fresh';

        $this->serveExtensions([$this->extension($pkg, 'Fresh Scan', false, true)]);
        $this->serveSources([$this->source(4242, $pkg, 'Fresh Scan (EN)')]);

        // A fresh instance after the trust write, and on the Available view where an
        // uninstalled extension is actually listed. Both reasons are given above.
        $this->page()->call('trust', $store->id);

        $page = $this->page()
            ->set('view', 'available')
            ->call('install', $pkg);

        /*
         * Read through a third instance, and this is the load-bearing part.
         *
         * `browseSites()` is a computed, and the instance that ran `install()` cached it
         * before the mirror was written: it was rendered with no sources and would answer
         * with an empty list forever after. Asserting on the same instance therefore proves
         * nothing about the picker, and asserting on the rendered HTML would fail for the
         * same reason.
         *
         * In a browser this is invisible, because the response to the click is a fresh render
         * and the dialog of computed values is cleared between requests. The test has to
         * reproduce that by asking a new instance, which is what a page load does.
         */
        $sites = $this->page()->instance()->browseSites();

        $this->assertNotSame([], $sites, 'A freshly installed source has to reach the picker.');

        $names = array_column($sites, 'sources');

        $this->assertNotSame(
            [],
            array_filter(array_merge(...$names), fn (array $s): bool => $s['name'] === 'Fresh Scan (EN)'),
            'The source the reader just installed has to be one of the picker entries.',
        );
    }

    public function test_uninstalling_an_extension_mirrors_its_source_away(): void
    {
        $this->store();

        $staying = 'eu.kanade.tachiyomi.extension.en.staying';
        $leaving = 'eu.kanade.tachiyomi.extension.en.leaving';

        Source::query()->create([
            'suwayomi_source_id' => 1,
            'name' => 'Staying',
            'display_name' => 'Staying (EN)',
            'extension_pkg_name' => $staying,
            'lang' => 'en',
            'is_installed' => true,
        ]);

        Source::query()->create([
            'suwayomi_source_id' => 2,
            'name' => 'Leaving',
            'display_name' => 'Leaving (EN)',
            'extension_pkg_name' => $leaving,
            'lang' => 'en',
            'is_installed' => true,
        ]);

        $this->serveExtensions([$this->extension($leaving, 'Leaving', true, true)]);
        $this->serveSources([$this->source(1, $staying, 'Staying (EN)')]);

        $this->page()
            ->call('uninstall', $leaving)
            ->assertHasNoErrors();

        /*
         * Mirrored on the way out too.
         *
         * A source left installed in the mirror is a browse link that 404s, which is the one
         * failure the picker is supposed to be structurally unable to have.
         */
        $this->assertDatabaseHas('sources', [
            'suwayomi_source_id' => 2,
            'is_installed' => false,
        ]);
    }

    public function test_the_picker_says_nothing_when_the_mirror_agrees(): void
    {
        $this->store();

        $pkg = 'eu.kanade.tachiyomi.extension.en.known';

        Source::query()->create([
            'suwayomi_source_id' => 7,
            'name' => 'Known',
            'display_name' => 'Known (EN)',
            'extension_pkg_name' => $pkg,
            'lang' => 'en',
            'is_installed' => true,
        ]);

        $this->serveExtensions([$this->extension($pkg, 'Known', true, true)]);
        $this->serveSources([$this->source(7, $pkg, 'Known (EN)')]);

        $this->page()
            ->assertDontSee('data-test="mirror-drift"', false);
    }

    public function test_the_picker_reports_extensions_missing_from_the_mirror(): void
    {
        $this->store();

        // Installed on the server, with no row in the mirror at all. This is the shape an
        // install performed through Suwayomi's own interface leaves behind, and the one half
        // that mirroring after our own installs cannot reach.
        $this->serveExtensions([
            $this->extension('eu.kanade.tachiyomi.extension.en.added', 'Added Scan', true, true),
        ]);
        $this->serveSources([$this->source(9, 'eu.kanade.tachiyomi.extension.en.added', 'Added Scan (EN)')]);

        $html = $this->page()
            ->assertSee('data-test="mirror-drift"', false)
            ->html();

        $this->assertStringContainsString('1 installed extension is missing from the source list below.', $html);
        $this->assertStringNotContainsString('Added Scan is installed', $html);

        $this->assertStringContainsString('data-test="mirror-drift-sync"', $html);
    }

    public function test_the_picker_does_not_claim_drift_it_cannot_see(): void
    {
        $this->store();

        $this->serveExtensions([
            $this->extension('eu.kanade.tachiyomi.extension.en.added', 'Added Scan', true, true),
        ]);
        $this->serveSources([$this->source(9, 'eu.kanade.tachiyomi.extension.en.added', 'Added Scan (EN)')]);

        // In the mirror now, so nothing is missing.
        Source::query()->create([
            'suwayomi_source_id' => 9,
            'name' => 'Added Scan',
            'display_name' => 'Added Scan (EN)',
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.en.added',
            'lang' => 'en',
            'is_installed' => true,
        ]);

        $page = $this->page();

        $this->assertSame([], $page->instance()->unmirroredExtensions());
        $page->assertDontSee('data-test="mirror-drift"', false);
    }

    public function test_a_failed_mirror_still_reports_the_install_as_done(): void
    {
        $this->store();

        $pkg = 'eu.kanade.tachiyomi.extension.en.brokenmirror';

        $this->serveExtensions([$this->extension($pkg, 'Broken Mirror', true, true)]);
        $this->catalogShouldFail = true;

        /*
         * The install succeeded. Reporting that as a failure would send a reader looking for
         * a problem with an extension that is installed and working, and the actual fault is
         * one button press away and named in the toast.
         */
        $this->page()
            ->call('install', $pkg)
            ->assertHasNoErrors()
            ->assertSee('Sync sources');
    }

    public function test_adding_a_store_loads_the_catalogue_itself(): void
    {
        /*
         * The manual Refresh catalogue button was the only way a new store's rows
         * reached the list, measured by the owner in the round that produced F26's
         * onboarding requirements: store added, list empty, a hunt for a button
         * whose existence is invisible. The run that adds the store now fetches the
         * catalogue in the same request, so the list is waiting when the page
         * paints again.
         */
        $pkg = 'eu.kanade.tachiyomi.extension.en.catalogue';
        $this->serveExtensions([$this->extension($pkg, 'Example Catalogue Row', false, true)]);
        $this->fetchRepliesWith = [$pkg];
        $this->catalogueArrivesAfterFetch = true;

        $this->page()->set('storeUrl', 'https://example.test/index.pb')->call('addStore');

        $this->assertSame(1, $this->fetchCount, 'adding a store must fetch the catalogue itself');

        $this->page()
            ->set('view', 'available')
            ->assertOk()
            ->assertSee('Example Catalogue Row');
    }

    public function test_trusting_recovers_a_catalogue_the_page_says_is_empty(): void
    {
        /*
         * The other shape the guest session measured: the store arrived earlier
         * (or its own fetch failed at add time), the catalogue is empty, and the
         * first action left to the reader is trust. Trust now fetches in that
         * case rather than toasting over nothing. The failed listing read is how
         * the empty shape is reached here, because an unread server and an empty
         * one lead the reader to the same wall.
         */
        $this->extensionListShouldFail = true;

        $store = $this->store();

        $this->page()->call('trust', $store->id);

        $this->assertSame(1, $this->fetchCount, 'trust on an empty catalogue must fetch it');

        $this->assertTrue(in_array($store->id, app(ExtensionManager::class)->trustedStoreIds($this->user), true));
    }

    public function test_trusting_does_not_refetch_a_catalogue_the_server_already_has(): void
    {
        /*
         * Every machine beside one just started already holds the list: this
         * checkout's reader trusted a community store on 2026-10-10 and its catalogue lives
         * on the server. Re-fetching over a thousand extension rows on every trust
         * spends upstream for nothing, so the skip is the contract, counted.
         */
        $store = $this->store();

        $this->page()->call('trust', $store->id);

        $this->assertSame(0, $this->fetchCount);
    }

    public function test_an_accepted_fetch_that_holds_nothing_yet_gets_a_line_saying_so(): void
    {
        /*
         * Measured live on 2026-10-17: trusting the store on a cold server ran
         * the fetch, the reply named zero extensions, and the same server held
         * 1,434 a minute later. Upstream answers fetchExtensions before its own
         * fetch has populated anything, so a zero reply is not an answer yet.
         * The load's own render riding on that zero reads as a silent failure,
         * so after a short wait and one fresh count read, one that still holds
         * nothing gets the line saying what is actually being waited for. The
         * fake models the cold shape: nothing held, an empty reply, no
         * population ever landing, and the line costs both these tests a real
         * wait each.
         */
        $this->nodes = [];
        $this->fetchRepliesWith = [];
        $this->catalogueArrivesAfterFetch = false;

        $store = $this->store();

        /*
         * The wait is itself the contract of the grace read: delete it and a
         * zero right off the reply would be judged when the population could
         * not have arrived, which is the shape the live run produced. A lower
         * bound is the one claim a blocking wait can make honestly, and this
         * test has read the clock twice only around its own real blocking
         * call.
         */
        $started = microtime(true);

        $html = $this->page()->call('trust', $store->id)->assertOk()->html();

        $this->assertGreaterThanOrEqual(1.9, microtime(true) - $started);

        $this->assertSame(1, substr_count(
            $html,
            'The catalogue was refreshed, but this store has no extensions to show yet.',
        ));
    }

    public function test_a_fetch_that_lands_during_the_wait_does_not_get_the_line(): void
    {
        /*
         * The other reading the live run produced: by the moment after the
         * reply, the server can already hold the catalogue, and a line about a
         * fetch still running over a list that exists would be the exact wrong
         * answer the note exists to avoid. This fake answers as the live
         * server behaved: nothing until the fetch is accepted, the rows
         * behind its next read.
         */
        $this->fetchRepliesWith = [];
        $this->catalogueArrivesAfterFetch = true;

        $store = $this->store();

        $html = $this->page()->call('trust', $store->id)->assertOk()->html();

        $this->assertSame(0, substr_count(
            $html,
            'The catalogue was refreshed, but this store has no extensions to show yet.',
        ));
    }

    public function test_a_note_from_an_earlier_load_retires_the_moment_the_list_is_real(): void
    {
        /*
         * The note is about the moment of the fetch, not a permanent banner:
         * the fetch populates the server behind Livewire's back, so a later
         * render must retire it by reading the server rather than by waiting
         * for an action to clear it. The property arrives here from an
         * earlier request, over a catalogue that now holds rows, and the page
         * must not argue with what the reader can see.
         */
        $this->assertSame(0, substr_count(
            $this->page()->set('autoloadStillEmpty', true)->assertOk()->html(),
            'The catalogue was refreshed, but this store has no extensions to show yet.',
        ));
    }

    public function test_a_note_from_an_earlier_load_still_stands_when_nothing_arrived(): void
    {
        /*
         * The retire-on-read contract cuts both ways: until the server holds
         * something there is no moment the page could name as the wait's end,
         * and a fresh count read that lands on nothing is the reading the
         * retire gate was built to consult.
         */
        $this->nodes = [];

        $this->assertSame(1, substr_count(
            $this->page()->set('autoloadStillEmpty', true)->assertOk()->html(),
            'The catalogue was refreshed, but this store has no extensions to show yet.',
        ));
    }

    public function test_a_failed_auto_load_says_what_failed_and_keeps_the_manual_path(): void
    {
        /*
         * The load runs in the same request as the action. When the server cannot
         * answer it, the store is still added and the reader still holds the trust
         * flow; the only honest thing to do is say what failed on the page, where
         * it survives, instead of in a toast that is gone in four seconds.
         */
        $this->fetchShouldFail = true;

        $this->page()
            ->set('storeUrl', 'https://example.test/index.pb')
            ->call('addStore')
            ->assertOk()
            ->assertSee('Papyrus could not load the catalogue.')
            ->assertSee('Refresh catalogue above to try again.')
            ->assertDontSee('Suwayomi returned HTTP 500');

        $this->assertSame(1, ExtensionStore::query()->count());
    }

    public function test_a_flagged_source_asks_before_it_installs(): void
    {
        /*
         * Owner, 2026-10-17: the press on one of those sources is where the warning
         * belongs, not only the card above it. The facts asked about are the ones the
         * config already tracks (the 403s, blocks, bans and scrambles), so the dialog
         * reads the card's own sentences rather than carrying a second copy. This test
         * pins the ask side: nothing is sent to the server until the reader answers.
         */
        $store = $this->store();
        $pkg = 'eu.kanade.tachiyomi.extension.en.kaynscans';

        $this->serveExtensions([$this->extension($pkg, 'Kayn Scans', false, true)]);

        $this->page()->call('trust', $store->id);

        $page = $this->page()
            ->set('view', 'available')
            ->call('install', $pkg);

        Http::assertNotSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'updateExtension'),
        );

        $this->assertSame(1, substr_count(
            $page->html(),
            'This source is tricky and risky',
        ));

        /*
         * The dialog is the one place the sentence renders now. The owner had the
         * notice block removed from the cards on 2026-10-17, because it warped the
         * layout next to it, so a second occurrence would mean the removal did not
         * take. One copy, and it is the dialog's, read from the config through
         * noticeFor().
         */
        $this->assertSame(1, substr_count(
            $page->html(),
            'This site is known to block readers that search aggressively.',
        ));
    }

    public function test_the_ask_confirms_by_installing_and_mirroring(): void
    {
        $store = $this->store();
        $pkg = 'eu.kanade.tachiyomi.extension.en.kaynscans';

        $this->serveExtensions([$this->extension($pkg, 'Kayn Scans', false, true)]);
        $this->serveSources([$this->source(4242, $pkg, 'Kayn Scans (EN)')]);

        $this->page()->call('trust', $store->id);

        /*
         * The confirm chains on the same instance, because the ask left its answer
         * pending in that component's state and a second instance mounts with
         * nothing pending (trap 284).
         */
        $page = $this->page()
            ->set('view', 'available')
            ->call('install', $pkg)
            ->call('installConfirmed')
            ->assertHasNoErrors();

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'updateExtension'),
        );

        $this->assertDatabaseHas('sources', [
            'suwayomi_source_id' => 4242,
            'extension_pkg_name' => $pkg,
            'is_installed' => true,
        ]);

        $page->assertSet('pendingInstall', []);
    }

    public function test_a_source_without_the_notice_installs_on_the_first_press(): void
    {
        /*
         * The gate at the press is for the sites the config names, and for nothing
         * else: a dialog on every install would teach the reader to click past
         * warnings, which is the exact cost the owner named for unsigned
         * installers. The existing mirror test pins that the install works; this
         * one pins that it works without the ask.
         */
        $store = $this->store();
        $pkg = 'eu.kanade.tachiyomi.extension.en.fresh';

        $this->serveExtensions([$this->extension($pkg, 'Fresh Scan', false, true)]);
        $this->serveSources([$this->source(4242, $pkg, 'Fresh Scan (EN)')]);

        $this->page()->call('trust', $store->id);

        $page = $this->page()
            ->set('view', 'available')
            ->call('install', $pkg)
            ->assertHasNoErrors();

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'updateExtension'),
        );

        $this->assertSame(0, substr_count($page->html(), 'This source is tricky and risky'));

        $page->assertSet('pendingInstall', []);
    }

    public function test_a_bulk_selection_with_one_flagged_name_asks_before_installing_anything(): void
    {
        /*
         * One ask for the whole selection, in the one modal for the page (the source
         * chooser's own reason in ⚡search.blade.php), listing the flagged names.
         * Confirmation installs the flagged ones along with the clean ones, because
         * that is the request the press stood for; cancelling leaves everything
         * uninstalled.
         */
        $store = $this->store();
        $flagged = 'eu.kanade.tachiyomi.extension.en.kaynscans';
        $clean = 'eu.kanade.tachiyomi.extension.en.fresh';

        $this->serveExtensions([
            $this->extension($flagged, 'Kayn Scans', false, true),
            $this->extension($clean, 'Fresh Scan', false, true),
        ]);
        $this->serveSources([
            $this->source(4242, $flagged, 'Kayn Scans (EN)'),
            $this->source(4243, $clean, 'Fresh Scan (EN)'),
        ]);

        $this->page()->call('trust', $store->id);

        $page = $this->page()
            ->set('view', 'available')
            ->set('selected', [$flagged, $clean])
            ->call('installSelected');

        Http::assertNotSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'updateExtension'),
        );

        /*
         * One flagged name in the selection, so the header reads singular, and the
         * clean companion is named as installing together with it rather than
         * silently.
         */
        $this->assertSame(1, substr_count($page->html(), 'This source is tricky and risky'));
        $this->assertSame(1, substr_count($page->html(), 'The install carries the 1 other selected extension with it.'));

        $page->assertSet('selected', []);

        $page->call('installConfirmed');

        $this->assertDatabaseHas('sources', [
            'suwayomi_source_id' => 4242,
            'extension_pkg_name' => $flagged,
            'is_installed' => true,
        ]);

        $this->assertDatabaseHas('sources', [
            'suwayomi_source_id' => 4243,
            'extension_pkg_name' => $clean,
            'is_installed' => true,
        ]);

        $page->assertSet('pendingInstall', []);
    }

    public function test_an_update_press_is_not_the_ask(): void
    {
        /*
         * The ask sits on the install of a site the config names. An update of an
         * already installed extension is a new signed artefact but not a first
         * contact with the site, so it keeps its single press, and this pins that
         * the update route never arms the warning.
         */
        $this->store();
        $pkg = 'eu.kanade.tachiyomi.extension.en.kaynscans';

        $this->serveExtensions([array_merge(
            $this->extension($pkg, 'Kayn Scans', true, true),
            ['hasUpdate' => true],
        )]);
        app(ExtensionManager::class)->trust($this->user, ExtensionStore::query()->first());

        $page = $this->page()->call('update', $pkg)->assertHasNoErrors();

        Http::assertSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'updateExtension'),
        );

        $page->assertSet('pendingInstall', []);
    }

    public function test_closing_the_warning_without_confirming_installs_nothing(): void
    {
        /*
         * Cancel, esc, backdrop and the x all land on closeInstallWarning. The
         * guarantee is the one the dialog promises: nothing installs on the way
         * out, the pending names are cleared, and a stale second confirm press
         * finds nothing to install.
         */
        $store = $this->store();
        $pkg = 'eu.kanade.tachiyomi.extension.en.kaynscans';

        $this->serveExtensions([$this->extension($pkg, 'Kayn Scans', false, true)]);

        $this->page()->call('trust', $store->id);

        $page = $this->page()
            ->set('view', 'available')
            ->call('install', $pkg)
            ->call('closeInstallWarning')
            ->assertHasNoErrors();

        Http::assertNotSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'updateExtension'),
        );

        $page->assertSet('pendingInstall', []);

        $page->call('installConfirmed');

        Http::assertNotSent(
            fn (Request $request): bool => str_contains($request->data()['query'] ?? '', 'updateExtension'),
        );
    }

    /*
     * The Migrate view: the sources page's third strip segment (IMG_1179), from
     * the one tally seam the move page's leaving list already reads, so the two
     * pages cannot disagree about what the reader holds.
     */

    public function test_the_migrate_view_lists_the_sources_holding_series_with_their_counts(): void
    {
        $this->mirrorSource('Harbor Comics', '1000000000000001');
        $this->mirrorSource('Beach House', '2000000000000002');
        $this->libraryNode(501, '1000000000000001', 'Alpha Road', 'Harbor Comics');
        $this->libraryNode(502, '1000000000000001', 'Beta Hill', 'Harbor Comics');
        $this->libraryNode(503, '2000000000000002', 'Gamma Cove', 'Beach House');

        $this->page()
            ->call('setView', 'migrate')
            ->assertSee('Harbor Comics')
            ->assertSee('Beach House')
            ->assertSee('2 series')
            ->assertSee('1 series')
            ->assertSee('The number is how many of your series come from that source.');
    }

    public function test_a_migrate_row_opens_the_compose_flow_for_its_source(): void
    {
        $this->mirrorSource('Harbor Comics', '1000000000000001');
        $this->libraryNode(501, '1000000000000001', 'Alpha Road', 'Harbor Comics');

        $this->page()
            ->call('setView', 'migrate')
            ->assertSee('/migrate/sources?compose=1000000000000001');
    }

    public function test_a_source_without_a_mirror_row_takes_the_name_its_series_carry(): void
    {
        $this->libraryNode(501, '9876543210987654', 'The Series Itself', 'Somewhere Else');

        $this->page()
            ->call('setView', 'migrate')
            ->assertSee('Somewhere Else');
    }

    public function test_a_source_the_lens_cannot_name_is_called_an_unnamed_source(): void
    {
        /*
         * Step 0's verdict. The row is labelled rather than presenting a numeral
         * as a name, and the id stays in the row's detail, where a reader who
         * wants it can still find it.
         */
        $this->libraryNode(501, '9876543210987654', 'The Series Itself', '');

        $this->page()
            ->call('setView', 'migrate')
            ->assertSee('An unnamed source')
            ->assertSee('9876543210987654');
    }

    /**
     * The plan's own named test: the catalogue's filters, count and empty state
     * belong to the extensions views, and none of them may surface inside the
     * Migrate view's rendering.
     */
    public function test_the_catalogue_view_s_state_does_not_leak_into_the_migrate_view(): void
    {
        /*
         * The first needle renders nowhere but the Migrate view's own branch, so
         * a catalogue that leaks across a view boundary dies here, and the two
         * don't-see needles that follow are the catalogue's failure state, which
         * visibly renders on the catalogue view this test leaves behind.
         */
        $this->extensionListShouldFail = true;
        $this->mirrorSource('Harbor Comics', '1000000000000001');
        $this->libraryNode(501, '1000000000000001', 'Alpha Road', 'Harbor Comics');

        $this->page()
            ->set('name', 'Bogus Nonexistent')
            ->set('content', [Source::NSFW])
            ->call('setView', 'migrate')
            ->assertSee('The number is how many of your series come from that source.')
            ->assertDontSee('The extension list did not load')
            ->assertDontSee('Nothing installed yet');
    }

    public function test_the_migrate_view_says_what_it_is_when_nothing_is_held(): void
    {
        $this->page()
            ->call('setView', 'migrate')
            ->assertSee('Nothing to move yet')
            ->assertSee('Your library holds no series on this server yet, so there is nothing to move.');
    }

    /**
     * The F26 shape, one view over: a server that will not answer is a state on
     * the page, never a 500 and never an empty list over an unreadable library.
     *
     * The reader has to hold something for the failure to be reachable: with no
     * holding rows at all, the lens returns before any network call, so a fake
     * failure would never be met and the test would pass against a page that
     * truthfully rendered its empty state instead (the shape trap 267 names).
     */
    public function test_the_migrate_view_renders_when_the_library_cannot_be_read(): void
    {
        $this->libraryNode(501, '9876543210987654', 'The Series Itself', '');

        $this->libraryShouldFail = true;

        $this->page()
            ->call('setView', 'migrate')
            ->assertSee('The library could not be read')
            ->assertSee('Suwayomi returned HTTP 500.')
            ->assertDontSee('Nothing to move yet');
    }

    public function test_the_conduct_sentence_has_no_home_on_the_card(): void
    {
        /*
         * Owner, 2026-10-17, on the notice block this card used to carry: it
         * should be removed, it warps everything else close to it, and that is
         * what the UI/UX sweep is for. The press carries the sentence now, in
         * the install warning (the ask test pins that), so the card holds none.
         * The page is asserted to have rendered the card itself as well: an
         * empty page would pass an absence check too, and that is not the page
         * this pins. The matcher's own verdicts are pinned at their seam,
         * SourceNotices::kind(), in the two tests below.
         */
        $this->serveExtensions([
            $this->extension('eu.kanade.tachiyomi.extension.en.kaynscans', 'Kayn Scans', false, true),
            $this->extension('eu.kanade.tachiyomi.extension.en.comix', 'Comix', false, true),
            $this->extension('eu.kanade.tachiyomi.extension.all.mangafire', 'MangaFire', false, true),
        ]);

        $page = $this->page()
            ->set('view', 'available')
            ->assertOk()
            ->assertSee('Kayn Scans');

        $html = $page->html();

        $this->assertSame(0, substr_count($html, 'This site is known to block readers that search aggressively.'));
        $this->assertSame(0, substr_count($html, 'Searches from extensions here come back scrambled'));
    }

    public function test_a_name_merely_containing_comix_is_not_the_comix_the_evidence_names(): void
    {
        /*
         * Measured live on 2026-10-17: the catalogue carries 8 extension names
         * containing "comix" from several different publishers (GlobalComix,
         * ArbxComix, Comix, GEDE Comix, ILikeComix, KingComiX, PornComix and
         * ComixTopia), and the session before this one had shown the scramble
         * notice on all of them. The two upstream issues that corroborate the
         * class, extension-source 12130 and 16684, name exactly one
         * extension, Comix (EN). An accusation of a site's conduct must not
         * ride on a substring, and these two real shapes pin the match against
         * a drift back to substring or word-boundary matching: "GEDE Comix"
         * would survive a word-boundary matcher, and only exact matching
         * refuses both of these. Read at the matcher itself, since the cards
         * no longer render the notice (owner, 2026-10-17); the positive is in
         * the same breath so the refusals cannot pass while the matcher
         * matches nothing at all.
         */
        $this->assertSame(SourceNotices::SCRAMBLE, SourceNotices::kind('Comix'));
        $this->assertNull(SourceNotices::kind('GlobalComix'));
        $this->assertNull(SourceNotices::kind('GEDE Comix'));
    }

    public function test_the_named_site_keeps_a_qualifier_suffix_name(): void
    {
        /*
         * A store may append the language family into the name a reader
         * recognises, and that shape is legitimately the named site: "Comix
         * (all)" is still Comix. This is why the match is exact on the name
         * and then tolerant of one parenthetical qualifier, rather than
         * substring. Read at the matcher itself, since the cards no longer
         * render the notice (owner, 2026-10-17).
         */
        $this->assertSame(SourceNotices::SCRAMBLE, SourceNotices::kind('Comix (all)'));
    }

    /**
     * The card is where the count of variants is already on screen, which is why
     * the pin control can live there: a button that affects 61 sources is being
     * shown a reader who can read "61 sources" on the face they pressed.
     *
     * The rows are written directly rather than through the mirror helper,
     * because the card keys on the home_url's bare host and this fixture needs a
     * URL that parses as one. The press passes what the card's wire:click sends:
     * that key, not the raw home_url, which is the distinction the first red run
     * surfaced.
     */
    public function test_pinning_a_site_card_marks_the_site_for_this_reader_only(): void
    {
        $this->cardSource('Example EN', 8513894820944573933);
        $this->cardSource('Example JA', 8513894820944573934);
        $bob = User::factory()->create();

        $this->page()->call('pinSite', 'example.test');

        $this->assertSame(2, UserSource::forUser($this->user->id)->whereNotNull('pinned_at')->count());
        $this->assertSame(0, UserSource::forUser($bob->id)->whereNotNull('pinned_at')->count());
    }

    public function test_unpinning_a_site_card_returns_the_search_to_every_runnable_source(): void
    {
        $this->cardSource('Example EN', 8513894820944573933);
        $this->cardSource('Example JA', 8513894820944573934);

        $this->page()->call('pinSite', 'example.test')->call('unpinSite', 'example.test');

        $this->assertSame(
            0,
            UserSource::forUser($this->user->id)->whereNotNull('pinned_at')->count(),
            'Unpinning the last pinned site must leave no pin behind.',
        );
    }

    public function test_a_pinned_site_card_says_so(): void
    {
        $this->cardSource('Example EN', 8513894820944573933);

        $this->page()->call('pinSite', 'example.test')
            ->assertSee('pinned by you')
            ->call('unpinSite', 'example.test')
            ->assertDontSee('pinned by you');
    }

    // The language selector --------------------------------------------------

    /**
     * The catalogue the language tests read, one language per extension.
     *
     * Two of each language, so a pill's count is a number and not a one, and
     * the names carry the language so an assertion about the list can tell
     * one extension from another.
     *
     * @return array<int, array<string, mixed>>
     */
    private function languageNodes(): array
    {
        return [
            $this->extension('eu.kanade.tachiyomi.extension.en.one', 'English One', false, true, 'en'),
            $this->extension('eu.kanade.tachiyomi.extension.en.two', 'English Two', false, true, 'en'),
            $this->extension('eu.kanade.tachiyomi.extension.ja.one', 'Japanese One', false, true, 'ja'),
            $this->extension('eu.kanade.tachiyomi.extension.ja.two', 'Japanese Two', false, true, 'ja'),
            $this->extension('eu.kanade.tachiyomi.extension.fr.one', 'French One', false, true, 'fr'),
            $this->extension('eu.kanade.tachiyomi.extension.fr.two', 'French Two', false, true, 'fr'),
        ];
    }

    /**
     * The catalogue's name filter is named.
     *
     * A placeholder is not a name: it goes the moment the reader types, and a
     * screen reader announces a control with no name as an edit box. The two
     * words are the ones the box already shows, so the name and the visible
     * text agree.
     */
    public function test_the_catalogue_filter_is_named(): void
    {
        $this->store();

        $this->page()->set('view', 'available')
            ->assertSee('aria-label="Filter by name"', false);
    }

    public function test_source_family_flags_link_directly_to_each_source(): void
    {
        $sources = [];

        foreach ([
            ['EN', 'en', 'gb'],
            ['ES', 'es', 'es'],
            ['ES-419', 'es-419', 'mx'],
            ['FR', 'fr', 'fr'],
            ['JA', 'ja', 'jp'],
            ['PT', 'pt', 'pt'],
            ['PT-BR', 'pt-BR', 'br'],
        ] as $index => [$suffix, $language, $flag]) {
            $sources[] = [
                'source' => $this->cardSource('MangaFire ('.$suffix.')', 1000 + $index, $language),
                'flag' => $flag,
            ];
        }

        $html = $this->page()->html();

        $this->assertSame(1, preg_match('/<li\b[^>]*data-test="source-family-card"[^>]*>(.*?)<\/li>/s', $html, $match));
        $card = $match[1];
        $this->assertStringNotContainsString('<details', $card);
        $this->assertStringContainsString('7 sources', $card);

        foreach ($sources as $item) {
            $this->assertStringContainsString('language-flag language-flag-'.$item['flag'], $card);
            $this->assertStringContainsString(route('browse', ['source' => $item['source']->id]), $card);
        }
    }

    /**
     * A site with more variants than a card can hold shows the rest as one way in.
     *
     * MangaDot has 108 language variants, and a card that rendered every flag on one
     * line clipped the row and left the last ones invisible. Twelve flags are what the
     * card holds, and the count of the rest points at the health page's own site
     * drilldown instead of implying that the card holds every flag.
     */
    public function test_a_site_with_many_variants_shows_twelve_flags_and_a_health_link(): void
    {
        $variants = [];

        for ($index = 0; $index < 108; $index++) {
            $name = $index === 0
                ? 'MangaDot (EN)'
                : 'MangaDot (EN) '.str_pad((string) $index, 3, '0', STR_PAD_LEFT);

            $variants[] = $this->cardSource($name, 7000 + $index);
        }

        $html = $this->page()->html();

        $this->assertSame(1, preg_match('/<li\b[^>]*data-test="source-family-card"[^>]*>(.*?)<\/li>/s', $html, $match));

        $card = $match[1];

        $this->assertSame(12, substr_count($card, 'data-source-language='));
        $this->assertSame(1, substr_count($card, '+96'));
        $this->assertStringContainsString(route('health', ['site' => 'https://example.test']), $card);
        $this->assertStringContainsString('aria-label="Open source health for MangaDot"', $card);
        $this->assertStringContainsString(route('browse', ['source' => $variants[11]->id]), $card);
        $this->assertStringNotContainsString(route('browse', ['source' => $variants[12]->id]), $card);
    }

    /**
     * The tick that selects an extension for a bulk install is named.
     *
     * One tick per card, so a card that carried no name was a screen reader
     * hearing "check box" over and over with nothing to tell them apart.
     */
    public function test_the_install_tick_is_named(): void
    {
        $this->serveExtensions($this->languageNodes());
        $this->store();

        $this->page()->set('view', 'available')
            ->assertSee('aria-label="Select English One to install"', false)
            ->assertSee('aria-label="Select Japanese One to install"', false);
    }

    /**
     * The card's language badge says the language the pill says.
     *
     * The two are read on the same screen, so a card saying "en" and a pill
     * saying "English" would be two different-looking languages for the same
     * thing. The catalogue's own code stays on the badge's title.
     */
    public function test_the_card_names_the_language_the_pill_names(): void
    {
        $this->serveExtensions([
            $this->extension('eu.kanade.tachiyomi.extension.pt.br.one', 'Brazil One', false, true, 'pt-BR'),
        ]);

        $this->store();

        $this->page()->set('view', 'available')
            ->assertSee('Portuguese (Brazil)')
            ->assertSee('title="pt-BR"', false);
    }

    /**
     * The pills name every language the catalogue holds, and say how many
     * sources are in each.
     *
     * The count is the number of extensions carrying that language under the
     * filters the reader can see, and it is what makes the row worth reading:
     * a language with two sources in it and a language with three hundred are
     * not the same thing to a reader deciding where to look.
     */
    public function test_the_language_pills_name_every_language_the_catalogue_holds(): void
    {
        $this->serveExtensions($this->languageNodes());
        $this->store();

        $this->page()->set('view', 'available')
            ->set('showAllLanguages', true)
            ->assertSee('English, 2 sources')
            ->assertSee('Japanese, 2 sources')
            ->assertSee('French, 2 sources');
    }

    /**
     * The pill says the language by name and not by its code.
     *
     * The code is the catalogue's own and it stays on the pill's title, but
     * the name is what a reader thinks in, and the map that names language
     * codes is the one place in this repository that knows them. The code
     * chosen here is one whose name and code say different things, because
     * "English" and "en" would pass even with the two swapped.
     */
    public function test_the_language_pill_says_the_language_by_name(): void
    {
        $this->serveExtensions([
            $this->extension('eu.kanade.tachiyomi.extension.pt.br.one', 'Brazil One', false, true, 'pt-BR'),
        ]);

        $this->store();

        $this->page()->set('view', 'available')
            ->assertSee('Portuguese (Brazil)')
            ->assertDontSee('>pt-BR<', false);
    }

    /**
     * Switching a language off leaves its sources out of the list.
     *
     * The claim is the owner's: "uncheck a language and its sources leave the
     * installable list, check it and they return". The list below the pills
     * is the list the server sends, so the fake has to honour the filter for
     * this to mean anything, and it does.
     */
    public function test_switching_a_language_off_leaves_its_sources_out_of_the_list(): void
    {
        $this->serveExtensions($this->languageNodes());
        $this->store();

        $this->page()->set('view', 'available')
            ->assertSee('French One')
            ->call('toggleLanguage', 'fr')
            ->assertDontSee('French One')
            ->assertSee('English One')
            ->assertSet('lang', ['fr']);
    }

    /**
     * Switching it back on puts them back.
     */
    public function test_switching_a_language_back_on_puts_its_sources_back(): void
    {
        $this->serveExtensions($this->languageNodes());
        $this->store();

        $this->page()->set('view', 'available')
            ->call('toggleLanguage', 'fr')
            ->assertDontSee('French One')
            ->call('toggleLanguage', 'fr')
            ->assertSee('French One')
            ->assertSet('lang', []);
    }

    /**
     * The choice survives a reload.
     *
     * The reader came back to the page and the language they had switched off
     * is still switched off. The state is in the URL, the same way the
     * catalogue's slice and the content filter are, and this is the test that
     * says so rather than leaving it to the reader to find out.
     */
    public function test_the_language_choice_survives_a_reload(): void
    {
        $this->serveExtensions($this->languageNodes());
        $this->store();

        Livewire::withQueryParams(['view' => 'available', 'lang' => ['fr']])
            ->test('pages::sources')
            ->assertSet('lang', ['fr'])
            ->assertDontSee('French One')
            ->assertSee('English One');
    }

    /**
     * The filter reaches the server, in the shape the server understands.
     *
     * The whole claim of the selector is that the list it shows is the list
     * the server would send, and the server's own field for a language is a
     * `notIn`, so that is what goes over the wire. A page that narrowed its
     * own fifty rows would look the same on screen and be a different thing.
     */
    public function test_the_language_filter_is_sent_to_the_server(): void
    {
        $this->serveExtensions($this->languageNodes());
        $this->store();

        $this->page()->set('view', 'available')->call('toggleLanguage', 'fr');

        Http::assertSent(function (Request $request): bool {
            $query = $request->data()['query'] ?? '';
            $variables = (array) ($request->data()['variables'] ?? []);

            if (! str_contains($query, 'extensions(')) {
                return false;
            }

            $and = $variables['filter']['and'] ?? [];

            return in_array(['lang' => ['notIn' => ['fr']]], $and, true);
        });
    }

    /**
     * The count under the list and the list itself read the same number.
     *
     * The page's count comes from the server's own `totalCount` over the same
     * filter the rows came from, so a reader who switches a language off sees
     * the count move with the rows rather than sit at the old number.
     */
    public function test_the_count_line_reads_the_same_number_as_the_list(): void
    {
        $this->serveExtensions($this->languageNodes());
        $this->store();

        $this->page()->set('view', 'available')
            ->assertSee('6 extensions')
            ->call('toggleLanguage', 'fr')
            ->assertSee('4 extensions');
    }

    /**
     * A long row compacts itself and offers a way to show the whole list.
     */
    public function test_the_language_row_compacts_and_can_expand(): void
    {
        $this->serveExtensions($this->languageNodes());
        $this->store();

        $this->page()->set('view', 'available')
            ->assertSee('Show all languages')
            ->assertSee('overflow-x-auto')
            ->call('toggleLanguageRow')
            ->assertSet('showAllLanguages', true)
            ->assertSee('Show fewer languages');
    }

    /**
     * The language strip compacts to two and the toggle is always on screen.
     *
     * The strip is a horizontal scroll when collapsed so the catalogue stays
     * compact, and the show-all toggle is visible at every width rather than
     * only on narrow screens.
     */
    public function test_every_language_is_rendered_and_the_show_all_control_is_mobile_only(): void
    {
        $this->serveExtensions($this->languageNodes());
        $this->store();

        $html = $this->page()->set('view', 'available')->html();

        $this->assertStringContainsString('data-test="language-strip"', $html);
        $this->assertStringContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('Show all languages', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<div[^>]*class="[^"]*\blg:hidden\b[^"]*"[^>]*>\s*<button[^>]*wire:click="toggleLanguageRow"/s',
            $html,
        );

        $this->page()->set('view', 'available')->call('toggleLanguageRow')->assertSee('Show fewer languages');
    }

    /**
     * A site card carries a quick health read so a dead source is obvious before
     * the reader opens the health page.
     */
    public function test_the_site_cards_report_source_health(): void
    {
        $this->cardSource('Example EN', 8513894820944573933);

        SourceHealth::query()->create([
            'source_id' => 8513894820944573933,
            'name' => 'Example EN',
            'lang' => 'en',
            'consecutive_failures' => 5,
            'total_successes' => 0,
            'total_failures' => 5,
            'is_dead' => true,
        ]);

        $this->page()->set('view', 'available')
            ->assertSee('Not answering');
    }

    /**
     * The healthy pill uses the same moss token as the Health page.
     *
     * The card said Healthy in emerald while the Health page's own healthy state is
     * moss, so the same state carried two different greens on two pages. The token is
     * the one place that colour is defined, so the card names it rather than carrying
     * a second green of its own.
     */
    public function test_the_healthy_site_pill_uses_the_moss_token(): void
    {
        $this->cardSource('Example EN', 8513894820944573934);

        SourceHealth::query()->create([
            'source_id' => 8513894820944573934,
            'name' => 'Example EN',
            'lang' => 'en',
            'consecutive_failures' => 0,
            'total_successes' => 1,
            'total_failures' => 0,
            'is_dead' => false,
        ]);

        $html = $this->page()->set('view', 'available')->html();

        $this->assertSame(1, preg_match('/<li\b[^>]*data-test="source-family-card"[^>]*>(.*?)<\/li>/s', $html, $match));

        $card = $match[1];

        $this->assertStringContainsString('Healthy', $card);
        $this->assertStringContainsString('bg-moss-600', $card);
        $this->assertStringContainsString('dark:bg-moss-500', $card);
        $this->assertStringNotContainsString('bg-emerald-600', $card);
    }

    /**
     * "Show none" and "Show every language" are the two ends of the row.
     *
     * Switching twenty-two languages off one at a time is twenty-two round
     * trips and twenty-two renders of a list of a thousand rows, and a reader
     * who reads two languages should not pay for that. Both ends are one
     * press, and the list reads as empty when every language is left out,
     * which is what "none" means.
     */
    public function test_showing_no_language_leaves_the_list_empty_and_showing_every_language_puts_it_back(): void
    {
        $this->serveExtensions($this->languageNodes());
        $this->store();

        $this->page()->set('view', 'available')
            ->call('showNoLanguage')
            ->assertSet('lang', ['en', 'fr', 'ja'])
            ->assertDontSee('English One')
            ->assertSee('0 extensions')
            ->assertSee('Every language is switched off')
            ->call('showEveryLanguage')
            ->assertSet('lang', [])
            ->assertSee('English One')
            ->assertSee('6 extensions');
    }

    /**
     * A site without a home_url lands on the health page's own bucket for it.
     *
     * The health page keys a site by the raw `home_url` string and spells the
     * no-URL bucket `unknown`, while the sources cards key on the bare host and
     * spell the same rows `local`. Sending the card's key therefore reached a
     * site the ledger does not have, and the link opened nothing.
     */
    public function test_a_source_without_a_home_url_links_to_the_unknown_health_bucket(): void
    {
        for ($index = 0; $index < 13; $index++) {
            $this->cardSource(
                'Local '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                7100 + $index,
                'en',
                $index % 2 === 0 ? null : '',
            );
        }

        $html = $this->page()->html();

        $this->assertSame(1, preg_match('/<li\b[^>]*data-test="source-family-card"[^>]*>(.*?)<\/li>/s', $html, $match));

        $card = $match[1];

        $this->assertStringContainsString(route('health', ['site' => 'unknown']), $card);
        $this->assertStringNotContainsString(route('health', ['site' => 'local']), $card);
    }

    /**
     * The empty Available slot says a load is in flight, and nothing else.
     *
     * The automatic load runs inside the action that starts it, so the response
     * that carries the progress text is also the response that carries the
     * catalogue. The string therefore rides Livewire's own loading state for
     * the same actions rather than a timer, a second request, or a number the
     * page cannot know yet. The filled catalogue has no empty slot for it.
     */
    public function test_the_available_empty_state_reports_an_in_flight_catalogue_load(): void
    {
        $this->store();
        $this->nodes = [];

        $html = $this->page()->set('view', 'available')->html();

        $this->assertStringContainsString('Loading the catalogue...', $html);
        $this->assertStringContainsString('wire:target="addStore,trust,refresh"', $html);
        $this->assertMatchesRegularExpression(
            '/<div[^>]*wire:loading(?:\s|>)[^>]*wire:target="addStore,trust,refresh"/s',
            $html,
        );

        $this->serveExtensions($this->defaultNodes());

        $this->page()->set('view', 'available')->assertDontSee('Loading the catalogue...');
    }

    /**
     * One mirrored source row behind a real-shaped site card.
     */
    private function cardSource(string $name, int $id, ?string $lang = null, ?string $homeUrl = 'https://example.test'): Source
    {
        return Source::create([
            'suwayomi_source_id' => $id,
            'name' => $name,
            'display_name' => $name,
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.en.example',
            'lang' => $lang ?? (str_contains($name, 'JA') ? 'ja' : 'en'),
            'home_url' => $homeUrl,
            'content_warning' => Source::SAFE,
            'is_installed' => true,
            'supports_latest' => true,
        ]);
    }

    /*
     * The adult content gate, on the catalogue side.
     *
     * The measurement that sent this work, read in a browser on 2026-10-21:
     * with `?content[]=NSFW` in the address the Available tab listed 874
     * extensions and 41 of the fifty cards on its first page carried an NSFW
     * badge, with nothing asked and the page still claiming they were hidden.
     * The tests below hold the fix for that, and each one names the half of
     * the claim it is holding.
     */

    /**
     * The catalogue's own nodes for the gate tests: one safe, one mixed and one
     * adult rated, none of them installed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ratedNodes(): array
    {
        return [
            $this->extension('eu.kanade.tachiyomi.extension.en.safe', 'Safe One', false, true, 'en', Source::SAFE),
            $this->extension('eu.kanade.tachiyomi.extension.en.mixed', 'Mixed One', false, true, 'en', Source::MIXED),
            $this->extension('eu.kanade.tachiyomi.extension.en.adult', 'Adult One', false, true, 'en', Source::NSFW),
        ];
    }

    public function test_the_catalogue_hides_the_adult_rated_extensions_while_the_gate_is_off(): void
    {
        $this->serveExtensions($this->ratedNodes());

        $this->page()->set('view', 'available')
            ->assertDontSee('Adult One')
            ->assertSee('Safe One')
            ->assertSee('2 extensions');
    }

    public function test_the_address_bar_cannot_open_the_gate(): void
    {
        $this->serveExtensions($this->ratedNodes());

        /*
         * The address bar, as a link would deliver it.
         *
         * `withQueryParams` is the framework's own way of mounting a component
         * with a query string, so the page hydrates from the address the way a
         * reader following a link would, rather than the test setting the
         * property behind the page's back. That matters here because the
         * defect was in the hydration: Livewire merges the array in the
         * address over the declared one by key, so `content[]=NSFW` replaced
         * SAFE with NSFW and the catalogue answered with every adult rated
         * extension in the store.
         */
        $page = Livewire::actingAs($this->user)
            ->withQueryParams([
                'view' => 'available',
                'content' => ['NSFW', 'MIXED'],
            ])
            ->test('pages::sources');

        $page->assertDontSee('Adult One')
            ->assertSee('Adult content off')
            ->assertSee('Nothing marked NSFW is listed or searched.');
    }

    public function test_the_adult_rating_is_not_offered_as_a_choice_while_the_gate_is_off(): void
    {
        $this->serveExtensions($this->ratedNodes());

        $this->page()->set('view', 'available')
            ->assertDontSee('value="NSFW"', escape: false)
            ->assertSee('Adult content off')
            ->assertSee('Nothing marked NSFW is listed or searched.')
            ->assertSee('Turn on adult content?');
    }

    public function test_the_confirmation_states_the_age_ask(): void
    {
        $this->serveExtensions($this->ratedNodes());

        $this->page()->set('view', 'available')
            ->assertSee('Turn on adult content?')
            ->assertSee('18 or older')
            ->assertSee('I am 18 or older, turn it on');
    }

    /**
     * The gate's state is a label, and the button is the only action.
     *
     * Both states used to be a badge sitting beside the button, so one decision read
     * as two things to press. The component is rendered on its own because the page
     * carries other badges for source counts, and this asserts on the gate alone.
     */
    public function test_the_adult_gate_status_is_a_label_and_not_a_second_action(): void
    {
        $off = Blade::render('<x-adult-content.gate :enabled="false" />');

        $this->assertStringContainsString('Adult content off', $off);
        $this->assertStringNotContainsString('data-flux-badge', $off);
        $this->assertStringContainsString('Turn it on', $off);
        $this->assertStringContainsString('Turn on adult content?', $off);
        $this->assertStringContainsString('18 or older', $off);

        $on = Blade::render('<x-adult-content.gate :enabled="true" />');

        $this->assertStringContainsString('Adult content on', $on);
        $this->assertStringNotContainsString('data-flux-badge', $on);
        $this->assertStringContainsString('Turn it off', $on);
    }

    public function test_confirming_the_gate_opens_the_adult_rated_extensions(): void
    {
        $this->serveExtensions($this->ratedNodes());

        $this->page()->set('view', 'available')
            ->assertDontSee('Adult One')
            ->call('confirmAdultContent');

        /*
         * A fresh instance for the read, and this is the load-bearing part.
         *
         * `catalogue()` is a computed, and the instance that ran the confirmation
         * cached it before the setting was written: it was rendered with the gate
         * shut and would answer with the adult rated extension missing for ever
         * after. Asserting on the same instance therefore proves nothing about
         * the page, and asserting on the rendered HTML would fail for the same
         * reason.
         *
         * In a browser this is invisible, because the response to the click is a
         * fresh render and the computed's cache is cleared between requests. The
         * test has to reproduce that by asking a new instance, which is what a
         * page load does.
         *
         * The rating rides in the filter as well as the gate, so the fresh
         * instance is given it: that is what the confirmation does, and a page
         * load carries it in the address.
         */
        $page = Livewire::actingAs($this->user)
            ->withQueryParams([
                'view' => 'available',
                'content' => [Source::SAFE, Source::MIXED, Source::NSFW],
            ])
            ->test('pages::sources');

        $page->assertSee('Adult One')
            ->assertSee('Adult content on')
            ->assertSee('3 extensions');
    }

    public function test_the_gate_is_per_reader(): void
    {
        $this->serveExtensions($this->ratedNodes());

        $other = User::factory()->create();

        $this->page()->set('view', 'available')
            ->call('confirmAdultContent');

        $this->assertTrue(app(AdultContent::class)->enabled($this->user));
        $this->assertFalse(app(AdultContent::class)->enabled($other));
    }

    public function test_turning_the_gate_off_puts_the_rating_back_out_of_the_address(): void
    {
        $this->serveExtensions($this->ratedNodes());

        $this->page()->set('view', 'available')
            ->call('confirmAdultContent')
            ->set('content', [Source::SAFE, Source::NSFW])
            ->call('turnAdultContentOff')
            ->assertSet('content', [Source::SAFE]);

        // A fresh instance for the read, for the reason named above.
        $page = $this->page()->set('view', 'available');

        $page->assertDontSee('Adult One')
            ->assertSee('Adult content off');
    }

    public function test_the_installed_slice_hides_an_adult_rated_extension_and_names_it(): void
    {
        $this->serveExtensions([
            $this->extension('eu.kanade.tachiyomi.extension.en.safe', 'Safe One', true, true, 'en', Source::SAFE),
            $this->extension('eu.kanade.tachiyomi.extension.en.adult', 'Adult One', true, true, 'en', Source::NSFW),
        ]);

        $this->page()->set('view', 'installed')
            ->assertDontSee('Adult One')
            ->assertSee('Safe One')
            ->assertSee('1 extension')
            ->assertSee('1 of the installed extensions is marked NSFW and is hidden.');
    }

    public function test_the_installed_slice_lists_the_adult_rated_extension_once_the_gate_is_on(): void
    {
        $this->serveExtensions([
            $this->extension('eu.kanade.tachiyomi.extension.en.safe', 'Safe One', true, true, 'en', Source::SAFE),
            $this->extension('eu.kanade.tachiyomi.extension.en.adult', 'Adult One', true, true, 'en', Source::NSFW),
        ]);

        $this->page()->set('view', 'installed')
            ->call('confirmAdultContent');

        // A fresh instance for the read, for the reason named above.
        $page = $this->page()->set('view', 'installed');

        $page->assertSee('Adult One')
            ->assertSee('2 extensions')
            ->assertDontSee('is marked NSFW and is hidden');
    }

    public function test_an_installed_extension_that_is_only_adult_rated_does_not_read_as_nothing_installed(): void
    {
        $this->store();

        $this->serveExtensions([
            $this->extension('eu.kanade.tachiyomi.extension.en.adult', 'Adult One', true, true, 'en', Source::NSFW),
        ]);

        $this->page()->set('view', 'installed')
            ->assertDontSee('Nothing installed yet')
            ->assertSee('The 1 extension installed is marked NSFW and is hidden until you turn adult content on.');
    }
}
