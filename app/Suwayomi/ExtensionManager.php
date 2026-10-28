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
    /**
     * How many rows the language scan reads.
     *
     * The catalogue held 1,443 extensions on 2026-10-21, and the available
     * set 1,055, so this is roughly three times the largest measured. It is a
     * bound and not a page size: the scan counts what it reads, and a
     * catalogue past the bound would be counted short rather than counted
     * wrong.
     */
    public const LANGUAGE_SCAN_LIMIT = 5000;

    /**
     * How many package names one catalogue lookup asks for.
     *
     * The `pkgName in` filter takes a list, and a backup names at most a few hundred
     * packages. Chunking keeps the request shape bounded even when a lesson from a future
     * format is larger than the measured one, and the total bound is the same ceiling the
     * language scan uses.
     */
    public const PACKAGE_QUERY_CHUNK = 500;

    public const PACKAGE_QUERY_LIMIT = 5000;

    public function __construct(
        private readonly Gateway $gateway,
    ) {}

    /**
     * List extensions, narrowed by Suwayomi rather than in PHP.
     *
     * @param  array{installed?: bool|null, has_update?: bool|null, obsolete?: bool|null, content?: array<int, string>, name?: string, store?: string, lang?: array<int, string>}  $filters
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
     * The catalogue entries for a named set of packages, grouped by package.
     *
     * ## Why this is a separate query from `extensions()`
     *
     * An import knows the packages its backup names, not the filters a page browses by.
     * `pkgName` is a `StringFilterInput` and supports `in`, verified against the schema
     * dump (`StringFilterInput$in`), so the server narrows 1,400-odd extensions before
     * they reach PHP. The result is grouped rather than keyed because one package can be
     * offered by more than one store, and the trust decision has to see every offer.
     *
     * Names past {@see self::PACKAGE_QUERY_LIMIT} are not queried. The caller treats a
     * missing package as untrusted, so the bound fails closed rather than installing it.
     *
     * @param  array<int, string>  $pkgNames
     * @return Collection<string, non-empty-list<array<string, mixed>>> Package to the nodes offering it.
     *
     * @throws SuwayomiException
     */
    public function catalogueForPackages(array $pkgNames): Collection
    {
        $names = array_values(array_unique(array_filter(
            $pkgNames,
            static fn (string $name): bool => $name !== '',
        )));

        $names = array_slice($names, 0, self::PACKAGE_QUERY_LIMIT);
        $found = [];

        foreach (array_chunk($names, self::PACKAGE_QUERY_CHUNK) as $chunk) {
            $data = $this->gateway->execute(
                'query Packages($first: Int, $filter: ExtensionFilterInput) {
                    extensions(first: $first, filter: $filter) {
                        nodes { pkgName name versionName storeIndexUrl }
                    }
                }',
                [
                    'first' => self::PACKAGE_QUERY_LIMIT,
                    'filter' => ['and' => [['pkgName' => ['in' => $chunk]]]],
                ],
            );

            /*
             * The payload is untyped until it is read, so the contract is stated here the same
             * way `extensions()` states it: the query asks for exactly these four fields.
             */
            /** @var array<int, array<string, mixed>> $nodes */
            $nodes = $data['extensions']['nodes'] ?? [];

            foreach ($nodes as $node) {
                $package = $node['pkgName'] ?? null;

                if (! is_string($package) || $package === '') {
                    continue;
                }

                $found[$package][] = $node;
            }
        }

        return collect($found);
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
     * Every language the catalogue holds, with how many extensions carry it.
     *
     * ## Why this is a scan and not a query
     *
     * Suwayomi has no group-by, so the only way to learn which languages exist
     * and how many of them there are is to read the language of every extension
     * that matches the filters and count them here. Measured against the live
     * server on 2026-10-21: the available set is 1,055 extensions across 23
     * languages, and the scan costs **39 ms**, which is less than the filtered
     * count the page already asks for.
     *
     * The scan is bounded at {@see LANGUAGE_SCAN_LIMIT} rows, three times the
     * largest catalogue measured (1,443), so a catalogue that grew past the
     * bound would report a short count rather than a wrong one. The bound is
     * here rather than in the call because the size of the catalogue is the
     * server's fact and not the page's.
     *
     * The codes are the catalogue's own, with its own casing: `pt-BR` and
     * `zh-Hant` are not `pt-br` and `zh-hant`, and the server's match on the
     * language filter is case sensitive. Measured: `notIn ["pt-BR"]` on the
     * available set takes it from 1,055 to 976, and `notIn ["pt-br"]` takes it
     * to 1,055, so a code that has been folded to lower case filters nothing
     * and says so by not filtering.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{code: string, count: int}>
     */
    public function extensionLanguages(array $filters = []): array
    {
        $data = $this->gateway->execute(
            'query Languages($first: Int, $filter: ExtensionFilterInput) { extensions(first: $first, filter: $filter) { nodes { lang } } }',
            [
                'first' => self::LANGUAGE_SCAN_LIMIT,
                'filter' => ['and' => $this->buildFilter($filters)],
            ],
        );

        $counts = [];

        foreach (($data['extensions']['nodes'] ?? []) as $node) {
            $code = (string) ($node['lang'] ?? '');

            if ($code === '') {
                continue;
            }

            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        // Biggest first, because the languages a reader is most likely to want
        // are the ones with the most sources in them, and the order has to be
        // the same on every render or the row reflows for no reason. The code
        // breaks a tie, so two languages of the same size never swap places.
        uksort($counts, fn (string $a, string $b): int => ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b));

        $languages = [];

        foreach ($counts as $code => $count) {
            $languages[] = ['code' => $code, 'count' => $count];
        }

        return $languages;
    }

    /**
     * Install or uninstall one extension by package name.
     *
     * The id is the pkgName string, not a numeric id. Verified against the
     * schema: UpdateExtensionInput.id is a String!.
     *
     * Uninstall names `uninstall`, not `install: false`. Suwayomi 2.4.2366
     * accepts the latter and returns success while leaving the package
     * installed, so the reader's Uninstall control appeared to work and the
     * source stayed. Measured on the scratch server: `uninstall: true`
     * removed the package and dropped its source.
     *
     * @throws SuwayomiException
     */
    public function setInstalled(string $pkgName, bool $installed): void
    {
        $this->gateway->execute(
            'mutation Set($id: String!, $patch: UpdateExtensionPatchInput!) { updateExtension(input: {id: $id, patch: $patch}) { clientMutationId } }',
            ['id' => $pkgName, 'patch' => $installed ? ['install' => true] : ['uninstall' => true]],
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
     * Update several extensions.
     *
     * One mutation each, for the same reason installs are one each. A partial
     * failure is reported rather than swallowed: a reader pressing "update all"
     * is told exactly which packages did not move.
     *
     * @param  array<int, string>  $pkgNames
     * @return array{updated: array<int, string>, failed: array<string, string>}
     */
    public function updateMany(array $pkgNames): array
    {
        $updated = [];
        $failed = [];

        foreach ($pkgNames as $pkgName) {
            try {
                $this->update($pkgName);
                $updated[] = $pkgName;
            } catch (SuwayomiException $e) {
                $failed[$pkgName] = $e->getMessage();
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
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
    public function trustedStoreIds(User|int $user): array
    {
        return UserExtensionStore::forUser($user)
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
     * The `lang` key is the one whose shape is not obvious: it holds the
     * languages to **leave out**, not the ones to show. The reason is that the
     * list a reader starts from is every language, and a filter that had to
     * name every language it wanted would have to be rewritten every time the
     * catalogue grew one. Excluding is also the only shape in which "no
     * language at all" can be said: an allow list of nothing and an absent
     * filter are the same empty array, and only one of them can mean "nothing".
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

        if (! empty($filters['lang'])) {
            $and[] = ['lang' => ['notIn' => array_values($filters['lang'])]];
        }

        return $and;
    }
}
