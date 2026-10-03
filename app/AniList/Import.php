<?php

namespace App\AniList;

use App\Jobs\ImportAniListProgress;
use App\Jobs\ResolveAniListTitles;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\ReadingState;
use App\Suwayomi\SeriesGateway;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Reading progress, pulled from a reader's AniList account into this app.
 *
 * The opposite of {@see Tracking}, and deliberately narrower. Push assumes the
 * reader is telling AniList what just happened here; pull assumes the reader
 * arrived with a history AniList already holds and wants this app to catch up.
 * Three limits keep it safe, and they are stated on the page rather than left
 * to be discovered:
 *
 *  - Only series already in the reader's library are touched. An import never
 *    adds series, because that would fire a fetch per AniList entry, which is
 *    a library the reader did not ask for.
 *  - Nothing is ever marked unread. An import can only move a series forward.
 *  - A series AniList has no entry for is left exactly as it is.
 *
 * ## Why a chain of passes
 *
 * The join runs on the title cache, and a fresh install's cache is empty: every
 * library title needs a lookup, and the measured limit is 30 a minute. A
 * 300 series library is ten minutes of lookups, which is a job chain and not a
 * request. Each pass takes a slice of the library, looks up what the cache
 * cannot answer, marks what it matched, and re-books itself, the same shape
 * {@see ResolveAniListTitles} uses for search.
 *
 * The tally lives in the cache keyed by reader, and the connections page polls
 * it, so progress is visible while it runs. What the run is *doing* lives
 * somewhere else, because both halves of it are bulk and neither is shown: the
 * flattened progress map is one int per series the reader follows and the queue
 * is one id per library series. In the entry that page polls every three
 * seconds, a 300 series library had both of them deserialised on every poll for
 * the full six hours, to draw one progress line and a list of titles.
 */
class Import
{
    /** Series per pass, sized so ten lookups at the measured 2s gap fit a pass. */
    private const SERIES_PER_PASS = 10;

    private const STATE_TTL_HOURS = 6;

    /**
     * How long the "an import is under way" marker lives without a pass arriving.
     *
     * A chain gap and not a chain: a 300 series import is ten minutes of lookups
     * by the measured rate limit alone, which no single lease should have to
     * cover, and every pass re-puts the marker at the top of its own body. What
     * the lease has to outlive is one pass, and it does that with room to spare,
     * because a pass cannot reach {@see worstCaseSeconds()}. What it does not
     * cover is a queue that stops for longer than this: there is then no pass
     * refreshing it and no chain making progress, so a reader who presses the
     * button again is asking for the chain they want, not for a second one.
     *
     * Public only so a test can hold it against {@see worstCaseSeconds()}.
     */
    public const RUNNING_LEASE_MINUTES = 10;

    public function __construct(
        private readonly Tracking $tracking,
        private readonly Client $client,
        private readonly SeriesGateway $series,
        private readonly TitleResolver $titles,
        private readonly ReadingState $reading,
    ) {}

    private function stateKey(int $userId): string
    {
        return "anilist:import:{$userId}:state";
    }

    private function runningKey(int $userId): string
    {
        return "anilist:import:{$userId}:running";
    }

    private function workKey(int $userId): string
    {
        return "anilist:import:{$userId}:work";
    }

    /**
     * The import's tally, or null when this reader has never run one.
     *
     * What the connections page reads and nothing else. Everything a pass needs
     * to *do* lives under {@see workKey()}, so a poll costs a tally rather than
     * the run's working set.
     *
     * @return array<string, mixed>|null
     */
    public function state(int $userId): ?array
    {
        $state = Cache::get($this->stateKey($userId));

        return is_array($state) ? $state : null;
    }

    /**
     * Begin an import, unless one is already going.
     *
     * The gate is a claim and not a question. Asking {@see Cache} whether the
     * marker is there and then writing it is a check-then-act: two requests in
     * the same instant, two tabs or a double click, both see a free marker and
     * both book a chain against one state key. `Cache::add()` writes only when
     * the marker is absent, and it writes it with the lease in the same call,
     * so the claim cannot be lost in between. It is atomic on every store that
     * can be, which is every store production uses.
     *
     * The marker stays a marker rather than becoming a lock over the run, and
     * that is a limit of the primitive rather than a choice: a cache lease is
     * owned by the process that took it, the pass that ends the run runs in a
     * worker, and a worker cannot release a lease held by the request that
     * started the import. So the lease bounds a chain gap (see
     * {@see RUNNING_LEASE_MINUTES}) and the marker is the liveness record, while
     * {@see runPass()} holds a lock over the work itself. Two live chains
     * cannot clobber each other either way, because a pass splices the queue and
     * writes the tally back under that lock.
     */
    public function start(User $user): bool
    {
        $userId = (int) $user->getKey();

        if ($this->tracking->accountFor($userId) === null) {
            return false;
        }

        if (Cache::add($this->runningKey($userId), true, now()->addMinutes(self::RUNNING_LEASE_MINUTES)) === false) {
            return false;
        }

        /*
         * A new run reads the list again and visits every library series, so
         * an unfinished run's leftovers are dropped rather than resumed under a
         * tally that has just been zeroed.
         */
        Cache::forget($this->workKey($userId));

        /*
         * The run's identity, and the total, both fixed here at the moment the reader
         * asked for this run rather than left to the first pass.
         *
         * The identity is what stops a job from an earlier press writing into this run's
         * tally. The job carries a user id and nothing else, so a chain still draining
         * from a previous press could not tell that the tally under it had been reset,
         * and the two would interleave: measured, `processed` reached 30 against a
         * `total` of 20. A stale job now sees a run id that is not its own and leaves
         * the tally alone.
         *
         * The total is here because it used to be a by-product of the first pass, which
         * made "your library holds no series" reachable on a library of twenty. Any pass
         * that ran against a tally it had not initialised finished the run with the zero
         * `start()` had written, and the card reported an empty library for a full one.
         * Counted from the library at press time, the message is only ever shown when it
         * is true.
         */
        $runId = (string) Str::uuid();

        Cache::put($this->stateKey($userId), [
            'run_id' => $runId,
            'phase' => 'running',
            'total' => $this->librarySize($userId),
            'processed' => 0,
            'series_marked' => 0,
            'chapters_marked' => 0,
            'unmatched' => [],
            'finished_at' => null,
        ], now()->addHours(self::STATE_TTL_HOURS));

        ImportAniListProgress::dispatch($userId, $runId);

        return true;
    }

    /**
     * One pass: mark what this slice matched, and say whether more remain.
     *
     * Held under a lock because the whole method is a read-modify-write against one
     * cache key: it reads the tally, splices ten series off the queue, marks them,
     * and writes the tally back. Two passes overlapping clobber each other's
     * `queue` and `processed`, which skips series and double-counts others without
     * any error anywhere. `SearchSourceJob` locks its equivalent pop for the same
     * reason, and the "already running" key in `start()` is a gate on the button
     * rather than a lock on the work, so it does not cover this.
     *
     * Both numbers are 300 seconds, and the lease is the one that matters: a pass
     * cannot outlast it, because {@see worstCaseSeconds()} is what a job allows a
     * pass and that is under the lease. A wait that long is generous on purpose,
     * and the caller cannot tell contention from a hang, so a wait that does run
     * out ends the import with a message rather than blocking a worker for ever.
     *
     * @return bool true when another pass should run
     */
    public function runPass(int $userId, ?string $runId = null): bool
    {
        /*
         * A job from an earlier press. The tally now belongs to a different run, so
         * touching it would splice this run's queue into someone else's tally and
         * double-count against it. Measured doing exactly that before the run id existed:
         * `processed` reached 30 against a `total` of 20.
         *
         * Only a caller that carries a run id is checked. A null means "whichever run is
         * current, or none", which is a test driving the chain directly, and it falls
         * through to `pass()`'s own fresh state exactly as it did before. Making null skip
         * the check would have left the protection one forgotten argument away from gone,
         * which is the shape of bug this feature is about.
         */
        if ($runId !== null) {
            $state = $this->state($userId);

            if ($state === null || ($state['run_id'] ?? null) !== $runId) {
                return false;
            }
        }

        try {
            return Cache::lock("anilist:import:{$userId}:pass", 300)->block(300, fn (): bool => $this->pass($userId));
        } catch (LockTimeoutException) {
            /*
             * Only this one. `block()` rethrows whatever the callback threw, and the pass
             * body talks to both AniList and Suwayomi, so catching `Throwable` here
             * swallowed a rate-limit refusal or a transport failure and reported "could
             * not take its turn", which is both untrue and unactionable. Measured doing
             * exactly that: a real run against a live AniList account reported the lock
             * message rather than the AniList failure underneath it.
             *
             * Everything else belongs to the job, which releases on a refusal and
             * re-books on a transient error, and to `failed()` for the rest.
             */
            $this->fail($userId, 'The import could not take its turn and has stopped. Start it again.');

            return false;
        }
    }

    /**
     * How many series this reader has to import into.
     *
     * Counted at press time so the page's "your library holds no series" is only ever
     * shown when it is true, rather than being inferred from a counter a pass may never
     * have set.
     */
    public function librarySize(int $userId): int
    {
        return SeriesState::forUser($userId)->where('in_library', true)->count();
    }

    /**
     * The run's working state, or null when this run has none yet.
     *
     * Kept out of the tally the page polls, and dropped the moment the run ends
     * rather than outliving it by the state TTL. The absence of this record is
     * also what says "first pass", which is a better signal than the tally's
     * `total` being zero: an empty library is a zero total too.
     *
     * @return array{progress_by_media: array<int, int>, queue: array<int, int>}|null
     */
    private function work(int $userId): ?array
    {
        $work = Cache::get($this->workKey($userId));

        if (! is_array($work)) {
            return null;
        }

        return [
            'progress_by_media' => array_map('intval', (array) ($work['progress_by_media'] ?? [])),
            'queue' => array_map('intval', (array) ($work['queue'] ?? [])),
        ];
    }

    /**
     * The pass itself, under the caller's lock.
     *
     * @return bool true when another pass should run
     */
    private function pass(int $userId): bool
    {
        $account = $this->tracking->accountFor($userId);

        if ($account === null) {
            $this->fail($userId, 'The AniList connection is gone.');

            return false;
        }

        Cache::put($this->runningKey($userId), true, now()->addMinutes(self::RUNNING_LEASE_MINUTES));

        $state = $this->state($userId) ?? $this->freshState();
        $work = $this->work($userId);

        /*
         * The first pass fetches the reader's AniList list in one request and
         * flattens it: the same media can sit in a custom list and a status
         * list, so the largest progress per media id wins.
         */
        if ($work === null) {
            try {
                $data = $this->client->forUser($account->access_token, <<<'GQL'
                    query Imported($id: Int) {
                        MediaListCollection(userId: $id, type: MANGA) {
                            lists {
                                entries {
                                    progress
                                    status
                                    media { id }
                                }
                            }
                        }
                    }
                    GQL, ['id' => $account->anilist_user_id]);
            } catch (AniListTokenRejected) {
                $this->tracking->disconnect($userId);
                $this->fail($userId, 'AniList rejected the saved token. Connect again.');

                return false;
            }

            $work = [
                'progress_by_media' => $this->flatten($data['MediaListCollection']['lists'] ?? []),
                'queue' => SeriesState::forUser($userId)
                    ->where('in_library', true)
                    ->pluck('suwayomi_manga_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all(),
            ];

            $state['total'] = count($work['queue']);
        }

        $started = microtime(true);
        $slice = array_splice($work['queue'], 0, self::SERIES_PER_PASS);

        foreach ($slice as $index => $mangaId) {
            /*
             * The budget and not the count, the same rule the resolver applies
             * and for the same reason: one AniList lookup can cost its 15 second
             * timeout three times over, so ten series cannot be promised to fit
             * anything. A pass that has spent its budget hands the rest of its
             * slice back to the front of the queue and re-books itself, so a slow
             * day costs passes rather than a killed job.
             *
             * Index zero always runs. A budget checked before the first series
             * could find nothing left and hand the same queue straight back, and
             * the chain would re-book itself for ever without touching anything.
             */
            if ($index > 0 && microtime(true) - $started >= self::passBudgetSeconds()) {
                array_splice($work['queue'], 0, 0, array_slice($slice, $index));

                break;
            }

            $this->importOne($userId, $mangaId, $state, $work['progress_by_media']);
        }

        $state['phase'] = $work['queue'] === [] ? 'done' : 'running';

        if ($state['phase'] === 'done') {
            $state['finished_at'] = now()->toDateTimeString();

            Cache::forget($this->runningKey($userId));
            // Nothing reads the working state once the run is over, so it goes
            // rather than sitting in the cache for another six hours.
            Cache::forget($this->workKey($userId));
        } else {
            Cache::put($this->workKey($userId), $work, now()->addHours(self::STATE_TTL_HOURS));
        }

        Cache::put($this->stateKey($userId), $state, now()->addHours(self::STATE_TTL_HOURS));

        return $state['phase'] === 'running';
    }

    /**
     * Match one series and pull its progress forward, if there is any to pull.
     *
     * @param  array<string, mixed>  $state  the tally, mutated in place
     * @param  array<int, int>  $progressByMedia  AniList media id to chapter number
     */
    private function importOne(int $userId, int $mangaId, array &$state, array $progressByMedia): void
    {
        $state['processed']++;

        $map = $this->series->chapterMap($mangaId);

        if ($map === null || $map['title'] === '') {
            return;
        }

        $mediaId = $this->titles->idFor($map['title']);

        if ($mediaId === null) {
            /*
             * The gap before the request it protects, not after the request
             * before it. Consecutive passes are back to back, so the first
             * lookup of a pass is exactly as close to the last lookup of the pass
             * before it as two inside a pass are, and only the gap in front of a
             * request can hold both ends.
             *
             * And only for a title whose answer is not already recorded. Any
             * cached row means `resolve()` reads it and returns without asking
             * anything, and a row under id 0 is the sentinel that says AniList
             * was asked and has never heard of this title. Sleeping for that one
             * meant a permanently unmatched series paid two seconds on every pass
             * it appeared in, for ever, which across a 300 series library is ten
             * minutes of sleeping and not one lookup.
             */
            if ($this->titles->mediaFor($map['title']) === null) {
                sleep((int) config('papyrus.anilist.gap_seconds', 2));
            }

            $media = $this->titles->resolve($map['title']);

            if ($media !== null && (int) $media->anilist_id > 0) {
                $mediaId = (int) $media->anilist_id;
            }
        }

        if ($mediaId === null) {
            $state['unmatched'][] = $map['title'];

            return;
        }

        $progress = $progressByMedia[$mediaId] ?? null;

        if ($progress === null || $progress <= 0) {
            return;
        }

        $target = (int) floor($progress);

        $markable = [];
        foreach ($map['chapters'] as $chapterId => $number) {
            if ($number !== null && $number <= $target) {
                $markable[] = (int) $chapterId;
            }
        }

        if ($markable === []) {
            return;
        }

        $before = $this->reading->chapterStates($userId, $markable);
        $wasRead = count(array_filter($before, fn ($row) => $row->is_read));

        $this->reading->markManyRead($userId, $mangaId, $markable, true);

        $after = $this->reading->chapterStates($userId, $markable);
        $isRead = count(array_filter($after, fn ($row) => $row->is_read));

        if ($isRead <= $wasRead) {
            return;
        }

        $state['series_marked']++;
        $state['chapters_marked'] += $isRead - $wasRead;

        /*
         * The pointer lands on the furthest chapter the import marked, by
         * number, so continue-reading offers what comes after the history
         * rather than chapter one. The sort key is the chapter number and not
         * the chapter id: measured, ids are not ordered the way a series reads.
         */
        $furthestId = null;
        $furthestNumber = -1;

        foreach ($map['chapters'] as $chapterId => $number) {
            if ($number === null || $number > $target) {
                continue;
            }

            $row = $after[(int) $chapterId] ?? null;

            if ($row !== null && $row->is_read && $number > $furthestNumber) {
                $furthestNumber = $number;
                $furthestId = (int) $chapterId;
            }
        }

        if ($furthestId !== null) {
            $this->reading->setPointer($userId, $mangaId, $furthestId);
        }
    }

    /**
     * AniList's list, flattened to media id => highest chapter number it holds.
     *
     * An entry with no chapter number contributes nothing, and that includes a
     * COMPLETED one. AniList's own chapter count is not a stand-in for it, and
     * the push path says so in its own words: the count is the original release
     * including chapters no scanlation has, and it over-counted the sampled
     * series by 9 and by 17. Reading it as progress marks chapters read that
     * the reader never said they had read, and this is the one direction of the
     * feature where guessing cannot be undone, because an import marks forward
     * only.
     *
     * So a completed entry with no number leaves the series alone, which is the
     * same answer `progress = 0` gives and is the honest one: AniList says the
     * reader finished and cannot say how far in our numbering they got.
     *
     * @param  array<int, mixed>  $lists
     * @return array<int, int>
     */
    private function flatten(array $lists): array
    {
        $map = [];

        foreach ($lists as $list) {
            foreach (($list['entries'] ?? []) as $entry) {
                $mediaId = (int) ($entry['media']['id'] ?? 0);
                $progress = $entry['progress'] ?? null;

                if ($mediaId === 0 || ! is_numeric($progress)) {
                    continue;
                }

                $map[$mediaId] = max((int) ($map[$mediaId] ?? 0), (int) $progress);
            }
        }

        return $map;
    }

    /**
     * End an import early, with the reason a reader can act on.
     *
     * Public because {@see ImportAniListProgress::failed()} needs it. That handler is
     * the only path that ends an import which was going fine until the queue stopped
     * cooperating: a pass that exceeds `$timeout` is marked failed by the framework,
     * and so is one that has run out of attempts, and with no worker the chain simply
     * never continues. None of those wrote `phase`, so the connections page kept
     * polling "Checking N of M series" until the six-hour state TTL expired.
     */
    public function fail(int $userId, string $message): void
    {
        Cache::forget($this->runningKey($userId));
        Cache::forget($this->workKey($userId));

        $state = $this->state($userId) ?? $this->freshState();
        $state['phase'] = 'failed';
        $state['error'] = $message;
        $state['finished_at'] = now()->toDateTimeString();

        Cache::put($this->stateKey($userId), $state, now()->addHours(self::STATE_TTL_HOURS));
    }

    /**
     * The most seconds a pass can spend, which is what a job has to allow.
     *
     * Derived from the numbers that bound a pass rather than picked, because the
     * literal this replaced was 120 and ten series at the configured per-call
     * budgets reach well past it: one AniList lookup alone can cost its 15
     * second timeout three times over. A pass that reached that was failed by the
     * framework, with `failOnTimeout` on, while the reader watched a working
     * import.
     *
     * The budget is checked between series and never during one, so the ceiling
     * is the budget plus the most expensive single series:
     *
     *  - the gap in front of a lookup, `anilist.gap_seconds`
     *  - that lookup: `anilist.timeout` an attempt, `anilist.retries` more
     *    attempts for a 429 or a 5xx, and `anilist.retry_delay_ms` between them
     *  - the Suwayomi chapter map at `suwayomi.timeout`, twice, because a
     *    rejected token makes the gateway send the whole call again
     *  - the first pass's list fetch at `anilist.timeout`, which does not retry
     *  - `anilist.pass_budget_seconds`, the time a pass may spend before it looks
     *    at the clock at all
     */
    public static function worstCaseSeconds(): int
    {
        $timeout = (int) config('papyrus.anilist.timeout', 15);
        $attempts = max(1, (int) config('papyrus.anilist.retries', 2) + 1);
        $lookup = $attempts * $timeout
            + ($attempts - 1) * ((int) config('papyrus.anilist.retry_delay_ms', 1500) / 1000);

        return (int) ceil(
            (int) config('papyrus.anilist.gap_seconds', 2)
            + $lookup
            + 2 * (int) config('papyrus.suwayomi.timeout', 28)
            + $timeout
            + self::passBudgetSeconds()
        );
    }

    /**
     * How long a pass may spend before it looks at the clock.
     *
     * The same budget the resolver gives its own pass, read from config rather
     * than restated as a constant, and the largest term in
     * {@see worstCaseSeconds()}.
     */
    private static function passBudgetSeconds(): int
    {
        return (int) config('papyrus.anilist.pass_budget_seconds', 45);
    }

    /**
     * @return array<string, mixed>
     */
    private function freshState(): array
    {
        return [
            // Null, so a tally built outside a run can never be claimed by a job that
            // carries one. Only `start()` mints a run id.
            'run_id' => null,
            'phase' => 'running',
            'total' => 0,
            'processed' => 0,
            'series_marked' => 0,
            'chapters_marked' => 0,
            'unmatched' => [],
            'finished_at' => null,
        ];
    }
}
