<?php

namespace App\Suwayomi;

use App\Models\SourcePreference;

/**
 * Translates between filter *names* and filter *positions*.
 *
 * Suwayomi addresses browse filters by ordinal position, and FilterChangeInput
 * requires `position: Int!` with no key field anywhere in the union. None of
 * the eight filter members carries a key - only a `name`. So:
 *
 *   - `name` is the stable identity, safe to persist in a saved search
 *   - `position` is volatile, and shifts whenever a source reorders its filters
 *
 * Writing a remembered position back later is how a saved search silently
 * targets the wrong filter - the server cannot detect it, because it has no idea
 * what we meant. Every write therefore resolves name -> position against the
 * list read at that moment.
 *
 * GroupFilter is the second wrinkle. `groupChange` is a *single* nested change,
 * not a list, so a group with several children needs several FilterChangeInput
 * entries that share the group's outer position and differ only in the inner
 * one.
 */
class SourceFilterTranslator
{
    /**
     * Flatten a filter list into name-addressed entries, descending into groups.
     *
     * @param  array<int, array<string, mixed>>  $filters
     * @return array<string, array{position: int, inner: int|null, typename: string}>
     */
    public function addresses(array $filters): array
    {
        $addresses = [];

        foreach ($filters as $position => $filter) {
            $name = (string) ($filter['name'] ?? '');

            if ($name === '') {
                continue;
            }

            // First definition wins, so a group and a child sharing a name does
            // not make the child unreachable.
            $addresses[$name] ??= [
                'position' => (int) $position,
                'inner' => null,
                'typename' => (string) ($filter['__typename'] ?? ''),
            ];

            if (($filter['__typename'] ?? '') === 'GroupFilter') {
                foreach (array_values($filter['filters'] ?? []) as $inner => $child) {
                    $childName = (string) ($child['name'] ?? '');

                    if ($childName === '') {
                        continue;
                    }

                    $addresses[$childName] ??= [
                        'position' => (int) $position,
                        'inner' => (int) $inner,
                        'typename' => (string) ($child['__typename'] ?? ''),
                    ];
                }
            }
        }

        return $addresses;
    }

    /**
     * @param  array<int, array<string, mixed>>  $filters
     * @return array<string, int>
     */
    public function nameToPosition(array $filters): array
    {
        $map = [];

        foreach ($this->addresses($filters) as $name => $address) {
            $map[$name] = $address['inner'] ?? $address['position'];
        }

        return $map;
    }

    /**
     * Build a FilterChangeInput list from named values.
     *
     *   SelectFilter   => ['genre' => 2]
     *   CheckBoxFilter => ['completed' => true]
     *   TextFilter     => ['author' => 'Oda']
     *   TriStateFilter => ['licensed' => 'INCLUDE']
     *   SortFilter     => ['sort' => ['index' => 1, 'ascending' => false]]
     *
     * Unknown names are skipped rather than guessed at. Guessing a position is
     * precisely the failure this class exists to prevent, and it is silent: the
     * server applies the change to whatever sits at that index.
     *
     * @param  array<int, array<string, mixed>>  $filters
     * @param  array<string, mixed>  $values
     * @return array<int, array<string, mixed>>
     */
    public function toChanges(array $filters, array $values): array
    {
        $addresses = $this->addresses($filters);
        $changes = [];

        foreach ($values as $name => $value) {
            $address = $addresses[(string) $name] ?? null;

            if ($address === null) {
                continue;
            }

            $state = $this->stateFor($value);

            if ($state === null) {
                continue;
            }

            $changes[] = $address['inner'] === null
                ? ['position' => $address['position'], ...$state]
                : [
                    'position' => $address['position'],
                    'groupChange' => ['position' => $address['inner'], ...$state],
                ];
        }

        return $changes;
    }

    /**
     * The state half of a FilterChangeInput, keyed by value type.
     *
     * @return array<string, mixed>|null
     */
    private function stateFor(mixed $value): ?array
    {
        if (is_array($value) && isset($value['index'], $value['ascending'])) {
            return [
                'sortState' => [
                    'index' => (int) $value['index'],
                    'ascending' => (bool) $value['ascending'],
                ],
            ];
        }

        return match (true) {
            is_int($value) => ['selectState' => $value],
            is_bool($value) => ['checkBoxState' => $value],
            is_string($value) && in_array($value, ['IGNORE', 'INCLUDE', 'EXCLUDE'], true) => ['triState' => $value],
            is_string($value) => ['textState' => $value],
            default => null,
        };
    }

    /**
     * Record the source's current filter list, so a saved search can be
     * resolved against it later rather than against a stale position.
     *
     * @param  array<int, array<string, mixed>>  $filters
     */
    public function cacheFilters(int $sourceId, array $filters): SourcePreference
    {
        return SourcePreference::query()->updateOrCreate(
            ['source_id' => $sourceId],
            ['preferences' => $filters, 'cached_at' => now()],
        );
    }
}
