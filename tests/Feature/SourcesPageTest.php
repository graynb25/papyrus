<?php

namespace Tests\Feature;

use App\Models\ExtensionStore;
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
    private function serveExtensions(array $nodes): void
    {
        $this->nodes = $nodes !== [] ? $nodes : $this->defaultNodes();
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

            if (str_contains($query, 'extensions(')) {
                return Http::response(['data' => ['extensions' => [
                    'totalCount' => count($this->nodes),
                    'nodes' => $this->nodes,
                ]]]);
            }

            if (str_contains($query, 'updateExtension')) {
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
}
