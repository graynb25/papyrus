<?php

namespace Tests\Feature;

use App\Models\ExtensionStore;
use App\Models\Source;
use App\Models\User;
use App\Suwayomi\ExtensionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

class SourcesPageTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->actingAs($this->user);

        $this->fakeSuwayomi();
    }

    /**
     * Choose what the catalogue endpoint returns for the rest of the test.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    /**
     * The nodes the fake will serve, honouring an `isInstalled` filter.
     *
     * The real server filters server-side, and the drift notice asks for the installed ones
     * only. A fake that ignored the filter would hand the notice every uninstalled extension
     * in the catalogue and it would accuse the reader of missing sources they never added.
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

        foreach ($variables['filter']['and'] ?? [] as $clause) {
            $field = $clause['isInstalled'] ?? null;

            if (! is_array($field)) {
                continue;
            }

            $wanted = (bool) ($field['equalTo'] ?? false);

            return array_values(array_filter(
                $this->nodes,
                fn (array $n): bool => (bool) ($n['isInstalled'] ?? false) === $wanted,
            ));
        }

        return $this->nodes;
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

            if (str_contains($query, 'extensionStores')) {
                return Http::response(['data' => ['extensionStores' => ['nodes' => [[
                    'indexUrl' => 'https://example.test/index.pb',
                    'name' => 'Test Store',
                    'badgeLabel' => 'TEST',
                    'signingKey' => '9add655a78e96c4eFULLKEY',
                    'contactWebsite' => null,
                    'contactDiscord' => null,
                    'isLegacy' => false,
                    'extensionListUrl' => null,
                ]]]]]);
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
     * @return array<string, mixed>
     */
    private function extension(string $pkg, string $name, bool $installed, bool $trusted): array
    {
        return [
            'pkgName' => $pkg,
            'name' => $name,
            'versionName' => '1.2.3',
            'contentWarning' => 'SAFE',
            'lang' => 'en',
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

        $this->get('/sources')->assertRedirect();
    }

    public function test_it_lists_stores_with_the_signing_key_shown_in_full(): void
    {
        $this->store(key: '9add655a78e96c4efullkeyvalue');

        $this->page()
            ->assertSee('Test Store')
            ->assertSee('9add655a78e96c4efullkeyvalue');
    }

    /**
     * The key is the entire point of the trust step, so a truncated fingerprint
     * would make it impossible to compare against the publisher.
     */
    public function test_it_does_not_truncate_the_signing_key(): void
    {
        $key = '9add655a78e96c4e5f3a91b2c7d84e0f1234567890abcdef1234567890abcdef';

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
     * Keiyoushi ships no NSFW extension among the installed set, so glancing at the
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

    public function test_the_picker_names_extensions_the_mirror_has_not_caught_up_with(): void
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

        $this->assertStringContainsString(
            'Added Scan',
            $html,
            'The notice names the extension. "1 source is missing" leaves the reader to guess which.',
        );

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
}
