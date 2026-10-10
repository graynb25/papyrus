<?php

namespace App\Import;

use App\Migration\CandidateFinder;
use App\Suwayomi\SeriesLocator;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Collection;

/**
 * Turns a backup's `(source, url)` pairs into Suwayomi manga ids.
 *
 * This is the whole point of the export: {@see ImportedSeries::backupId} is a local
 * autoincrement that means nothing here, so a series can only be written into this
 * library once it is identified by the pair both apps agree on. {@see SeriesGateway}
 * reads a series you have identified; this is what identifies it.
 *
 * ## Two tiers, and why tier 1 goes first
 *
 * Measured on the live server against a reader's real library:
 *
 * | | cost | notes |
 * | --- | --- | --- |
 * | tier 1, in the catalogue | 20 to 91ms | `mangas(filter:)` on `(source, url)` |
 * | tier 2, ask the source | **520ms median** over 7 samples | the `fetchSourceManga` mutation |
 *
 * 1,277 series entirely through tier 2 is about eleven minutes of upstream calls. Tier 1
 * first is not an optimisation, it is how most of an import ends up free, and it works
 * because tier 2 persists what it fetches: ask Suwayomi for a series once and it is in the
 * catalogue for good, so a second import of the same backup is nearly all tier 1.
 *
 * ## The hang guard is a transport failure, not an empty result
 *
 * A source whose site has gone down is the dangerous case. It does not return an error,
 * it returns nothing, and a request that never comes back is worse: 1,277 series at a
 * 28s timeout is ten hours. So the guard is on the thing that is actually about the source
 * rather than the series:
 *
 * - the mutation **throws** (transport failure, timeout, unregistered source) -> this
 *   source is unavailable for the rest of the run, and every series on it is reported
 *   against that one fact rather than being attempted 1,277 times
 * - the mutation **returns nothing** -> this source answered, and this series is not on
 *   it. Another series on the same source may well be, so the source stays live and is
 *   tried again
 *
 * Conflating those two would either hang on a dead source or skip a live one, and they
 * look alike from the outside. Measured on the live server: a source id that does not
 * exist throws `For input string` from inside the server, while a source that exists but
 * has no such series answers cleanly with nothing.
 *
 * ## Why this does not use CandidateFinder
 *
 * {@see CandidateFinder} is the existing title-search-and-rank machinery,
 * and it is the wrong tool on purpose. It deliberately excludes the series' own source
 * (`where('suwayomi_source_id', '!=', ...)`) because migration means "find this somewhere
 * else". An import means "find this exact series on the source it already is on", and the
 * key is the url, not a ranked title. Reusing it would have inverted its central
 * assumption silently.
 */
final class SeriesResolver
{
    /**
     * Sources that could not be asked at all this run, keyed by source id.
     *
     * @var array<int, true>
     */
    private array $unavailableSources = [];

    /**
     * Sources this server has no extension for, keyed by source id.
     *
     * Kept apart from {@see $unavailableSources} because the two need different sentences.
     * A missing extension is something a reader can fix; an unreachable source is not.
     *
     * @var array<int, true>
     */
    private array $missingSources = [];

    /**
     * What happened, for the report. Keyed by the backup's own series id.
     *
     * @var array<int, Resolution>
     */
    private array $resolutions = [];

    /**
     * @param  SeriesLocator  $locator  The one door to Suwayomi for finding a series.
     */
    public function __construct(
        private readonly SeriesLocator $locator,
    ) {}

    /**
     * Resolve one series, tier 1 first and tier 2 only when tier 1 found nothing.
     */
    public function resolve(ImportedSeries $series): Resolution
    {
        $resolution = $this->withoutThisSource($series)
            ?? $this->alreadyInTheCatalogue($series)
            ?? $this->askTheSource($series)
            ?? Resolution::unresolved($this->reasonSourceUnavailable($series));

        $this->resolutions[$series->backupId] = $resolution;

        return $resolution;
    }

    /**
     * Resolve a whole library, keyed by the backup's own series id.
     *
     * Order is preserved so the report reads in the order the backup listed the series,
     * which is the order a reader would recognise from their own shelf.
     *
     * @param  Collection<int, ImportedSeries>  $series
     * @return Collection<int, Resolution>
     */
    public function resolveAll(Collection $series): Collection
    {
        $this->resolutions = [];

        foreach ($series as $one) {
            $this->resolve($one);
        }

        return new Collection($this->resolutions);
    }

    /**
     * How the run went, for a report that says more than "1,277 imported".
     *
     * The numbers a reader needs are separate, because they have different fixes:
     *
     * | count | what it means | what a reader does about it |
     * | --- | --- | --- |
     * | `from_catalogue` | already here, matched on the pair | nothing |
     * | `from_source` | asked the source, and it was there | nothing |
     * | `sources_missing` | this server has no extension for it | install the extension |
     * | `not_found` | asked, and the url has changed | nothing automatic; listed |
     * | `sources_unavailable` | the source could not be reached | try again later |
     *
     * Collapsing those into one count is how an import that resolved 8 of 56 gets reported as
     * a clean run.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $tiers = array_count_values(array_map(
            fn (Resolution $r): string => $r->tier,
            array_values($this->resolutions),
        ));

        return [
            'resolved' => count(array_filter($this->resolutions, fn (Resolution $r): bool => $r->resolved)),
            'from_catalogue' => $tiers['tier 1'] ?? 0,
            'from_source' => $tiers['tier 2'] ?? 0,
            'not_found' => $tiers['unresolved'] ?? 0,
            'ambiguous' => $tiers['ambiguous'] ?? 0,
            /*
             * Series, not sources. `sources_missing` further down counts distinct source ids,
             * which is what a reader needs in order to go install things; this counts the
             * series affected, which is what tells them how much of their library is
             * affected. They are different questions and the same number answers neither.
             */
            'series_on_missing_sources' => $tiers['missing_source'] ?? 0,
            'series_on_unreachable_sources' => $tiers['unreachable_source'] ?? 0,
            'sources_missing' => count($this->missingSources),
            'sources_unavailable' => count($this->unavailableSources),
        ];
    }

    /**
     * The series the resolver could not place, with the reason, for the report.
     *
     * @return Collection<int, Resolution>
     */
    public function unresolved(): Collection
    {
        return new Collection(array_filter(
            $this->resolutions,
            fn (Resolution $r): bool => ! $r->resolved,
        ));
    }

    /**
     * Tier 0: a source this server has no extension for, or has already failed.
     *
     * ## "Not installed" is the constraint, and it is actionable
     *
     * Measured on a reader's real library: 1,277 series across 56 sources, of which this
     * server has extensions for 13. So three quarters of the sources, and most of the
     * series, cannot be asked anything at all.
     *
     * That has to be said as what it is. "Could not be reached" reads as the reader's data
     * being gone or the site being down, and neither is true. The honest sentence names the
     * cause and the fix: this server does not have that source installed, and installing
     * the extension would bring these series across. Anything else teaches a reader that
     * the import lost their data when it did not.
     */
    private function withoutThisSource(ImportedSeries $series): ?Resolution
    {
        if ($this->locator->sourceIsInstalled($series->sourceId) === false) {
            $this->missingSources[$series->sourceId] = true;

            return Resolution::missingSource(
                "This server does not have this series' source installed, so it cannot be looked "
                ."up (source {$series->sourceId}). Installing that extension would let these series "
                .'come across. Nothing about the series itself has gone missing.'
            );
        }

        if (isset($this->unavailableSources[$series->sourceId])) {
            return Resolution::unreachableSource(
                "This series is on a source this server could not reach ({$series->sourceId})."
            );
        }

        return null;
    }

    /**
     * Tier 1: the series is already in the catalogue, matched on the pair.
     */
    private function alreadyInTheCatalogue(ImportedSeries $series): ?Resolution
    {
        $found = $this->locator->findBySourceAndUrl($series->sourceId, $series->url);

        if ($found === null) {
            return null;
        }

        /*
         * More than one match is refusable, not resolvable. Silently taking the first
         * would file a reader's history against whichever of two series came back first,
         * and which one is theirs is a question the data cannot answer.
         */
        if ($found['matches'] > 1) {
            return Resolution::ambiguous(
                "This source holds more than one series on the url {$series->url}, so which one is "
                .'yours cannot be worked out from the backup.'
            );
        }

        return Resolution::fromTier(1, $found);
    }

    /**
     * Tier 2: ask the source for the series, which puts it in the catalogue.
     */
    private function askTheSource(ImportedSeries $series): ?Resolution
    {
        try {
            $hits = $this->locator->searchSource($series->sourceId, $series->title);
        } catch (SuwayomiException) {
            /*
             * A transport failure is a fact about the source, not about this series, so it
             * is remembered once and every other series on that source is reported
             * against it rather than paying for the same timeout again.
             */
            $this->unavailableSources[$series->sourceId] = true;

            return null;
        }

        if ($hits === []) {
            /*
             * The source answered and has nothing here. That is an answer, so this series
             * is unresolved but the source stays live for the rest of the run.
             */
            return Resolution::unresolved(
                "The source has no series at the url {$series->url}. It may have been moved, "
                .'renamed, or removed.'
            );
        }

        /*
         * A title hit is not a match. The source returned something, but the url is the
         * identity, so ask again with the pair, which now exists by the mutation's own
         * doing.
         */
        $again = $this->locator->findBySourceAndUrl($series->sourceId, $series->url);

        if ($again === null) {
            return Resolution::unresolved(
                "The source returned a series called {$series->title} but not at this url, so it is "
                .'not the same series and nothing was imported.'
            );
        }

        return Resolution::fromTier(2, $again);
    }

    private function reasonSourceUnavailable(ImportedSeries $series): string
    {
        return "The source this series came from ({$series->sourceId}) could not be reached. The "
            .'rest of this run will not try it again.';
    }
}
