<?php

namespace App\Search;

/**
 * What one source can be asked, for one run's filter terms.
 *
 * ## Why this is a value rather than two returns
 *
 * Resolving a reader's terms against a source yields three things that have to travel
 * together: what to push, which of our terms the source cannot express at all, and which
 * it cannot honour but can be left alone about. A pair of returns loses that grouping and
 * the caller has to recompute it, which is how a source ends up skipped for something that
 * was only ever a sort order.
 *
 * ## Why a source that cannot filter is skipped rather than asked anyway
 *
 * Two alternatives, both worse.
 *
 * Ask it unfiltered and the reader gets rows that do not match what they asked for, with
 * no way to tell which rows those are. Post-filter the rows instead, which was the first
 * design, and there is nothing to check against: measured across 704 search rows from 76
 * sources, `author` is missing on 90% of them and `status` reads `UNKNOWN` on 99%. A
 * post-filter there throws away nearly the whole source and the run reads as broken.
 *
 * Skipping is exact. Every row a run returns honours every filter the reader typed, and
 * the cost is a number the page can show rather than a silence the reader has to notice.
 *
 * ## An order never blocks a source
 *
 * `sort` changes the order rows arrive in, not which rows arrive. A source that cannot
 * sort still has perfectly good results in its own order, and dropping them to enforce an
 * ordering the reader did not strictly need would be the worse trade by a long way.
 *
 * ## Having the filter is not enough, and finding that out cost five sources
 *
 * A term is pushed by name, but a name does not say what kind of value belongs with it. A
 * `SelectFilter` wants an integer index into its own options and a `SortFilter` wants
 * `{index, ascending}`; a plain string becomes `textState`, which those two reject.
 * Measured on the box: `Expected select state change at position 16`, `Expected group
 * change at position 9` and `Expected sort state change at position 5`, on MangaFire,
 * Thunder Scans, MangaTaro, Atsumaru and Weeb Central. All five were our mistake, not
 * theirs.
 *
 * `FilterValue` maps our value onto the source's own option, and a value it has no option
 * for counts as a filter the source cannot express, so the source is skipped and counted
 * like any other. Guessing the nearest option would apply a filter the reader did not ask
 * for, on a source whose option names nobody else has seen.
 *
 * ## A group is the other shape, and it is the common one
 *
 * Measured across the 82 installed sources, a term's filter is a `GroupFilter` more often
 * than a plain select, and MangaDex, at 61 of the 82, uses one for every term: Status is a
 * group of checkboxes, one per value, and Genre and Theme are groups of three-way toggles.
 *
 * In a group our value is a *label* rather than an option to look up, so the child whose
 * name matches is the filter, and the child's kind decides the state. Keying the group
 * itself, which is what this did first, produced `Expected group change at position 4` on
 * every MangaDex variant.
 */
final class FilterPlan
{
    /**
     * @param  array<string, int|bool|array{index: int, ascending: bool}|string>  $pushable  keyed by the source's own filter name, holding state already typed for that filter's kind
     * @param  array<int, string>  $unfilterable  our filter terms this source cannot express
     * @param  array<int, string>  $unsortable  our order terms this source cannot honour
     */
    private function __construct(
        public readonly array $pushable,
        public readonly array $unfilterable,
        public readonly array $unsortable,
    ) {}

    /**
     * @param  array<string, string>  $values  our term to value
     * @param  array<int, array<string, mixed>>  $filters  the source's own filter list
     */
    public static function resolve(array $values, array $filters, FilterVocabulary $vocabulary): self
    {
        $pushable = [];
        $unfilterable = [];
        $unsortable = [];

        foreach ($values as $term => $value) {
            $filter = $vocabulary->sourceFilter((string) $term, $filters);

            /*
             * A `GroupFilter` is a box with one child per value, so our value selects a
             * child rather than an option inside one. MangaDex is the case that matters,
             * being 61 of the 82 installed sources: it models Status as a group of
             * checkboxes, one per value, and Genre as a group of three-way toggles.
             *
             * The child is what gets keyed, because `SourceFilterTranslator` addresses a
             * group child by its own name and emits the nested change. Keying the group
             * instead produced `Expected group change at position 4` on every variant.
             */
            if ($filter !== null && (string) ($filter['__typename'] ?? '') === 'GroupFilter') {
                $child = FilterValue::childFor((string) $value, array_values($filter['filters'] ?? []));

                $filter = $child;
                $state = $child === null ? null : FilterValue::stateForChild($child);
            } else {
                $state = $filter === null ? null : FilterValue::resolve((string) $value, $filter);
            }

            if ($filter === null || $state === null) {
                /*
                 * Either there is no such filter, or there is one and it has no option
                 * for what was asked. Both mean the same thing to a reader: this source
                 * cannot deliver this term.
                 *
                 * A source declaring `ONGOING`, `COMPLETED` and `PAUSED` cannot be asked
                 * for `hiatus`, and sending it anyway is what produced `Expected select
                 * state change at position 16` across five sources.
                 */
                if ($vocabulary->isFilter((string) $term)) {
                    $unfilterable[] = (string) $term;
                } else {
                    $unsortable[] = (string) $term;
                }

                continue;
            }

            // Keyed by the source's own name, because SourceFilterTranslator resolves a
            // name to a volatile position and refuses a name it has not seen.
            $pushable[(string) ($filter['name'] ?? '')] = $state;
        }

        return new self($pushable, $unfilterable, $unsortable);
    }

    /**
     * Whether this source can contribute to the run at all.
     */
    public function canRun(): bool
    {
        return $this->unfilterable === [];
    }

    /**
     * A sentence for the ledger's error column, or null when there is nothing to say.
     */
    public function reason(): ?string
    {
        if ($this->canRun()) {
            return null;
        }

        return 'Cannot filter by '.implode(' or ', $this->unfilterable).'.';
    }

    /**
     * @return array<string, int|bool|array{index: int, ascending: bool}|string>
     */
    public function toArray(): array
    {
        return $this->pushable;
    }
}
