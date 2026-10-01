<?php

namespace App\Search;

/**
 * Turns one of our term values into the state a particular source will accept.
 *
 * ## Why this exists
 *
 * `SourceFilterTranslator` maps a filter *name* to a volatile *position*. It did not map
 * our *value* onto anything, so a value came through as a bare string and became
 * `textState`. Almost nothing wants text where a value goes. Measured on the box:
 * `Expected select state change at position 16`, `Expected sort state change at
 * position 5` and `Expected group change at position 4`, across MangaFire, Thunder
 * Scans, MangaTaro, Atsumaru, Weeb Central and all 61 MangaDex variants.
 *
 * ## The three shapes a value can take, and none of them is text
 *
 * Measured across all 82 installed sources, for the three terms that ship:
 *
 *   SelectFilter   a list of options, addressed by index
 *   GroupFilter    a box of children, one per value, each with its own kind
 *   SortFilter     a list of options plus a direction
 *
 * MangaDex is the case that matters most, being 61 of the 82. It models Status as a
 * `GroupFilter` of `CheckBoxFilter` children, one per value, and Genre as a group of
 * `TriStateFilter` children. So `status:completed` there means "tick the box labelled
 * Completed", which is a group change at an inner position, not a select at an outer one.
 *
 * ## `values` is a list of strings
 *
 * `SelectFilter.values` and `SortFilter.values` are `[String!]!`, not a list of objects
 * with `name` and `value`. Reading `$option['value']` off a string yields nothing, which
 * is why an earlier version of this resolved every select to null and every sort to
 * nothing at all, with no error anywhere to point at it. A selection naming fields a
 * leaf type does not have fails the whole query, so the mistake presents as silence
 * rather than as an exception.
 */
final class FilterValue
{
    /**
     * The value to send for a filter that is not a group, or null when it cannot be
     * expressed.
     *
     * @param  array<string, mixed>  $filter  one member of a source's own filter list
     * @return int|bool|array{index: int, ascending: bool}|string|null
     */
    public static function resolve(string $value, array $filter): int|bool|array|string|null
    {
        return match ((string) ($filter['__typename'] ?? '')) {
            'SelectFilter' => self::indexOf($value, self::options($filter)),
            'SortFilter' => self::sortState($value, self::options($filter)),
            'CheckBoxFilter' => filter_var(trim($value), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'TriStateFilter' => self::triState($value),

            // A TextFilter takes the value as written, which is the one case that needs
            // nothing done to it. `genre` lands here on the sources that model it as free
            // text rather than as a list.
            default => trim($value) === '' ? null : trim($value),
        };
    }

    /**
     * The child of a group whose name is our value, or null when there is no such child.
     *
     * A group is a box of one-filter-per-value, so our value is a *label* here rather
     * than something to look up in a list of options. MangaDex's Status group holds
     * `Ongoing`, `Completed`, `Hiatus` and `Cancelled` as four checkboxes, and
     * `status:completed` means the second one is on.
     *
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>|null
     */
    public static function childFor(string $value, array $children): ?array
    {
        foreach ($children as $child) {
            if (self::sameOption($value, (string) ($child['name'] ?? ''))) {
                return $child;
            }
        }

        return null;
    }

    /**
     * The state for a group child, which is what "this value is on" means for its kind.
     *
     * No sort here, because a `SortFilter` inside a group would be a list to choose from
     * rather than a single order, and no term of ours is that. The union is narrower than
     * `resolve()`'s for exactly that reason.
     *
     * @param  array<string, mixed>  $child
     */
    public static function stateForChild(array $child): int|bool|string|null
    {
        return match ((string) ($child['__typename'] ?? '')) {
            // A checkbox child exists to be ticked. Our value matched its name, so
            // matching is the whole test and there is nothing left to decide.
            'CheckBoxFilter' => true,

            // MangaDex models every genre and theme as a three-way: include, exclude, or
            // ignore. A reader typing `genre:action` wants it included.
            'TriStateFilter' => 'INCLUDE',

            'SelectFilter' => self::indexOf(
                (string) ($child['name'] ?? ''),
                self::options($child),
            ),

            default => trim((string) ($child['name'] ?? '')) === ''
                ? null
                : trim((string) $child['name']),
        };
    }

    /**
     * The index of our value among a filter's declared options.
     *
     * @param  array<int, string>  $values
     */
    private static function indexOf(string $value, array $values): ?int
    {
        foreach (array_values($values) as $index => $option) {
            if (self::sameOption($value, $option)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $values
     * @return array{index: int, ascending: bool}|null
     */
    private static function sortState(string $value, array $values): ?array
    {
        $index = self::indexOf($value, $values);

        // Descending, which is what a reader asking to sort by something wants. There is
        // no term for ascending, so this only has to be a defensible default rather than
        // a meaningful one.
        return $index === null ? null : ['index' => $index, 'ascending' => false];
    }

    private static function triState(string $value): ?string
    {
        $upper = strtoupper(trim($value));

        return in_array($upper, ['IGNORE', 'INCLUDE', 'EXCLUDE'], true) ? $upper : null;
    }

    /**
     * A filter's declared options.
     *
     * They arrive as `[String!]!`, a plain list of strings, and that is what is read. An
     * earlier version also tolerated objects with `name` and `value` keys, which was
     * defensive against a shape this schema does not have and quietly produced nothing
     * when handed the real one.
     *
     * @param  array<string, mixed>  $filter
     * @return array<int, string>
     */
    private static function options(array $filter): array
    {
        $out = [];

        foreach ((array) ($filter['values'] ?? []) as $option) {
            $out[] = is_string($option) ? $option : '';
        }

        return $out;
    }

    /**
     * Whether our value and a source's option mean the same thing.
     *
     * Compared on letters alone, then with a trailing `ly` given up. That covers the one
     * systematic mismatch across the install: we say `alphabetically`, sources say
     * `ALPHABETICAL`.
     */
    public static function sameOption(string $ours, string $theirs): bool
    {
        if (trim($ours) === '' || trim($theirs) === '') {
            return false;
        }

        $oursKey = self::key($ours);
        $theirsKey = self::key($theirs);

        if ($oursKey === $theirsKey) {
            return true;
        }

        if (str_ends_with($oursKey, 'ly') && substr($oursKey, 0, -2) === $theirsKey) {
            return true;
        }

        return str_ends_with($theirsKey, 'ly') && substr($theirsKey, 0, -2) === $oursKey;
    }

    /**
     * Lowercase letters only, so `Slice of Life`, `slice_of_life` and `SLICE-OF-LIFE` all
     * reduce to the same key.
     */
    private static function key(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/i', '', mb_strtolower(trim($value)));
    }
}
