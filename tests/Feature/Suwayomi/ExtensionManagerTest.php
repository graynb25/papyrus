<?php

namespace Tests\Feature\Suwayomi;

use App\Exceptions\MissingOwnerException;
use App\Models\ExtensionStore;
use App\Models\User;
use App\Models\UserExtensionStore;
use App\Suwayomi\ExtensionManager;
use App\Suwayomi\SourceCatalog;
use App\Suwayomi\StoreHostResolver;
use App\Suwayomi\SuwayomiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStoreHostResolver;
use Tests\Support\MintsTokens;
use Tests\TestCase;

class ExtensionManagerTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    /**
     * Captures the GraphQL documents that go out so a test can assert on the
     * shape of the filter, which is the part that matters here: narrowing 1,379
     * extensions has to happen in Suwayomi, not in PHP after the fact.
     *
     * @var array<int, array{query: string, variables: array<string, mixed>}>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];

        /*
         * The store address tests here use names that must not depend on this machine's
         * resolver. Empty means "no answer", which the policy leaves to the server.
         */
        $this->app->instance(StoreHostResolver::class, new FakeStoreHostResolver);
    }

    private function fakeSuwayomi(array $overrides = []): void
    {
        Http::fake(function (Request $request) use ($overrides) {
            $query = $request->data()['query'] ?? '';
            $variables = (array) ($request->data()['variables'] ?? []);

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => ['accessToken' => $this->accessToken(), 'refreshToken' => $this->refreshToken()]],
                ]);
            }

            $this->sent[] = ['query' => $query, 'variables' => $variables];

            if (isset($overrides['catch']) && ($overrides['catch']($query, $variables))) {
                return $overrides['respond'];
            }

            if (str_contains($query, 'mutation Add')) {
                return Http::response(['data' => ['addExtensionStore' => ['extensionStore' => self::storePayload()]]]);
            }

            if (str_contains($query, 'extensionStores')) {
                return Http::response(['data' => ['extensionStores' => ['nodes' => [self::storePayload()]]]]);
            }

            if (str_contains($query, 'extensions(')) {
                return Http::response(['data' => ['extensions' => [
                    'totalCount' => 1379,
                    'nodes' => [[
                        'pkgName' => 'eu.kanade.tachiyomi.extension.all.example',
                        'name' => 'Example',
                        'versionName' => '1.0.0',
                        'contentWarning' => 'SAFE',
                        'lang' => 'en',
                        'isInstalled' => false,
                        'hasUpdate' => false,
                        'isObsolete' => false,
                        'iconUrl' => null,
                        'storeIndexUrl' => 'https://example.test/index.pb',
                    ]],
                ]]]);
            }

            return Http::response(['data' => ['ok' => true]]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function lastFilter(): array
    {
        $last = end($this->sent);

        return $last === false ? [] : (array) ($last['variables']['filter'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function storePayload(): array
    {
        return [
            'indexUrl' => 'https://example.test/index.pb',
            'name' => 'Test Store',
            'badgeLabel' => 'TEST',
            'signingKey' => 'abc123',
            'contactWebsite' => null,
            'contactDiscord' => null,
            'isLegacy' => false,
            'extensionListUrl' => null,
        ];
    }

    /**
     * Registering a store and mirroring the source list belong to SourceCatalog.
     * ExtensionManager owns extensions and trust, so the store fixtures here go
     * through the model directly and only the server round trip is stubbed.
     */
    private function makeStore(string $url = 'https://example.test/index.pb', string $name = 'Test Store'): ExtensionStore
    {
        return ExtensionStore::create([
            'index_url' => $url,
            'name' => $name,
            'signing_key' => 'abc123',
        ]);
    }

    /**
     * The filter must travel as the nested `filter` argument, because
     * ExtensionConditionInput takes flat scalars and cannot express AND or IN.
     */
    public function test_it_sends_filters_as_a_nested_filter_argument_not_a_condition(): void
    {
        $this->fakeSuwayomi();

        app(ExtensionManager::class)->extensions(['installed' => false, 'content' => ['SAFE']]);

        $filter = $this->lastFilter();

        $this->assertArrayHasKey('and', $filter, 'Must use filter:, since condition cannot express AND.');
        $this->assertContains(['isInstalled' => ['equalTo' => false]], $filter['and']);
        $this->assertContains(['contentWarning' => ['in' => ['SAFE']]], $filter['and']);

        $this->assertStringNotContainsString('ExtensionConditionInput', end($this->sent)['query']);
    }

    public function test_it_omits_filters_that_were_not_asked_for(): void
    {
        $this->fakeSuwayomi();

        app(ExtensionManager::class)->extensions(['installed' => true]);

        $and = $this->lastFilter()['and'];

        $this->assertCount(1, $and, 'An unset filter must not narrow the query.');
        $this->assertSame(['isInstalled' => ['equalTo' => true]], $and[0]);
    }

    public function test_a_null_filter_is_distinct_from_an_absent_one(): void
    {
        $this->fakeSuwayomi();

        app(ExtensionManager::class)->extensions(['installed' => null]);

        $this->assertSame([], $this->lastFilter()['and'], 'null means unknown, so it must not filter.');
    }

    public function test_it_searches_names_case_insensitively(): void
    {
        $this->fakeSuwayomi();

        app(ExtensionManager::class)->extensions(['name' => 'MANGA']);

        $this->assertContains(
            ['name' => ['includesInsensitive' => 'MANGA']],
            $this->lastFilter()['and'],
        );
    }

    /**
     * The languages left out travel as the server's own `notIn`.
     *
     * The key is the list of what the reader does not want, so the wire shape
     * is a `notIn` and not an `in`. Measured against the live server on
     * 2026-10-21: `notIn ["pt-BR"]` on the available set takes it from
     * 1,055 to 976, and `notIn ["pt-br"]` takes it to 1,055, so the case of
     * the code is the catalogue's own.
     */
    public function test_the_languages_left_out_travel_as_the_servers_own_not_in(): void
    {
        $this->fakeSuwayomi();

        app(ExtensionManager::class)->extensions(['lang' => ['fr', 'pt-BR']]);

        $this->assertContains(
            ['lang' => ['notIn' => ['fr', 'pt-BR']]],
            $this->lastFilter()['and'],
        );
    }

    /**
     * No languages left out is no language filter at all.
     */
    public function test_no_language_left_out_sends_no_language_filter(): void
    {
        $this->fakeSuwayomi();

        app(ExtensionManager::class)->extensions(['lang' => []]);

        $this->assertNotContains(
            ['lang' => ['notIn' => []]],
            $this->lastFilter()['and'],
        );
    }

    /**
     * The language scan counts what it reads, biggest language first.
     *
     * Suwayomi has no group-by, so the only way to learn which languages
     * there are is to read the language of every extension that matches and
     * count them here. The order is the size order, so the row of pills puts
     * the language a reader is most likely to want first, and the code
     * breaks a tie so two languages of the same size never swap places.
     */
    public function test_the_language_scan_counts_each_language_and_orders_them_by_size(): void
    {
        $this->fakeSuwayomi(overrides: ['catch' => function (string $query): bool {
            return str_contains($query, 'query Languages');
        }, 'respond' => ['data' => ['extensions' => ['nodes' => [
            ['lang' => 'en'],
            ['lang' => 'ja'],
            ['lang' => 'en'],
            ['lang' => 'fr'],
            ['lang' => 'ja'],
            ['lang' => 'en'],
        ]]]]]);

        $languages = app(ExtensionManager::class)->extensionLanguages();

        $this->assertSame([
            ['code' => 'en', 'count' => 3],
            ['code' => 'ja', 'count' => 2],
            ['code' => 'fr', 'count' => 1],
        ], $languages);
    }

    /**
     * The scan asks for the language and nothing else.
     *
     * A thousand rows of package names and icons are a thousand rows the
     * server did not need to send, and the scan is the one request on the
     * page that reads the whole catalogue.
     */
    public function test_the_language_scan_asks_for_the_language_and_nothing_else(): void
    {
        $this->fakeSuwayomi();

        app(ExtensionManager::class)->extensionLanguages();

        $query = end($this->sent)['query'];

        $this->assertStringContainsString('nodes { lang }', $query);
        $this->assertStringNotContainsString('pkgName', $query);
    }

    public function test_adding_a_store_mirrors_it_into_our_own_table(): void
    {
        $this->fakeSuwayomi();

        $store = app(SourceCatalog::class)->addStore('https://example.test/index.pb');

        $this->assertSame('Test Store', $store->name);
        $this->assertSame('abc123', $store->signing_key);
        $this->assertTrue($store->isProtobuf());

        $this->assertDatabaseHas('extension_stores', ['index_url' => 'https://example.test/index.pb']);
    }

    /**
     * Registering a store belongs to SourceCatalog, which also owns the error
     * for the easy mistake: the repository root rather than the index file.
     */
    public function test_adding_a_store_that_yields_nothing_explains_the_likely_cause(): void
    {
        $this->fakeSuwayomi([
            'catch' => static fn (string $q): bool => str_contains($q, 'mutation Add'),
            'respond' => Http::response(['data' => ['addExtensionStore' => ['extensionStore' => null]]]),
        ]);

        try {
            app(SourceCatalog::class)->addStore('https://github.com/keiyoushi/extensions');
            $this->fail('Expected a SuwayomiException.');
        } catch (SuwayomiException $e) {
            $this->assertStringContainsString('index', $e->getMessage());
            $this->assertStringContainsString('repository', $e->getMessage());
        }
    }

    /**
     * A store that no longer exists cannot be trusted, and keeping the record
     * would let the user re-trust a different publisher under a familiar name.
     * The server round trip lives in SourceCatalog.
     */
    public function test_removing_a_store_also_drops_its_trust_records(): void
    {
        $this->fakeSuwayomi();

        $user = User::factory()->create();
        $store = $this->makeStore();
        app(ExtensionManager::class)->trust($user, $store);

        $this->assertDatabaseHas('user_extension_stores', [
            'user_id' => $user->id,
            'extension_store_id' => $store->id,
        ]);

        app(SourceCatalog::class)->removeStore('https://example.test/index.pb');

        $this->assertDatabaseMissing('user_extension_stores', ['extension_store_id' => $store->id]);
        $this->assertDatabaseMissing('extension_stores', ['id' => $store->id]);
    }

    public function test_trust_is_recorded_per_user(): void
    {
        $this->fakeSuwayomi();

        $manager = app(ExtensionManager::class);
        $store = $this->makeStore();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $manager->trust($alice, $store);

        $this->assertTrue($manager->isTrusted($alice, $store));
        $this->assertFalse($manager->isTrusted($bob, $store), 'Trusting must not leak across users.');
    }

    public function test_revoking_trust_leaves_the_record_but_clears_the_date(): void
    {
        $this->fakeSuwayomi();

        $manager = app(ExtensionManager::class);
        $store = $this->makeStore();
        $user = User::factory()->create();

        $manager->trust($user, $store);
        $manager->revokeTrust($user, $store);

        $this->assertFalse($manager->isTrusted($user, $store));
        $this->assertDatabaseHas('user_extension_stores', [
            'user_id' => $user->id,
            'extension_store_id' => $store->id,
            'trusted_at' => null,
        ]);
    }

    public function test_trusting_twice_keeps_the_original_date(): void
    {
        $this->fakeSuwayomi();

        $manager = app(ExtensionManager::class);
        $store = $this->makeStore();
        $user = User::factory()->create();

        $first = $manager->trust($user, $store)->trusted_at;

        $this->travel(5)->minutes();

        $second = $manager->trust($user, $store)->trusted_at;

        $this->assertEquals($first, $second, 'Re-trusting is not a new decision, so the date holds.');
    }

    /**
     * The point of reporting per-package failures is that the user is about to be
     * told how many sources they gained, so a partial result must be visible.
     */
    public function test_install_many_reports_which_packages_failed(): void
    {
        $this->fakeSuwayomi([
            'catch' => static fn (string $q, array $v): bool => str_contains($q, 'updateExtension')
                && ($v['id'] ?? '') === 'eu.kanade.bad',
            'respond' => Http::response(['errors' => [['message' => 'signature does not match']]]),
        ]);

        $result = app(ExtensionManager::class)->installMany([
            'eu.kanade.good.one',
            'eu.kanade.bad',
            'eu.kanade.good.two',
        ]);

        $this->assertSame(['eu.kanade.good.one', 'eu.kanade.good.two'], $result['installed']);
        $this->assertSame(['eu.kanade.bad'], array_keys($result['failed']));
        $this->assertStringContainsString('signature', $result['failed']['eu.kanade.bad']);
    }

    public function test_installing_an_extension_sends_the_package_name_as_the_id(): void
    {
        $this->fakeSuwayomi();

        app(ExtensionManager::class)->setInstalled('eu.kanade.tachiyomi.extension.en.example', true);

        $last = end($this->sent);

        $this->assertSame('eu.kanade.tachiyomi.extension.en.example', $last['variables']['id']);
        $this->assertSame(['install' => true], $last['variables']['patch']);
    }

    public function test_extension_count_asks_the_server_rather_than_counting_rows(): void
    {
        $this->fakeSuwayomi();

        $this->assertSame(1379, app(ExtensionManager::class)->extensionCount(['installed' => false]));
    }

    /**
     * last_synced_at has to mean something. A store we have never re-read must
     * report null so the UI can say "not synced yet" rather than implying the
     * mirror agrees with the server when nobody has checked.
     */
    public function test_syncing_stores_stamps_the_mirror_and_leaves_unsynced_ones_null(): void
    {
        $this->fakeSuwayomi();

        $stale = ExtensionStore::create([
            'index_url' => 'https://elsewhere.test/index.pb',
            'name' => 'Never Synced',
        ]);

        $this->assertNull($stale->fresh()->last_synced_at);

        app(SourceCatalog::class)->syncStores();

        $mirrored = ExtensionStore::query()->where('index_url', 'https://example.test/index.pb')->first();

        $this->assertNotNull($mirrored, 'The store the server knows about should be mirrored.');
        $this->assertNotNull($mirrored->last_synced_at);

        $this->assertNull(
            $stale->fresh()->last_synced_at,
            'A store the server does not return must not be stamped as synced.',
        );
    }

    public function test_trust_records_are_scoped_to_the_acting_user(): void
    {
        $this->fakeSuwayomi();

        $manager = app(ExtensionManager::class);
        $store = $this->makeStore();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $manager->trust($alice, $store);

        $this->assertSame([$store->id], $manager->trustedStoreIds($alice));
        $this->assertSame([], $manager->trustedStoreIds($bob));
    }

    /**
     * An extension arrives with the index URL of the store it came from, which is
     * the only way to decide whether its publisher has been trusted.
     */
    public function test_it_resolves_the_store_an_extension_came_from(): void
    {
        $this->fakeSuwayomi();

        $store = $this->makeStore();

        $this->assertSame(
            $store->id,
            app(ExtensionManager::class)->storeFor(['storeIndexUrl' => 'https://example.test/index.pb'])?->id,
        );

        $this->assertNull(app(ExtensionManager::class)->storeFor(['storeIndexUrl' => 'https://elsewhere.test/i.pb']));
        $this->assertNull(app(ExtensionManager::class)->storeFor([]));
    }

    /**
     * user_id is not mass-assignable, so a request cannot write a trust record
     * on someone else's behalf. Only the explicit createFor path can.
     */
    public function test_a_trust_record_cannot_be_created_without_an_owner(): void
    {
        $store = ExtensionStore::create([
            'index_url' => 'https://example.test/index.pb',
            'name' => 'Test Store',
        ]);

        $this->expectException(MissingOwnerException::class);

        UserExtensionStore::create(['extension_store_id' => $store->id, 'trusted_at' => now()]);
    }
}
