<?php

namespace App\Live\Search;

use App\Models\SearchRun;
use App\Models\Source;
use App\Support\LanguageName;
use Illuminate\Support\Collection;

/**
 * The search page's pure rules, kept out of the Livewire component.
 *
 * Everything here is a function of its arguments: the language name, the stalled
 * worker boundary, the chooser's heading and the two language buckets. No query and
 * no reader state belongs here, which is what lets the rules be exercised without
 * mounting a page.
 */
final class SearchFormatting
{
    /**
     * The human name for a language code.
     */
    public function languageName(string $lang): string
    {
        return LanguageName::name($lang);
    }

    /**
     * A run can only finish if a worker is draining the queue.
     *
     * On a fresh install there may not be one, and the run would sit at zero forever
     * looking like a hang. Saying so plainly beats an indefinite spinner. The boundary
     * is eight seconds, more than the page's three second poll and less than a reader's
     * patience; a run that has answered any source is moving and is not stalled.
     */
    public function queueStalled(?SearchRun $run): bool
    {
        if ($run === null || $run->isFinished() || $run->sources_done > 0) {
            return false;
        }

        $created = $run->created_at;

        if ($created === null) {
            return false;
        }

        return $created->diffInSeconds(now()) > 8;
    }

    /**
     * The title of the series being chosen between, for the chooser's heading.
     *
     * @param  array<int, array{manga: int, source: string, title: string, inLibrary: bool, cover: ?string}>  $sources
     */
    public function chosenTitle(array $sources): ?string
    {
        return $sources[0]['title'] ?? null;
    }

    /**
     * Languages with more than one installed source.
     *
     * More than one, not two or more: the page offers a choice where there is one, and
     * a language with a single source has nothing to choose between.
     *
     * @param  Collection<int, Source>  $counts
     * @return Collection<int, Source>
     */
    public function languages(Collection $counts): Collection
    {
        return $counts
            ->filter(fn (Source $row): bool => (int) $row->getAttribute('total') > 1)
            ->values();
    }

    /**
     * The singleton tail, for a reader who wants one specific language.
     *
     * Exactly one, so the two buckets are a partition of the counts: a language with no
     * installed source is not in the count read at all, and every row it returns is in
     * one bucket or the other.
     *
     * @param  Collection<int, Source>  $counts
     * @return Collection<int, Source>
     */
    public function rareLanguages(Collection $counts): Collection
    {
        return $counts
            ->filter(fn (Source $row): bool => (int) $row->getAttribute('total') === 1)
            ->values();
    }
}
