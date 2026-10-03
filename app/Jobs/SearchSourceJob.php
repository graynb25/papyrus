<?php

namespace App\Jobs;

use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\Source;
use App\Search\FilterVocabulary;
use App\Search\HealthRecorder;
use App\Search\SearchFanout;
use App\Search\SourceSearcher;
use App\Search\TitleNormalizer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Searches one source on behalf of one run, and records what happened.
 *
 * The job never throws for a source-level failure. A run that reports "3 of 40
 * sources timed out" is a useful run; a run that dies is not.
 */
class SearchSourceJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    /**
     * A single search is a single attempt. Retrying a source that already
     * timed out just spends the run's budget twice.
     */
    /**
     * @param  array<string, mixed>  $filterValues  named filter values, resolved
     *                                              to positions at send time
     */
    public function __construct(
        public readonly int $runId,
        public readonly int $userId,
        public readonly int $sourceId,
        public readonly string $query,
        public readonly array $filterValues = [],
    ) {}

    public function handle(
        SourceSearcher $searcher,
        TitleNormalizer $normalizer,
        HealthRecorder $health,
        FilterVocabulary $vocabulary,
    ): void {
        $run = SearchRun::forAllUsers()->find($this->runId);
        $source = Source::find($this->sourceId);

        if ($run === null) {
            /*
             * The run row is gone, so nothing here can be recorded, and there is
             * nothing to finalise. The batch is still live though, and every
             * source pending behind this job would wait on a slot that is never
             * freed. Roll the window on: each drained job lands here too and
             * hands on in turn, so the batch finishes instead of hanging.
             */
            $this->feedNextSource();

            return;
        }

        /*
         * A retry re-enters this method. The first attempt already committed its
         * outcome inside its own transaction, and what threw afterwards was the
         * batch's bookkeeping rather than the search, so recording a second time
         * would push `sources_done` past `sources_total` and stamp a run that
         * returned every result as `partial`. The result row is the record of
         * whether this source reported, which is the same guard `failed()` uses
         * and for the same reason.
         */
        if ($this->alreadyRecorded($run, $source)) {
            $this->feedNextSource();
            $this->finishIfDone($run);

            return;
        }

        if ($source === null) {
            /*
             * A source row deleted mid-run is not a reason to strand every source
             * still pending behind it, and it is not a failure either. Counted the
             * way a skip is counted, so the run's denominator still balances: the
             * old code fed the next source and returned, leaving `sources_done`
             * one short of `sources_total` for ever, which is the hang
             * `finishIfDone()` exists to prevent.
             */
            DB::transaction(function () use ($run): void {
                SearchSourceResult::createFor($this->userId, [
                    'search_run_id' => $run->id,
                    'suwayomi_source_id' => 0,
                    'source_name' => 'Unknown',
                    'status' => SearchSourceResult::SKIPPED,
                    'result_count' => 0,
                    'duration_ms' => 0,
                    'error' => 'The source was removed while this run was in flight.',
                ]);

                $run->sources_done++;
                $run->sources_skipped++;
                $run->save();
            });

            $this->feedNextSource();
            $this->finishIfDone($run);

            return;
        }

        /*
         * Which of the reader's terms this source can be *asked* about, by matching our
         * terms against the source's own filter names.
         *
         * `filtersFor()` lives on the searcher because that is where the filter list is
         * already read and cached from; asking again here would double the request count.
         */
        $plan = $searcher->plan($source, $this->filterValues, $vocabulary);

        /*
         * A source that cannot express one of the reader's filters is left out of the
         * run and counted, rather than asked for unfiltered rows.
         *
         * Asking anyway means the reader gets back things that do not match what they
         * asked for with no way to tell which those are. Checking the returned rows
         * instead is what this did first, and it is not possible: measured across 704
         * search rows from 76 sources, `author` is missing on 90% and `status` reads
         * UNKNOWN on 99%, so a post-filter there discards nearly the whole source and the
         * run reads as broken.
         *
         * Recorded rather than dropped, because a term that quietly removes a third of
         * the sources is exactly the thing a reader needs to be told about.
         */
        if (! $plan->canRun()) {
            DB::transaction(function () use ($run, $source, $plan): void {
                SearchSourceResult::createFor($this->userId, [
                    'search_run_id' => $run->id,
                    'suwayomi_source_id' => $source->suwayomi_source_id,
                    'source_name' => $source->label(),
                    'status' => SearchSourceResult::SKIPPED,
                    'result_count' => 0,
                    'duration_ms' => 0,
                    'error' => $plan->reason(),
                ]);

                $run->sources_done++;
                $run->sources_skipped++;
                $run->save();
            });

            $this->feedNextSource();

            $this->finishIfDone($run);

            return;
        }

        $outcome = $searcher->search($source, $this->query, $plan->toArray());

        $results = $outcome->results;

        /*
         * `$results` is captured rather than recomputed inside. `SearchSourceResult`'s
         * count is the source's own, which is the only count that says anything about
         * whether the source is healthy.
         */
        DB::transaction(function () use ($run, $source, $outcome, $normalizer, $health, $results): void {
            SearchSourceResult::createFor($this->userId, [
                'search_run_id' => $run->id,
                'suwayomi_source_id' => $source->suwayomi_source_id,
                'source_name' => $source->label(),
                'status' => $outcome->status,
                'result_count' => $outcome->count(),
                'duration_ms' => $outcome->durationMs,
                'error' => $outcome->error,
            ]);

            foreach ($results as $manga) {
                $title = (string) ($manga['title'] ?? '');

                if ($title === '') {
                    continue;
                }

                SearchResult::createFor($this->userId, [
                    'search_run_id' => $run->id,
                    'suwayomi_manga_id' => (int) ($manga['id'] ?? 0),
                    'suwayomi_source_id' => (int) ($manga['source']['id'] ?? $source->suwayomi_source_id),
                    'title' => $title,
                    'normalized_title' => $normalizer->normalise($title),
                    'url' => $manga['url'] ?? null,
                    'thumbnail_url' => $manga['thumbnailUrl'] ?? null,
                    'source_name' => $source->label(),
                    'content_warning' => $source->content_warning,
                    'status' => isset($manga['status']) ? (string) $manga['status'] : null,
                    'genre' => is_array($manga['genre'] ?? null) ? $manga['genre'] : null,

                    // Present on about a tenth of search rows and shown when it is,
                    // because a reader scanning near matches often decides by who drew
                    // them. Not a filter, and nothing is checked against it.
                    'author' => isset($manga['author']) ? mb_substr((string) $manga['author'], 0, 255) : null,
                ]);
            }

            $run->sources_done++;
            $run->result_count += $outcome->count();

            if ($outcome->isFailure()) {
                $run->sources_failed++;
            }

            $run->save();

            /*
             * The outcome's own count is what the source returned, so health is recorded
             * against the source and never against our filtering. A source that answered
             * fifteen rows is a healthy source whatever we did with them.
             */
            $health->record($source, $outcome);
        });

        $this->feedNextSource();

        $this->finishIfDone($run);
    }

    /**
     * Has this source already reported against this run?
     *
     * Two callers need it and they need it for the same reason. `handle()` re-enters
     * on a retry, when the first attempt already committed. `failed()` reaches a job
     * that may have thrown after committing too, and must not record twice. Both
     * guard on the row rather than on how the code got there, because "how it got
     * there" is exactly what a retry makes unknowable.
     *
     * Scoped explicitly to this job's reader, and that detail is the whole reason the
     * guard works. `SearchSourceResult` is an `OwnedModel`, so a bare `::query()`
     * still carries `OwnedByUser`, which resolves against `auth()`. A queue job has no
     * authenticated user, so the scope matched nothing and the guard always
     * said "not recorded". Written as a plain `::query()` it was silently inert, both
     * here and in `failed()`, which is why a retry still counted its source twice.
     */
    private function alreadyRecorded(SearchRun $run, ?Source $source): bool
    {
        return SearchSourceResult::forUser($this->userId)
            ->where('search_run_id', $run->id)
            ->where('suwayomi_source_id', $source === null ? 0 : $source->suwayomi_source_id)
            ->exists();
    }

    /**
     * Dispatch the next pending source, keeping the run's window full.
     *
     * The fan-out's cap is this method: a batch queues everything it is given,
     * so {@see SearchFanout} dispatches only the first window and
     * every job that finishes hands the next pending source to the batch. The
     * pop is under a lock because two workers can finish two sources at the
     * same moment, and both must not take the same pending row.
     *
     * A feed that cannot run is logged and swallowed, never thrown: the job
     * has already recorded its source's outcome by then, and a throw here would
     * land in failed() and record it twice.
     *
     * It is not simply swallowed, because a swallowed feed is a hang. The pop never
     * happened, so the source is still pending but no job will ever claim it, and if
     * this was the last job to finish then `sources_done` can never reach
     * `sources_total` and the run sits at "39 of 40" for ever. So a feed that cannot
     * take the lock spends its own slot instead: the run is told a source went
     * undispatched, the shortfall is visible rather than silent, and the run
     * terminates. Blocking longer is the cheaper half of the answer and comes first.
     */
    private function feedNextSource(): void
    {
        $batch = $this->batch();

        if ($batch === null || $batch->cancelled()) {
            return;
        }

        $key = SearchFanout::pendingKey($this->runId);

        try {
            $sourceId = Cache::lock("search:run:{$this->runId}:feed", 30)->block(30, function () use ($key): ?int {
                $pending = Cache::get($key, []);

                if ($pending === []) {
                    return null;
                }

                $next = (int) array_shift($pending);

                if ($pending === []) {
                    Cache::forget($key);
                } else {
                    Cache::put($key, $pending, now()->addHours(6));
                }

                return $next;
            });

            if ($sourceId === null) {
                return;
            }

            $batch->add(new self(
                runId: $this->runId,
                userId: $this->userId,
                sourceId: $sourceId,
                query: $this->query,
                filterValues: $this->filterValues,
            ));

            return;
        } catch (Throwable $e) {
            Log::warning('Search fan-out could not dispatch the next pending source.', [
                'run' => $this->runId,
                'error' => $e->getMessage(),
            ]);
        }

        /*
         * The feed failed. Rather than leave the run short a source for ever, record
         * the shortfall against it and drop what is left, so the counter balances and
         * the run finalises. The cost is that a source can go unsearched in a lock
         * storm. The alternative was worse: a run that never finishes, which is what
         * the reader actually sees.
         */
        $this->recordUndispatched();
    }

    /**
     * Account for a source that could not be dispatched, and let the run finish.
     */
    private function recordUndispatched(): void
    {
        $run = SearchRun::forAllUsers()->find($this->runId);

        if ($run === null || $run->sources_done >= $run->sources_total) {
            return;
        }

        try {
            DB::transaction(function () use ($run): void {
                SearchSourceResult::createFor($this->userId, [
                    'search_run_id' => $run->id,
                    'suwayomi_source_id' => 0,
                    'source_name' => 'Fan-out',
                    'status' => SearchSourceResult::SKIPPED,
                    'result_count' => 0,
                    'duration_ms' => 0,
                    'error' => 'Not dispatched: the fan-out could not hand on the next source.',
                ]);

                $run->sources_done++;
                $run->sources_skipped++;
                $run->save();
            });
        } catch (Throwable $e) {
            Log::warning('Search fan-out could not record an undispatched source.', [
                'run' => $this->runId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->finishIfDone($run);
    }

    /**
     * Close the run once every source has reported, skipped included.
     *
     * Shared rather than repeated, because the skip path has to count the same way the
     * search path does. A skip that forgets to advance the counter leaves the run at
     * "38 of 40 sources" for ever, which looks like a hang.
     *
     * @param  SearchRun  $run  the instance already loaded, so its counters are current
     */
    private function finishIfDone(SearchRun $run): void
    {
        if ($run->sources_done >= $run->sources_total) {
            $run->finalise();
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Only reachable if the job itself blew up, which is a bug rather than
        // a source failure. Record it so the run still completes.
        $run = SearchRun::forAllUsers()->find($this->runId);

        if ($run === null) {
            return;
        }

        $source = Source::find($this->sourceId);

        /*
         * A job that threw after already recording its outcome must not record
         * twice, so the guard is on the row rather than on how it got here.
         * Either way the window rolls on: a job that died is a slot that freed.
         */
        if (! $this->alreadyRecorded($run, $source)) {
            SearchSourceResult::createFor($this->userId, [
                'search_run_id' => $run->id,
                'suwayomi_source_id' => $source === null ? 0 : $source->suwayomi_source_id,
                'source_name' => $source?->label() ?? 'Unknown',
                'status' => SearchSourceResult::ERROR,
                'result_count' => 0,
                'error' => mb_substr($exception?->getMessage() ?? 'Job failed.', 0, 500),
            ]);

            $run->sources_done++;
            $run->sources_failed++;
            $run->save();
        }

        $this->feedNextSource();

        $this->finishIfDone($run);
    }
}
