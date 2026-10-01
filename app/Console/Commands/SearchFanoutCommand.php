<?php

namespace App\Console\Commands;

use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\User;
use App\Search\Deduplicator;
use App\Search\SearchFanout;
use App\Search\TitleNormalizer;
use Illuminate\Console\Command;

/**
 * Runs the cross-source fan-out from the CLI so it can be exercised against a
 * live Suwayomi without a browser or a queue worker.
 *
 * Uses the sync queue driver deliberately: this is a diagnostic, and a job that
 * silently sits in a queue tells you nothing.
 */
class SearchFanoutCommand extends Command
{
    protected $signature = 'search:fanout
                            {query : The search term}
                            {--user=1 : User id to run as}
                            {--lang=* : Restrict to these languages}
                            {--content=* : Restrict to these content ratings}
                            {--include-dead : Do not skip sources marked dead}
                            {--limit=40 : Maximum sources to fan out to}';

    protected $description = 'Fan a search out across every eligible source and print deduplicated results';

    public function handle(SearchFanout $fanout, Deduplicator $deduplicator, TitleNormalizer $normalizer): int
    {
        $user = User::find($this->option('user'));

        if ($user === null) {
            $this->components->error("No user with id {$this->option('user')}.");

            return self::FAILURE;
        }

        $options = [
            'limit' => (int) $this->option('limit'),
            'include_dead' => (bool) $this->option('include-dead'),
        ];

        if ($this->option('lang') !== []) {
            $options['langs'] = $this->option('lang');
        }

        if ($this->option('content') !== []) {
            $options['content'] = $this->option('content');
        }

        $targets = $fanout->targets($user, $options);

        $this->components->twoColumnDetail('Query', (string) $this->argument('query'));
        $this->components->twoColumnDetail('Sources targeted', (string) $targets->count());

        if ($targets->isEmpty()) {
            $this->components->warn('No eligible sources. Run: php artisan sources:sync');

            return self::FAILURE;
        }

        // Bypass the queue so results exist by the time this command returns.
        config(['queue.default' => 'sync']);

        $started = microtime(true);
        $run = $fanout->start($user, (string) $this->argument('query'), $options);
        $elapsed = microtime(true) - $started;

        $run = SearchRun::forUser($user->id)->findOrFail($run->id);

        $this->components->twoColumnDetail('Elapsed', sprintf('%.1fs', $elapsed));
        $this->components->twoColumnDetail('Status', $run->status);
        $this->components->twoColumnDetail('Raw hits', (string) $run->result_count);

        /*
         * Relations inherit the tenant scope, which fails closed when there is
         * no authenticated user - and a console command has none. Everything
         * below names the user explicitly rather than the scope being relaxed
         * for non-request contexts.
         */
        $results = SearchResult::forUser($user->id)
            ->where('search_run_id', $run->id)
            ->get();

        $outcomes = SearchSourceResult::forUser($user->id)
            ->where('search_run_id', $run->id)
            ->orderBy('status')
            ->get();

        $groups = $deduplicator->group($results);
        $multi = $groups->filter(fn ($g): bool => $g->sourceCount() > 1)->count();

        $this->components->twoColumnDetail('After dedupe', (string) $groups->count());
        $this->components->twoColumnDetail('Multi-source series', (string) $multi);

        $this->line('');

        // Per-source outcomes, so a dead source is visible rather than inferred.
        $this->line('  <fg=gray>per source:</>');
        foreach ($outcomes as $outcome) {
            $style = match ($outcome->status) {
                'ok' => 'green',
                'empty' => 'yellow',
                default => 'red',
            };

            $this->line(sprintf(
                '    <fg=%s>%-8s</> %-18s %2d results  %5dms  %s',
                $style,
                $outcome->status,
                mb_substr($outcome->source_name, 0, 18),
                $outcome->result_count,
                $outcome->duration_ms ?? 0,
                mb_substr((string) $outcome->error, 0, 60),
            ));
        }

        $this->line('');
        $this->line('  <fg=gray>deduplicated results:</>');

        foreach ($groups->take(15) as $group) {
            $extra = $group->sourceCount() - 1;
            $badge = $extra > 0 ? " <fg=gray>(+{$extra} more)</>" : '';

            $this->line(sprintf(
                '    %s%s',
                mb_substr($group->primary->title, 0, 60),
                $badge,
            ));
        }

        if ($run->status === SearchRun::PARTIAL) {
            $this->line('');
            $failed = $run->sources_failed;
            $total = $run->sources_total;
            $this->components->warn("{$failed} of {$total} sources did not answer.");
        }

        return self::SUCCESS;
    }
}
