<?php

namespace App\Search;

use App\Jobs\SearchSourceJob;
use App\Models\SearchRun;
use App\Models\Source;
use App\Models\SourceHealth;
use App\Models\SourcePreference;
use App\Models\User;
use App\Models\UserSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

/**
 * Starts a cross-source search.
 *
 * Suwayomi has no global search endpoint, so this fans out across sources and
 * then waits. The design consequences are deliberate:
 *
 *  - Results are persisted per source as they land, so a run that is still
 *    going is already useful. Partial results are the normal case.
 *  - Each source gets its own budget, well under the server's 30s cap, so one
 *    slow upstream cannot consume the whole run.
 *  - Sources the ledger has marked dead are skipped by default. A dead source
 *    is not merely slow, it will spend its full timeout every single run.
 *  - Concurrency is bounded, because fanning out to 1,379 sources at once would
 *    take Suwayomi down - its own subscription layer already documents that it
 *    goes unresponsive under concurrent load.
 */
class SearchFanout
{
    public function __construct(
        private readonly QueryParser $parser,
        private readonly FilterVocabulary $vocabulary,
    ) {}

    /**
     * Begin a run and queue one job per target source.
     *
     * @param  array<string, mixed>  $options  langs, content, filters,
     *                                         include_dead, limit
     */
    public function start(User $user, string $query, array $options = []): SearchRun
    {
        $query = trim($query);

        /*
         * Split once, here. Parsing per job would mean 82 parses of one string and 82
         * chances to disagree about what the reader asked for.
         *
         * A filter the reader named in `label:value` form joins anything the caller
         * passed programmatically, so a saved search and a typed one reach the sources
         * by the same route.
         */
        $parsed = $this->parser->parse($query, $this->vocabulary);

        $filters = array_merge($options['filters'] ?? [], $parsed['filters']);

        $sources = $this->targets($user, $options);

        $run = SearchRun::createFor($user, [
            'query' => mb_substr($query, 0, 512),

            /*
             * What was actually sent upstream and what was understood, both stored. A
             * reader who typed `author:isang` and got three results needs to be able to
             * tell whether the source had no such manga or the term was dropped, and
             * those are different places to look next.
             */
            'text_query' => mb_substr($parsed['text'], 0, 512),
            'filters' => $filters === [] ? null : $filters,

            'status' => SearchRun::RUNNING,
            'sources_total' => $sources->count(),
        ]);

        if ($sources->isEmpty()) {
            $run->finalise();

            return $run;
        }

        $jobs = $sources->map(fn (Source $source): SearchSourceJob => new SearchSourceJob(
            runId: $run->id,
            userId: $user->id,
            sourceId: $source->id,

            // The free text, not the raw query: sending `author:isang` to a source that
            // has no author filter would search for the literal string.
            query: $parsed['text'],
            filterValues: $filters,
        ));

        // Bus::batch caps in-flight jobs; withChains would serialise them,
        // which is not what a fan-out is for.
        Bus::batch($jobs)
            ->allowFailures()
            ->dispatch();

        return $run;
    }

    /**
     * Decide which sources this run should hit.
     *
     * Every eligible source is targeted, including all 61 MangaDex language
     * variants, and that is deliberate. Those variants look like 61 requests for one
     * answer and they are not: measured on a "one piece" run they return the same 51
     * manga in different scripts, EN giving 15 Latin titles against JA's 6 Latin and
     * 9 Japanese. Collapsing to one variant per site keeps 64% of results and throws
     * away every non-Latin one. Set cover says 25 variants reach the full union, but
     * which 25 are redundant is a fact about one query rather than about the
     * catalogue, so nothing is dropped.
     *
     * {@see HealthDashboard} groups by site, and that is a display concern. Do not
     * copy the grouping here.
     *
     * @param  array<string, mixed>  $options
     * @return Collection<int, Source>
     */
    public function targets(User $user, array $options = []): Collection
    {
        $query = Source::query()
            ->installed()
            ->when(
                $options['langs'] ?? null,
                fn ($q, $langs) => $q->whereIn('lang', (array) $langs),
            )
            ->when(
                $options['content'] ?? null,
                fn ($q, $content) => $q->whereIn('content_warning', (array) $content),
            )
            ->orderBy('name');

        // Sources the user has explicitly disabled never run.
        $disabled = UserSource::forUser($user->id)
            ->where('disabled', true)
            ->pluck('source_id')
            ->all();

        if ($disabled !== []) {
            $query->whereNotIn('suwayomi_source_id', $disabled);
        }

        // Dead sources are skipped unless explicitly asked for, because each
        // one would otherwise burn a full timeout on every future run.
        if (! ($options['include_dead'] ?? false)) {
            $dead = SourceHealth::query()
                ->where('is_dead', true)
                ->pluck('source_id')
                ->all();

            if ($dead !== []) {
                $query->whereNotIn('suwayomi_source_id', $dead);
            }
        }

        $limit = (int) ($options['limit'] ?? 60);

        return $query->limit($limit)->get();
    }

    /**
     * Filter lists needed to resolve named filters to positions.
     *
     * @param  array<int, int>  $sourceIds
     * @return Collection<int, SourcePreference>
     */
    public function filterCache(array $sourceIds): Collection
    {
        return SourcePreference::query()->whereIn('source_id', $sourceIds)->get();
    }
}
