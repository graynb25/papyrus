<?php

namespace Tests\Feature;

use App\Models\ExtensionStore;
use App\Models\User;
use App\Suwayomi\ExtensionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The catalogue lookup an import uses to decide where a package comes from.
 *
 * ## Why the shape of the query is asserted here
 *
 * The installer can only hold or install a package it can place in a store, so the lookup
 * has to answer two questions the page used to have no way to ask: which offers exist for
 * this exact set of package names, and does one of them belong to a store this reader
 * trusts. That makes the request shape load bearing. A filter that silently dropped the
 * names would return the whole catalogue, and every package would look unplaced; a scan
 * without chunking would send a list larger than a future backup could name.
 *
 * The per-user trust read is here for the same reason: it is the other half of the same
 * decision, and a revoked row must not keep appearing in it.
 */
class ExtensionManagerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every payload sent to the fake Suwayomi server, in order.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sent = [];
    }

    /*
     * ---------------------------------------------------------------------------
     * Asking for named packages.
     * ---------------------------------------------------------------------------
     */

    public function test_the_catalogue_is_asked_for_the_named_packages_in_bounded_chunks(): void
    {
        $this->fakeCatalogue();

        $names = array_map(
            static fn (int $i): string => sprintf('eu.test.extension.pkg.%04d', $i),
            range(1, 1201),
        );

        // A duplicate and an empty name are what a caller can hand over from a real backup.
        // Neither may become a second query or a blank filter entry.
        $names[] = 'eu.test.extension.pkg.0001';
        $names[] = '';

        $catalogue = app(ExtensionManager::class)->catalogueForPackages($names);

        $this->assertCount(1201, $catalogue);
        $this->assertCount(3, $this->packageQueries(), '1,201 names are three requests, not one unbounded list');
        $this->assertSame([500, 500, 201], $this->chunkSizes());
        $this->assertSame(ExtensionManager::PACKAGE_QUERY_LIMIT, $this->packageQueries()[0]['variables']['first']);

        $this->assertSame(
            array_slice($names, 0, 1201),
            $this->queriedNames(),
            'every name is asked for once, in the order the caller gave them',
        );
    }

    public function test_the_package_query_bound_fails_closed_rather_than_unbounded(): void
    {
        $this->fakeCatalogue();

        $names = array_map(
            static fn (int $i): string => sprintf('eu.test.extension.pkg.%04d', $i),
            range(1, 5001),
        );

        $catalogue = app(ExtensionManager::class)->catalogueForPackages($names);

        $this->assertCount(ExtensionManager::PACKAGE_QUERY_LIMIT, $catalogue);
        $this->assertCount(10, $this->packageQueries(), '5,000 names at 500 each');
        $this->assertSame(500, count((array) end($this->sent)['variables']['filter']['and'][0]['pkgName']['in']));
        $this->assertNotContains(
            'eu.test.extension.pkg.5001',
            $this->queriedNames(),
            'a name past the bound is simply not queried, which the installer treats as untrusted',
        );
    }

    public function test_every_offer_for_a_package_is_returned(): void
    {
        $this->fakeCatalogue(static fn (array $names): array => [
            [
                'pkgName' => 'eu.test.extension.pkg',
                'name' => 'The first offer',
                'versionName' => '1.0',
                'storeIndexUrl' => 'https://one.test/index.pb',
            ],
            [
                'pkgName' => 'eu.test.extension.pkg',
                'name' => 'The second offer',
                'versionName' => '2.0',
                'storeIndexUrl' => 'https://two.test/index.pb',
            ],
            // Nodes without a package name cannot be filed, and must not become a key.
            ['pkgName' => '', 'name' => 'Nameless', 'versionName' => '1.0'],
        ]);

        $catalogue = app(ExtensionManager::class)->catalogueForPackages(['eu.test.extension.pkg']);

        $this->assertCount(1, $catalogue);
        $this->assertCount(2, $catalogue->get('eu.test.extension.pkg'));
        $this->assertSame(
            ['https://one.test/index.pb', 'https://two.test/index.pb'],
            array_column($catalogue->get('eu.test.extension.pkg'), 'storeIndexUrl'),
            'one package can be published by more than one store, and trust has to see both',
        );
    }

    /*
     * ---------------------------------------------------------------------------
     * The trust read and the store lookup.
     * ---------------------------------------------------------------------------
     */

    public function test_trusted_store_ids_are_only_this_users_current_trust(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $trusted = $this->store('Trusted store', 'https://one.test/index.pb');
        $revoked = $this->store('Revoked store', 'https://two.test/index.pb');
        $theirs = $this->store('Their store', 'https://three.test/index.pb');

        $manager = app(ExtensionManager::class);
        $manager->trust($user, $trusted);
        $manager->trust($user, $revoked);
        $manager->revokeTrust($user, $revoked);
        $manager->trust($other, $theirs);

        $this->assertSame([$trusted->id], $manager->trustedStoreIds($user), 'a revoked store is not trust');
        $this->assertSame([$theirs->id], $manager->trustedStoreIds($other->id), 'another reader has their own answer');
        $this->assertSame([], $manager->trustedStoreIds(User::factory()->create()));
    }

    public function test_a_store_is_found_by_the_catalogue_url_and_not_by_name(): void
    {
        $store = $this->store('A name a server could change', 'https://example.test/index.pb');
        $manager = app(ExtensionManager::class);

        $this->assertSame($store->id, $manager->storeFor(['storeIndexUrl' => $store->index_url])?->id);
        $this->assertNull($manager->storeFor(['storeIndexUrl' => 'https://unknown.test/index.pb']));
        $this->assertNull($manager->storeFor([]), 'a node with no store cannot be placed');
    }

    /*
     * ---------------------------------------------------------------------------
     * Helpers.
     * ---------------------------------------------------------------------------
     */

    private function store(string $name, string $url): ExtensionStore
    {
        return ExtensionStore::create([
            'name' => $name,
            'index_url' => $url,
            'signing_key' => 'abc123',
        ]);
    }

    /**
     * Answer the catalogue with one node per requested name, unless the test supplies nodes.
     *
     * @param  (callable(array<int, string>): array<int, array<string, mixed>>)|null  $nodes
     */
    private function fakeCatalogue(?callable $nodes = null): void
    {
        Http::fake(function (Request $request) use ($nodes) {
            $body = json_decode((string) $request->body(), true) ?: [];
            $this->sent[] = $body;

            $document = (string) ($body['query'] ?? '');

            if (str_contains($document, 'login(input')) {
                return Http::response(['data' => ['login' => ['accessToken' => 'a-token']]]);
            }

            if (! str_contains($document, 'query Packages')) {
                return Http::response(['data' => []]);
            }

            $names = (array) ($body['variables']['filter']['and'][0]['pkgName']['in'] ?? []);

            $offers = $nodes !== null
                ? $nodes($names)
                : array_map(static fn (mixed $name): array => [
                    'pkgName' => (string) $name,
                    'name' => (string) $name,
                    'versionName' => '1.0',
                    'storeIndexUrl' => 'https://example.test/index.pb',
                ], $names);

            return Http::response(['data' => ['extensions' => ['nodes' => $offers]]]);
        });
    }

    /**
     * The variables of every catalogue query, in order.
     *
     * @return array<int, array{variables: array<string, mixed>}>
     */
    private function packageQueries(): array
    {
        return array_values(array_map(
            static fn (array $payload): array => ['variables' => (array) ($payload['variables'] ?? [])],
            array_filter(
                $this->sent,
                static fn (array $payload): bool => str_contains((string) ($payload['query'] ?? ''), 'query Packages'),
            ),
        ));
    }

    /**
     * The chunk size of every catalogue query, in order.
     *
     * @return list<int>
     */
    private function chunkSizes(): array
    {
        return array_map(
            static fn (array $query): int => count((array) $query['variables']['filter']['and'][0]['pkgName']['in']),
            $this->packageQueries(),
        );
    }

    /**
     * Every name sent to the catalogue, concatenated in request order.
     *
     * @return list<string>
     */
    private function queriedNames(): array
    {
        $names = [];

        foreach ($this->packageQueries() as $query) {
            foreach ((array) $query['variables']['filter']['and'][0]['pkgName']['in'] as $name) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
