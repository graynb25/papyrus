<?php

namespace App\Live\Search;

use App\AniList\Preference as AniListPreference;
use App\AniList\TitleResolver;
use App\Models\AniListMedia;
use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\User;
use App\Search\DedupeGroup;
use App\Search\Deduplicator;
use App\Search\SearchFanout;
use App\Search\TitleNormalizer;
use Illuminate\Support\Collection;

/**
 * The search page's reads, kept out of the Livewire component.
 *
 * The component asks these on every render and after an action. Keeping them here lets
 * the run scoping, the grouping opt-in, the ordering, the empty states and the pinned
 * scope be exercised without mounting a page. Reads that depend on another read take
 * the value the component already computed, so a render still makes one run read, one
 * result read and one library read rather than one per helper.
 */
final class SearchPageData
{
    public function __construct(
        private readonly SearchFanout $fanout,
        private readonly TitleResolver $resolver,
        private readonly TitleNormalizer $normalizer,
        private readonly AniListPreference $preference,
        private readonly SearchFormatting $formatting,
    ) {}

    /**
     * The run on screen, looked up for the signed-in reader.
     *
     * Named explicitly: relations inherit the tenant scope, which fails closed for
     * anything without an authenticated user.
     */
    public function run(User $user, ?int $runId): ?SearchRun
    {
        if ($runId === null) {
            return null;
        }

        return SearchRun::forUser($user)->find($runId);
    }

    /**
     * The run's results, deduplicated into one group per series.
     *
     * A resolver only when this reader opted in. That is the whole opt-in: with no
     * resolver the deduplicator keys on titles exactly as it did before AniList
     * existed, so an opted-out reader gets the old behaviour rather than a slower
     * version of the new one.
     *
     * @return Collection<int, DedupeGroup>
     */
    public function groups(User $user, ?SearchRun $run, bool $anilistEnabled): Collection
    {
        if ($run === null) {
            return new Collection;
        }

        $results = SearchResult::forUser($user)
            ->where('search_run_id', $run->id)
            ->get();

        return new Deduplicator(
            $this->normalizer,
            (float) config('papyrus.search.dedupe_fuzzy_threshold', 0.95),
            $anilistEnabled ? $this->resolver : null,
        )->group($results);
    }

    /**
     * Per-source status rows, busiest source first.
     *
     * @return Collection<int, SearchSourceResult>
     */
    public function outcomes(User $user, ?SearchRun $run): Collection
    {
        if ($run === null) {
            return new Collection;
        }

        return SearchSourceResult::forUser($user)
            ->where('search_run_id', $run->id)
            ->orderByDesc('result_count')
            ->get();
    }

    /**
     * Languages with installed sources, most common first, then by code.
     *
     * The live catalogue is extremely long-tailed: roughly 70 of the 80 installed
     * languages have exactly one source each. Rendering every one as a chip produces
     * an unusable wall, so the ones with real choice stay visible and the singleton
     * tail hides behind a disclosure.
     *
     * @return Collection<int, Source>
     */
    public function languageCounts(): Collection
    {
        return Source::query()
            ->installed()
            ->selectRaw('lang, count(*) as total')
            ->groupBy('lang')
            ->orderByDesc('total')
            ->orderBy('lang')
            ->get();
    }

    /**
     * The languages with more than one installed source.
     *
     * @param  Collection<int, Source>  $counts
     * @return Collection<int, Source>
     */
    public function languages(Collection $counts): Collection
    {
        return $this->formatting->languages($counts);
    }

    /**
     * The singleton tail, for a reader who wants one specific language.
     *
     * @param  Collection<int, Source>  $counts
     * @return Collection<int, Source>
     */
    public function rareLanguages(Collection $counts): Collection
    {
        return $this->formatting->rareLanguages($counts);
    }

    /**
     * The runnable sources these filters draw from, after the cap.
     *
     * @param  array<string, mixed>  $options
     */
    public function eligibleSources(User $user, array $options): int
    {
        return $this->fanout->targets($user, $options)->count();
    }

    /**
     * The runnable sources these filters draw from, before the cap.
     *
     * The denominator for the cost line. It comes from the fanout's own universe
     * read, so disabled, dead and filtered-out sources are already excluded exactly
     * as they are for the run itself.
     *
     * @param  array<string, mixed>  $options
     */
    public function runnableSourceCount(User $user, array $options): int
    {
        return $this->fanout->runnableCount($user, $options);
    }

    /**
     * How many runnable sources the cap leaves out.
     *
     * Answered only when no pinned scope is in play. With pins active the skipped
     * list also carries the reader's own narrowing, so calling all of it the cap's
     * cut would be false; the pin read below names that case separately.
     *
     * @param  Collection<int, Source>  $notPinned
     */
    public function sourcesSkippedByCap(int $runnable, int $eligible, Collection $notPinned): int
    {
        if ($notPinned->isNotEmpty()) {
            return 0;
        }

        return max(0, $runnable - $eligible);
    }

    /**
     * The runnable sources this reader's pins leave out of the run, named.
     *
     * The honest half of the scope. With pins active, "no results" must never read
     * as "nobody has this": the reader chose to ask fewer places, and this list says
     * who was passed over. Empty for a reader without pins, and that absence is the
     * frozen half of the rule, because a page with no pins in play renders nothing
     * about pins at all.
     *
     * @param  array<string, mixed>  $options
     * @return Collection<int, Source>
     */
    public function sourcesNotPinned(User $user, array $options): Collection
    {
        return $this->fanout->scopeFor($user, $options)['skipped'];
    }

    /**
     * Everything installed, not the filtered subset.
     *
     * The zero case it answers is "this reader has no source at all", which no filter
     * can produce and no filter can fix. The mirror owns the count for the same reason
     * the sources page computes from the mirror: asking the server for a list on every
     * render measured 185ms against 30ms for the tables that already answer.
     */
    public function installedSourceCount(): int
    {
        return Source::query()->installed()->count();
    }

    /**
     * The sources this run left out, and what each could not do.
     *
     * Only once the run has finished, because a source that has not reported yet is
     * not known to be skipped and listing it early would be a list of guesses.
     *
     * @return array<int, array{source_name: string, reason: string}>
     */
    public function skippedSources(User $user, ?SearchRun $run): array
    {
        if ($run === null || ! $run->isFinished()) {
            return [];
        }

        return SearchSourceResult::forUser($user)
            ->where('search_run_id', $run->id)
            ->where('status', SearchSourceResult::SKIPPED)
            ->orderBy('source_name')
            ->get(['source_name', 'error'])
            ->map(fn (SearchSourceResult $row): array => [
                'source_name' => (string) $row->source_name,
                'reason' => (string) $row->error,
            ])
            ->all();
    }

    /**
     * Whether each result is already in this reader's library, keyed by manga id.
     *
     * Every result in every group, not only the primaries, because the chooser acts on
     * the alternatives too. Still one query for the page.
     *
     * @param  Collection<int, DedupeGroup>  $groups
     * @return array<int, bool>
     */
    public function libraryMap(User $user, Collection $groups): array
    {
        $ids = $groups
            ->flatMap(fn (DedupeGroup $group): array => [
                (int) $group->primary->suwayomi_manga_id,
                ...$group->alternatives->map(fn (SearchResult $row): int => (int) $row->suwayomi_manga_id)->all(),
            ])
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $map = [];

        foreach (SeriesState::forUser($user)->whereIn('suwayomi_manga_id', $ids)->get(['suwayomi_manga_id', 'in_library']) as $state) {
            $map[(int) $state->suwayomi_manga_id] = (bool) $state->in_library;
        }

        return $map;
    }

    /**
     * Every source the chosen group turned up on, primary first.
     *
     * The group is looked up again rather than remembered, so what the chooser lists
     * is what the card is showing and not a copy taken when the click was handled.
     * Looked up by membership, because a search is still streaming when a reader
     * clicks: see {@see DedupeGroup::hasManga()}.
     *
     * @param  Collection<int, DedupeGroup>  $groups
     * @param  array<int, bool>  $library
     * @return array<int, array{manga: int, source: string, title: string, inLibrary: bool, cover: ?string}>
     */
    public function chosenSources(?int $chosenManga, Collection $groups, array $library): array
    {
        if ($chosenManga === null) {
            return [];
        }

        $group = $groups->first(
            fn (DedupeGroup $one): bool => $one->hasManga($chosenManga)
        );

        if ($group === null) {
            return [];
        }

        $rows = [];

        foreach ([$group->primary, ...$group->alternatives->all()] as $row) {
            $mangaId = (int) $row->suwayomi_manga_id;

            $rows[] = [
                'manga' => $mangaId,
                'source' => (string) $row->source_name,
                'title' => (string) $row->title,
                'inLibrary' => (bool) ($library[$mangaId] ?? false),
                'cover' => $row->coverUrl(),
            ];
        }

        return $rows;
    }

    /**
     * The title of the series being chosen between, for the chooser's heading.
     *
     * @param  array<int, array{manga: int, source: string, title: string, inLibrary: bool, cover: ?string}>  $sources
     */
    public function chosenTitle(array $sources): ?string
    {
        return $this->formatting->chosenTitle($sources);
    }

    /**
     * Whether this reader has asked for their titles to be sent to AniList.
     */
    public function anilistEnabled(User $user): bool
    {
        return $this->preference->enabled($user);
    }

    /**
     * The distinct titles in one run.
     *
     * Shared by the progress read and the job dispatch, so both count the same set.
     *
     * @return array<int, string>
     */
    public function resultTitles(User $user, SearchRun $run): array
    {
        return SearchResult::forUser($user)
            ->where('search_run_id', $run->id)
            ->distinct()
            ->pluck('title')
            ->map(fn (mixed $title): string => (string) $title)
            ->all();
    }

    /**
     * How far the AniList lookups for this run have got.
     *
     * @return array{total: int, answered: int} zero total meaning nothing to do
     */
    public function anilistProgress(User $user, ?SearchRun $run, bool $enabled): array
    {
        if (! $enabled || $run === null) {
            return ['total' => 0, 'answered' => 0];
        }

        return $this->resolver->progress($this->resultTitles($user, $run));
    }

    /**
     * The cached AniList media for a result's title, for the content warning.
     *
     * A primary key lookup on a small table, and null for a reader who has not opted
     * in and for a title AniList has never heard of, which is most titles.
     */
    public function anilistMediaFor(string $title, bool $enabled): ?AniListMedia
    {
        if (! $enabled) {
            return null;
        }

        return $this->resolver->mediaFor($title);
    }
}
