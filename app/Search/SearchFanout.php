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
use Illuminate\Support\Facades\Cache;

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
 *  - Concurrency is bounded by a rolling window of `search.fanout_concurrency`
 *    jobs, because fanning out to 1,379 sources at once would take Suwayomi
 *    down - its own subscription layer already documents that it goes
 *    unresponsive under concurrent load.
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

        /*
         * A rolling window, and it has to be built here rather than trusted from
         * the batch. Bus::batch queues every job it is given and a worker pulls
         * them as fast as it can, so a 40 source run handed to a batch is 40
         * jobs in flight the moment a second worker exists, and the fan-out
         * spent months carrying a comment that claimed otherwise while
         * `search.fanout_concurrency` sat in config read by nobody.
         *
         * The first window goes out now. Each job that finishes dispatches the
         * next pending source ({@see SearchSourceJob::feedNextSource()}), so
         * in-flight work never exceeds the cap whatever the worker count, and
         * no source waits on the slowest member of a wave.
         */
        $cap = max(1, (int) config('papyrus.search.fanout_concurrency', 8));

        $jobs = $sources->map(fn (Source $source): SearchSourceJob => new SearchSourceJob(
            runId: $run->id,
            userId: $user->id,
            sourceId: $source->id,

            // The free text, not the raw query: sending `author:isang` to a source that
            // has no author filter would search for the literal string.
            query: $parsed['text'],
            filterValues: $filters,
        ));

        $pending = $sources->slice($cap)->pluck('id')->values()->all();

        if ($pending !== []) {
            Cache::put(self::pendingKey($run->id), $pending, now()->addHours(6));
        }

        Bus::batch($jobs->take($cap)->values()->all())
            ->allowFailures()
            ->dispatch();

        return $run;
    }

    /**
     * Where a run keeps the sources it has not dispatched yet.
     *
     * Public because the job feeds from it, and the key shape is part of the
     * contract between the two.
     */
    public static function pendingKey(int $runId): string
    {
        return "search:run:{$runId}:pending_sources";
    }

    /**
     * Decide which sources this run should hit.
     *
     * Every eligible source is *candidates*, including all 61 MangaDex language variants,
     * and that is deliberate. Those variants look like 61 requests for one answer and they
     * are not: measured on a "one piece" run they return the same 51 manga in different
     * scripts, EN giving 15 Latin titles against JA's 6 Latin and 9 Japanese. Collapsing to
     * one variant per site keeps 64% of results and throws away every non-Latin one.
     *
     * What the cap may remove is a *slice*, and the slice is now taken round robin over
     * extension packages. This is a correction, not an optimisation. Ordered by name and cut
     * at 40 against 82 sources with 61 of them named "MangaDex (...)", the surviving 40 were
     * ten scanlation sites plus thirty MangaDex languages, so MangaFire (7 sources),
     * MangaTaro, Thunder Scans and Weeb Central were never searched at all. A reader
     * reported that as "search only seems to hit MangaDex and Atsumaru", and the cause was
     * not a bug: nothing was broken, a limit was simply applied to a sort nobody chose.
     *
     * The round robin guarantees every site's first source a slot before any site is asked
     * for a second language, so the sites a reader names are the sites they reach. Which
     * variants fill the later rounds depends on health and on nothing else, which is the
     * honest version of "every variant gets a turn".
     *
     * Grouping here is by extension package and deliberately is not HealthDashboard's
     * grouping. The dashboard groups for display and collapses MangaDex to one tile; this
     * grouping exists only to make the cap fair and must leave every variant queryable.
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

        /*
         * Fetched in full and cut here rather than with a SQL LIMIT, because the round robin
         * needs every site's first row before it can decide what the first row of the result
         * is. The eligible set is the installed catalogue, measured at 82 rows, so the extra
         * rows cost nothing next to the requests this method exists to schedule.
         *
         * The ordering comes from the builder above, which is what makes interleave()
         * deterministic: it depends on each bucket arriving in name order, which in turn
         * means two runs over the same catalogue pick the same sources.
         */
        $eligible = $query->get();

        // SQL reads a negative LIMIT as unlimited and 0 as no rows. Keep that meaning rather
        // than letting take() reinterpret a negative as "all but the last".
        if ($limit < 0) {
            return $eligible;
        }

        if ($limit === 0) {
            return $eligible->take(0);
        }

        return $this->interleave($eligible)->take($limit);
    }

    /**
     * Round robin the sources by extension package.
     *
     * Every package's first source, then every package's second, and so on. Packages keep
     * the order their first source appeared in, and the query already orders by name, so two
     * runs over the same catalogue pick the same sources.
     *
     * A source with no package gets a bucket of its own rather than being folded into a
     * shared one. The local source is the case that matters: it has no extension, and giving
     * it a bucket keyed on the empty string would be harmless only until a second source
     * arrived without a package.
     *
     * @param  Collection<int, Source>  $sources  already ordered by name
     * @return Collection<int, Source>
     */
    private function interleave(Collection $sources): Collection
    {
        /** @var array<string, array<int, Source>> $buckets */
        $buckets = [];

        foreach ($sources as $source) {
            $key = $source->extension_pkg_name !== '' ? $source->extension_pkg_name : 'local';

            $buckets[$key][] = $source;
        }

        $ordered = new Collection;
        $depth = 0;
        $more = true;

        while ($more) {
            $more = false;

            foreach ($buckets as $bucket) {
                if (isset($bucket[$depth])) {
                    $ordered->push($bucket[$depth]);
                    $more = true;
                }
            }

            $depth++;
        }

        return $ordered;
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
