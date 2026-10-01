<?php

namespace App\Jobs;

use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\Source;
use App\Search\FilterVocabulary;
use App\Search\HealthRecorder;
use App\Search\SourceSearcher;
use App\Search\TitleNormalizer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

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

        if ($run === null || $source === null) {
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

    public function failed(?\Throwable $exception): void
    {
        // Only reachable if the job itself blew up, which is a bug rather than
        // a source failure. Record it so the run still completes.
        $run = SearchRun::forAllUsers()->find($this->runId);

        if ($run === null) {
            return;
        }

        $source = Source::find($this->sourceId);

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

        if ($run->sources_done >= $run->sources_total) {
            $run->finalise();
        }
    }
}
