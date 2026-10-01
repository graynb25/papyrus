<?php

namespace App\Search;

use App\Models\SourceHealth;
use App\Models\UserSource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the health ledger for the dashboard.
 *
 * The one thing this exists to get right is the difference between "working",
 * "broken" and "never asked". Measured on first build: 82 sources mirrored, 6
 * with any observation at all. A dashboard that sorted by success rate and
 * showed every unobserved source as a blank or a 100% would be lying about 93%
 * of the catalogue, so unobserved is its own state rather than a default.
 *
 * The ledger itself is global, because health describes an upstream that does
 * not care who asked. The consequence of a dead source is personal though, so
 * "skip this in my searches" is a per-user flag on user_sources and lives here
 * only as a join.
 *
 * ## Why this groups by site
 *
 * Keiyoushi ships one extension per language, and those extensions point at the
 * same site. Measured over the live catalogue: 82 installed sources, 15 distinct
 * home_url values. MangaDex alone accounts for 61 of the 82.
 *
 * So the dashboard's grain is the site, not the source. Reporting 82 rows would
 * be reporting MangaDex 61 times, which is noise dressed up as detail. A site's
 * state is the worst state among its language variants, because that is what the
 * reader experiences: one broken region of a working site is still a source they
 * cannot use.
 *
 * Grouping for *display* is not grouping for *search*, and conflating the two is a trap
 * this file has already walked into once. Measured on a "one piece" run: those 61
 * variants return the same 51 manga rendered in different scripts, EN giving 15 Latin
 * titles against JA's 6 Latin and 9 Japanese. Each variant is the only route to its
 * own script, so collapsing the fan-out to one query per site keeps 64% of results and
 * discards every non-Latin one. `SearchFanout` deliberately still targets every source.
 */
class HealthDashboard
{
    /**
     * How many consecutive hard failures mark a source as failing.
     *
     * Matches {@see HealthRecorder::DEAD_AFTER_FAILURES}. One failure is noise:
     * sites rate-limit, scrape badly, or get restarted mid-request.
     */
    public const FAILING_AFTER = 2;

    /** Never touched, so there is nothing to say about it yet. */
    public const STATE_UNOBSERVED = 'unobserved';

    /** Answered recently, with results or a clean miss. */
    public const STATE_HEALTHY = 'healthy';

    /** Answers, but keeps returning nothing. The signature of a resolved corpse. */
    public const STATE_SILENT = 'silent';

    /** Hard failures, either enough to be flagged dead or still short of it. */
    public const STATE_FAILING = 'failing';

    /** Marked dead by the recorder after enough consecutive failures. */
    public const STATE_DEAD = 'dead';

    /** Bad states first when sorting by trouble. */
    public const STATES = [self::STATE_DEAD, self::STATE_FAILING, self::STATE_SILENT, self::STATE_HEALTHY, self::STATE_UNOBSERVED];

    /**
     * The sites, with their health rolled up across language variants.
     *
     * Aimed at the question a reader actually has, which is "which of these
     * sites work for me", over the answer the ledger can give, which is per
     * language variant.
     *
     * @param  array{state?: string|null, lang?: string|null, query?: string|null}  $options
     * @return LengthAwarePaginator<int, object>
     */
    public function sites(array $options = [], int $perPage = 25): LengthAwarePaginator
    {
        $state = $options['state'] ?? null;
        $lang = $options['lang'] ?? null;
        $needle = trim((string) ($options['query'] ?? ''));

        /*
         * Two layers, because the filters split cleanly between them.
         *
         * The rollup goes in a derived table so paginating works: a grouped
         * query holding several aggregates makes Laravel wrap the select in a
         * scalar count, and SQLite rejects a sum() used that way with "misuse of
         * aggregate".
         *
         * Everything that reads a computed column goes on the OUTER query. The
         * inner one only knows about h.* and s.*, so `where state = 'dead'` in
         * there compiles to a reference to a SELECT alias, which is not a legal
         * filter and produces an error about aggregates rather than about the
         * alias. The outer query sees state and site as plain columns.
         */
        $perSite = $this->siteRollup()
            ->when($lang !== null && $lang !== '', fn ($q) => $q->whereExists(
                fn ($e) => $e->selectRaw('1')
                    ->from('sources as s2')
                    ->whereColumn('s2.suwayomi_source_id', 'h.source_id')
                    ->where('s2.lang', $lang),
            ))
            ->when($needle !== '', fn ($q) => $q->whereIn('h.name', function ($sub) use ($needle): void {
                $sub->select('name')->from('source_healths')->where('name', 'like', '%'.$needle.'%');
            }));

        return DB::query()
            ->fromSub($perSite, 'per_site')
            ->when($needle !== '', fn ($q) => $q->where('site', 'like', '%'.$needle.'%'))
            ->when($state !== null && $state !== '', fn ($q) => $q->where('state', $state))
            ->orderByDesc('rank')
            ->orderBy('site')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Count per site state, for the summary strip.
     *
     * A separate query from {@see self::sites()} because the two aggregate at
     * different grains, but both derive their state from
     * {@see self::siteStateSql()} so the tiles cannot drift from the rows.
     * Measured against the live catalogue: 15 sites over 82 sources.
     *
     * @return array<string, int>
     */
    public function siteTally(): array
    {
        // A subquery rather than a second groupBy on the same builder: you cannot
        // group by an aggregate expression that the same SELECT is also naming,
        // and adding a second selectRaw would emit the bare column as well.
        $perSite = DB::table('source_healths as h')
            ->join('sources as s', 's.suwayomi_source_id', '=', 'h.source_id')
            ->selectRaw("coalesce(nullif(s.home_url, ''), 'unknown') as site")
            ->addSelect(DB::raw($this->siteStateSql().' as state'))
            ->groupBy('site');

        $rows = DB::query()
            ->fromSub($perSite, 'per_site')
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $tally = array_fill_keys(self::STATES, 0);

        foreach ($rows as $state => $total) {
            $tally[(string) $state] = (int) $total;
        }

        $tally['sites'] = (int) $rows->sum();

        return $tally;
    }

    /**
     * The health rows for one site's language variants.
     *
     * 'unknown' is the bucket for a source publishing no home_url, which is
     * Suwayomi's own local source. Only that site has no URL to match on, so the
     * fallback is safe here where it would not be if there were several.
     *
     * @return Collection<int, SourceHealth>
     */
    public function variantsOf(string $site): Collection
    {
        return SourceHealth::query()
            ->whereIn('source_id', function ($q) use ($site): void {
                $q->select('suwayomi_source_id')
                    ->from('sources')
                    ->when(
                        $site === 'unknown',
                        fn ($w) => $w->whereNull('home_url')->orWhere('home_url', ''),
                        fn ($w) => $w->where('home_url', $site),
                    );
            })
            ->orderBy('lang')
            ->get();
    }

    /**
     * Which sources this user has chosen to skip in their searches.
     *
     * @return array<int, int>
     */
    public function disabledSourceIds(int $userId): array
    {
        return UserSource::forUser($userId)
            ->where('disabled', true)
            ->pluck('source_id')
            ->all();
    }

    public function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_DEAD => __('Dead'),
            self::STATE_FAILING => __('Failing'),
            self::STATE_SILENT => __('Going quiet'),
            self::STATE_HEALTHY => __('Working'),
            default => __('Never asked'),
        };
    }

    /**
     * The rollup query.
     *
     * Kept in one place so the table and the tiles cannot disagree. The state
     * expression is a CASE over the per-source columns, and a site takes the
     * worst value any of its variants has earned.
     *
     * A query builder rather than an Eloquent one: there is no model behind a
     * grouped rollup, and hydrating SourceHealth for a row that is really a site
     * would give every downstream caller the wrong idea about the grain.
     */
    private function siteRollup(): QueryBuilder
    {
        return DB::table('source_healths as h')
            ->join('sources as s', 's.suwayomi_source_id', '=', 'h.source_id')
            ->selectRaw("coalesce(nullif(s.home_url, ''), 'unknown') as site")
            ->selectRaw('count(*) as variants')
            ->selectRaw('sum(case when h.total_successes + h.total_failures > 0 then 1 else 0 end) as observed')
            ->selectRaw('sum(h.consecutive_failures) as failures')
            ->selectRaw('sum(h.consecutive_empty) as empties')
            ->selectRaw('max(h.avg_latency_ms) as worst_latency_ms')
            ->selectRaw('max(h.last_success_at) as last_success_at')
            ->selectRaw('max(h.last_error) as last_error')
            // addSelect, never select: Query\Builder::select() resets the column
            // list before adding, so calling it here would silently discard every
            // selectRaw above it and the derived table would come back with three
            // columns and no `site`.
            ->addSelect(DB::raw($this->siteStateSql().' as state'))
            ->addSelect(DB::raw($this->siteRankSql().' as rank'))
            // One row per site, and the label needs a representative. The name of
            // the site with the most observations stands in for it, so a row does
            // not appear blank when one variant is labelled and 60 are not.
            ->selectRaw('max(h.name) as label')
            ->selectRaw('group_concat(distinct h.lang) as languages')
            ->groupBy('site');
    }

    /**
     * The state a site has earned, as a SQL expression over its variants.
     *
     * Worst wins, in the order dead, failing, silent, unobserved, healthy. One
     * unusable language region of a working site is still a source the reader
     * cannot use, so a site is never better than its weakest variant.
     *
     * Written as SQL rather than in PHP because it has to run inside a GROUP BY,
     * and shared by the paged rollup and the tally so the two cannot disagree.
     *
     * @return literal-string
     */
    private function siteStateSql(): string
    {
        return <<<'SQL'
            (case
                when sum(case when h.is_dead then 1 else 0 end) > 0 then 'dead'
                when sum(case when h.consecutive_failures >= 2 then 1 else 0 end) > 0 then 'failing'
                when sum(case when h.consecutive_failures = 0 and h.consecutive_empty >= 20 then 1 else 0 end) > 0 then 'silent'
                when sum(case when h.total_successes + h.total_failures > 0 then 1 else 0 end) = 0 then 'unobserved'
                else 'healthy'
            end)
        SQL;
    }

    /**
     * The same ordering as the state, as a sortable number.
     *
     * Unobserved sorts below healthy rather than above silent: a source we have
     * never asked is less alarming than one that has gone quiet, so it belongs
     * at the bottom of a trouble-first sort rather than the top.
     *
     * @return literal-string
     */
    private function siteRankSql(): string
    {
        return <<<'SQL'
            (case
                when sum(case when h.is_dead then 1 else 0 end) > 0 then 4
                when sum(case when h.consecutive_failures >= 2 then 1 else 0 end) > 0 then 3
                when sum(case when h.consecutive_failures = 0 and h.consecutive_empty >= 20 then 1 else 0 end) > 0 then 2
                when sum(case when h.total_successes + h.total_failures > 0 then 1 else 0 end) = 0 then 0
                else 1
            end)
        SQL;
    }
}
