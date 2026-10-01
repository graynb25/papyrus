<?php

namespace App\Suwayomi;

use App\Models\ExtensionStore;
use App\Models\Scopes\OwnedByUser;
use App\Models\Source;
use App\Models\UserExtensionStore;
use App\Support\Suwayomi\Timestamps;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the server's extension stores, extensions and sources into our own
 * tables.
 *
 * Suwayomi owns this data. We cache it rather than own it, for three reasons
 * measured against a live server: listing 1,379 sources through the browser on
 * every page view is slow; a source list is needed to render UI even when the
 * server is mid-restart; and our own health ledger needs somewhere to hang
 * per-source observations that Suwayomi has no field for at all.
 *
 * LongString ids are coerced through Timestamps - source ids exceed 2^53 in
 * practice, so they must never round-trip through a JSON number.
 */
class SourceCatalog
{
    public function __construct(
        private readonly Gateway $gateway,
        private readonly Timestamps $timestamps,
    ) {}

    /**
     * Refresh extension stores from the server.
     *
     * @return int number of stores seen
     */
    public function syncStores(): int
    {
        $data = $this->gateway->execute(<<<'GQL'
            query Stores {
                extensionStores { nodes { indexUrl name badgeLabel signingKey contactWebsite contactDiscord isLegacy extensionListUrl } }
            }
            GQL);

        $stores = $data['extensionStores']['nodes'] ?? [];

        foreach ($stores as $store) {
            ExtensionStore::query()->updateOrCreate(
                ['index_url' => $store['indexUrl']],
                [
                    'name' => $store['name'] ?? 'Unknown store',
                    'badge_label' => $store['badgeLabel'] ?? null,
                    'signing_key' => $store['signingKey'] ?? null,
                    'contact_website' => $store['contactWebsite'] ?? null,
                    'contact_discord' => $store['contactDiscord'] ?? null,
                    'is_legacy' => (bool) ($store['isLegacy'] ?? false),
                    'extension_list_url' => $store['extensionListUrl'] ?? null,
                    'last_synced_at' => now(),
                ],
            );
        }

        return count($stores);
    }

    /**
     * Add a store to the server and mirror the result.
     *
     * @throws SuwayomiException
     */
    public function addStore(string $indexUrl): ExtensionStore
    {
        $data = $this->gateway->execute(
            'mutation Add($u: String!) { addExtensionStore(input: {indexUrl: $u}) { extensionStore { indexUrl name badgeLabel signingKey contactWebsite contactDiscord isLegacy extensionListUrl } } }',
            ['u' => $indexUrl],
        );

        $store = $data['addExtensionStore']['extensionStore'] ?? null;

        if (! is_array($store)) {
            throw new SuwayomiException(
                'Suwayomi accepted the store URL but returned no store. Is the URL a repository index?',
                FailureKind::Query,
            );
        }

        return ExtensionStore::query()->updateOrCreate(
            ['index_url' => $store['indexUrl']],
            [
                'name' => $store['name'] ?? 'Unknown store',
                'badge_label' => $store['badgeLabel'] ?? null,
                'signing_key' => $store['signingKey'] ?? null,
                'contact_website' => $store['contactWebsite'] ?? null,
                'contact_discord' => $store['contactDiscord'] ?? null,
                'is_legacy' => (bool) ($store['isLegacy'] ?? false),
                'extension_list_url' => $store['extensionListUrl'] ?? null,
                'added_at' => now(),
                'last_synced_at' => now(),
            ],
        );
    }

    /**
     * Remove a store from the server and drop our mirror of it.
     *
     * Trust records go with it: a store that no longer exists cannot be trusted,
     * and keeping the row would invite the user to re-trust a different
     * publisher later under a name they already recognise.
     *
     * @throws SuwayomiException
     */
    public function removeStore(string $indexUrl): void
    {
        $this->gateway->execute(
            'mutation Remove($u: String!) { removeExtensionStore(input: {indexUrl: $u}) { clientMutationId } }',
            ['u' => $indexUrl],
        );

        $store = ExtensionStore::query()->where('index_url', $indexUrl)->first();

        if ($store === null) {
            return;
        }

        UserExtensionStore::query()
            ->withoutGlobalScope(OwnedByUser::class)
            ->where('extension_store_id', $store->id)
            ->delete();

        $store->delete();
    }

    /**
     * Tell the server to re-read every store, then mirror extensions.
     *
     * @throws SuwayomiException
     */
    public function refreshExtensions(): int
    {
        $data = $this->gateway->execute(
            'mutation Fetch { fetchExtensions(input: {}) { extensions { pkgName } } }',
        );

        return count($data['fetchExtensions']['extensions'] ?? []);
    }

    /**
     * Mirror the installed source catalogue.
     *
     * Sources are marked uninstalled rather than deleted when they vanish from
     * the server, so a user's pinned preferences and health history survive an
     * extension being temporarily removed.
     *
     * @return int number of sources synced
     */
    public function syncSources(): int
    {
        $data = $this->gateway->execute(<<<'GQL'
            query Sources {
                sources {
                    nodes {
                        id name displayName lang contentWarning homeUrl iconUrl
                        supportsLatest isConfigurable
                        extension { pkgName isInstalled }
                    }
                }
            }
            GQL);

        $nodes = $data['sources']['nodes'] ?? [];
        $seen = [];

        $now = now();

        foreach ($nodes as $node) {
            $sourceId = $this->timestamps->toInt($node['id'] ?? null);

            if ($sourceId === null) {
                continue;
            }

            $seen[] = $sourceId;

            $payload = [
                'name' => $node['name'] ?? '',
                'display_name' => $node['displayName'] ?? ($node['name'] ?? ''),
                'extension_pkg_name' => $node['extension']['pkgName'] ?? '',
                'lang' => $node['lang'] ?? 'other',
                'content_warning' => $node['contentWarning'] ?? Source::SAFE,
                'home_url' => $node['homeUrl'] ?? null,
                'icon_url' => $node['iconUrl'] ?? null,
                'supports_latest' => (bool) ($node['supportsLatest'] ?? false),
                'is_configurable' => (bool) ($node['isConfigurable'] ?? false),
                'is_installed' => true,
                'last_synced_at' => $now,
            ];

            $existing = Source::query()->find($sourceId);

            if ($existing !== null) {
                $existing->fill($payload)->save();

                continue;
            }

            Source::query()->create(['suwayomi_source_id' => $sourceId, ...$payload]);
        }

        if ($seen !== []) {
            Source::query()
                ->whereNotIn('suwayomi_source_id', $seen)
                ->where('is_installed', true)
                ->update(['is_installed' => false, 'last_synced_at' => $now]);
        }

        return count($seen);
    }

    /**
     * Sync everything in one transaction, so a half-synced catalogue is never
     * visible to a reader.
     *
     * @return array{stores: int, sources: int}
     */
    public function syncAll(): array
    {
        return DB::transaction(function (): array {
            $stores = $this->syncStores();
            $sources = $this->syncSources();

            return ['stores' => $stores, 'sources' => $sources];
        });
    }
}
