<?php

namespace App\Suwayomi;

use App\Models\ExtensionStore;
use App\Models\User;
use App\Models\UserExtensionStore;
use Illuminate\Support\Collection;

/**
 * The extension catalogue and the per-user decision to install from it.
 *
 * Scope is deliberate. Registering a store and mirroring the source list belong
 * to {@see SourceCatalog}, which owns everything Suwayomi treats as truth about
 * what is registered. This class owns the two things SourceCatalog does not:
 * acting on an individual extension, and the trust decision, which is per-user
 * because it is a decision about what one person is willing to execute.
 *
 * Filtering happens in Suwayomi, not here. The Keiyoushi store holds 1,379
 * extensions, and every web client that pages a catalogue through the browser
 * is the reason they feel slow. ExtensionFilterInput gives us isInstalled,
 * hasUpdate, isObsolete, contentWarning and a case insensitive name match, so
 * the catalogue is narrowed before it ever reaches PHP. Measured at 65 to
 * 270ms per filtered count against the live server.
 */
class ExtensionManager
{
    public function __construct(
        private readonly Gateway $gateway,
    ) {}

    /**
     * List extensions, narrowed by Suwayomi rather than in PHP.
     *
     * @param  array{installed?: bool|null, has_update?: bool|null, obsolete?: bool|null, content?: array<int, string>, name?: string, store?: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function extensions(array $filters = [], int $limit = 60, int $offset = 0): Collection
    {
        $data = $this->gateway->execute(
            'query Extensions($first: Int, $offset: Int, $filter: ExtensionFilterInput, $order: [ExtensionOrderInput!]) {
                extensions(first: $first, offset: $offset, filter: $filter, order: $order) {
                    totalCount
                    nodes {
                        pkgName name versionName contentWarning lang
                        isInstalled hasUpdate isObsolete iconUrl storeIndexUrl
                    }
                }
            }',
            [
                'first' => $limit,
                'offset' => $offset,
                'filter' => ['and' => $this->buildFilter($filters)],
                'order' => [['by' => 'PKG_NAME', 'byType' => 'ASC']],
            ],
        );

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = $data['extensions']['nodes'] ?? [];

        return collect($nodes);
    }

    /**
     * How many extensions match, so the UI can page without loading the catalogue.
     *
     * @param  array<string, mixed>  $filters
     */
    public function extensionCount(array $filters = []): int
    {
        $data = $this->gateway->execute(
            'query Count($filter: ExtensionFilterInput) { extensions(filter: $filter) { totalCount } }',
            ['filter' => ['and' => $this->buildFilter($filters)]],
        );

        return (int) ($data['extensions']['totalCount'] ?? 0);
    }

    /**
     * Install or uninstall one extension by package name.
     *
     * The id is the pkgName string, not a numeric id. Verified against the
     * schema: UpdateExtensionInput.id is a String!.
     *
     * @throws SuwayomiException
     */
    public function setInstalled(string $pkgName, bool $installed): void
    {
        $this->gateway->execute(
            'mutation Set($id: String!, $patch: UpdateExtensionPatchInput!) { updateExtension(input: {id: $id, patch: $patch}) { clientMutationId } }',
            ['id' => $pkgName, 'patch' => ['install' => $installed]],
        );
    }

    /**
     * @throws SuwayomiException
     */
    public function update(string $pkgName): void
    {
        $this->gateway->execute(
            'mutation Update($id: String!) { updateExtension(input: {id: $id, patch: {update: true}}) { clientMutationId } }',
            ['id' => $pkgName],
        );
    }

    /**
     * Install several extensions.
     *
     * Each needs its own mutation, so this is a loop rather than a batch. A
     * partial failure is reported rather than swallowed, because the user is
     * about to be told how many sources they just gained and "some worked" is
     * not an acceptable answer to that question.
     *
     * @param  array<int, string>  $pkgNames
     * @return array{installed: array<int, string>, failed: array<string, string>}
     */
    public function installMany(array $pkgNames): array
    {
        $installed = [];
        $failed = [];

        foreach ($pkgNames as $pkgName) {
            try {
                $this->setInstalled($pkgName, true);
                $installed[] = $pkgName;
            } catch (SuwayomiException $e) {
                $failed[$pkgName] = $e->getMessage();
            }
        }

        return ['installed' => $installed, 'failed' => $failed];
    }

    /**
     * Record that a user chose to trust a store's signing key.
     *
     * Honest caveat: this is a gate in our own interface, not an enforcement
     * the API offers. Suwayomi keeps its own trusted repository list in server
     * config and exposes no trust surface through GraphQL, so a determined user
     * can install from the server's own UI regardless. The point is to surface
     * the signing key before anyone clicks install, and to make the cautious
     * path the default one rather than the one that requires typing a URL.
     */
    public function trust(User $user, ExtensionStore $store): UserExtensionStore
    {
        $existing = UserExtensionStore::forUser($user->id)
            ->where('extension_store_id', $store->id)
            ->first();

        if ($existing !== null) {
            // Trust is a decision with a date, not a flag that flips, so
            // re-trusting an already trusted store must not move the date.
            if ($existing->trusted_at === null) {
                $existing->trusted_at = now();
                $existing->save();
            }

            return $existing;
        }

        return UserExtensionStore::createFor($user->id, [
            'extension_store_id' => $store->id,
            'trusted_at' => now(),
        ]);
    }

    public function revokeTrust(User $user, ExtensionStore $store): void
    {
        UserExtensionStore::forUser($user->id)
            ->where('extension_store_id', $store->id)
            ->update(['trusted_at' => null]);
    }

    public function isTrusted(User $user, ExtensionStore $store): bool
    {
        $record = UserExtensionStore::forUser($user->id)
            ->where('extension_store_id', $store->id)
            ->first();

        return $record !== null && $record->trusted_at !== null;
    }

    /**
     * Ids of the stores this user has trusted.
     *
     * Install actions are only offered for extensions whose store appears here,
     * so this is the check the UI leans on.
     *
     * @return array<int, int>
     */
    public function trustedStoreIds(User $user): array
    {
        return UserExtensionStore::forUser($user->id)
            ->whereNotNull('trusted_at')
            ->pluck('extension_store_id')
            ->all();
    }

    /**
     * The store an extension came from, if we hold it.
     *
     * @param  array<string, mixed>  $extension
     */
    public function storeFor(array $extension): ?ExtensionStore
    {
        $url = $extension['storeIndexUrl'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return ExtensionStore::query()->where('index_url', $url)->first();
    }

    /**
     * Build an ExtensionFilterInput from a simple filter array.
     *
     * "filter" rather than "condition": the condition input only takes flat
     * scalars, so it cannot express "content in SAFE or MIXED" or a case
     * insensitive name match, which is exactly what the catalogue needs.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildFilter(array $filters): array
    {
        $and = [];

        foreach ([
            'installed' => 'isInstalled',
            'has_update' => 'hasUpdate',
            'obsolete' => 'isObsolete',
        ] as $key => $field) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null) {
                $and[] = [$field => ['equalTo' => $filters[$key]]];
            }
        }

        if (! empty($filters['content'])) {
            $and[] = ['contentWarning' => ['in' => array_values($filters['content'])]];
        }

        if (! empty($filters['name'])) {
            $and[] = ['name' => ['includesInsensitive' => $filters['name']]];
        }

        if (! empty($filters['store'])) {
            $and[] = ['storeIndexUrl' => ['equalTo' => $filters['store']]];
        }

        return $and;
    }
}
