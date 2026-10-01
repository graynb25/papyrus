<?php

namespace App\Library;

use App\Models\LibraryCheck;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\Library;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Re-reads a series from its source and records what came back.
 *
 * ## One code path, two callers
 *
 * A per-card button and a whole-library pass are the same action at two scales, so
 * they are the same method. The button calls {@see check()} inline and answers in
 * about a second, because the measured cost of one refresh is 361ms to 1617ms with
 * a median of 876ms and a reader watching one card can wait for that. The pass calls
 * the same method in a loop, inside a job, and shows progress.
 *
 * ## Why the chapter count is the source's and not ours
 *
 * The number that replaces "Chapters never fetched" is `chapters.totalCount` as the
 * source counts it, not a count of rows we happen to have read state for. AniList's
 * chapter count was measured as a substitute and rejected: 4 of 6 sampled series had
 * no entry at all, and both that did over-count, by +9 and +17, because it counts the
 * original release including chapters no scanlation has.
 *
 * ## Why a "no chapters" answer is not an error
 *
 * Measured: `fetchMangaAndChapters` on a series whose source has nothing throws a
 * bare `java.lang.Exception: No chapters found`, delivered as a GraphQL error on HTTP
 * 200. The gateway already turns that one message into a result rather than an
 * exception, and this class turns it into its own state. A source with no chapters
 * today is a fact about the source, and a reader shown an error would go looking for
 * a fault that is not there.
 *
 * ## What moves the clock and what does not
 *
 * `chapter_list_checked_at` moves on any check the source *answered*, including
 * "I have no chapters". A timeout or a refused mutation leaves it alone, because a
 * request that never landed is not information and must not hold the series out for
 * a whole interval.
 *
 * The distinction was measured rather than assumed. A `no_chapters` check on One
 * Piece left the clock alone at first, so the series stayed due forever and every
 * press re-asked a source that had already said no.
 */
class CheckLedger
{
    public function __construct(
        private readonly SeriesGateway $gateway,
        private readonly Library $library,
    ) {}

    /**
     * Check one series and record the outcome.
     *
     * Never throws. A reader pressing a button should get a row saying what happened,
     * not an error page, and one unreachable source must not stop the other
     * thirteen in a pass.
     */
    public function check(User|int $user, int $mangaId, bool $force = false): LibraryCheck
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $state = SeriesState::forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->first();

        if ($state === null) {
            throw new \InvalidArgumentException(
                "Series {$mangaId} is not in this reader's state, so there is nothing to check."
            );
        }

        if (! $force && ! app(CheckSchedule::class)->isDue($state)) {
            return $this->existingRow($userId, $mangaId) ?? $this->skippedRow($userId, $mangaId);
        }

        // The count we last saw, which is what "new" is measured against. Read
        // before the refresh so a refresh that changes it does not hide the change.
        $previous = LibraryCheck::query()
            ->forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->value('chapter_count');

        try {
            $result = $this->gateway->refresh($mangaId);
        } catch (SuwayomiException $e) {
            return $this->record($userId, $mangaId, LibraryCheck::ERROR, [
                'error' => $this->describe($e),
            ]);
        }

        if ($result['error'] !== null) {
            $noChapters = str_contains(strtolower((string) $result['error']), 'no chapters');

            if ($noChapters) {
                /*
                 * A successful answer, so the clock moves.
                 *
                 * The floor exists to stop an accidental press putting hundreds of
                 * upstream requests into 82 sites, and a source that answers "I have
                 * nothing" is answering. Leaving the timestamp alone would make such a
                 * series due forever, so every press would re-ask a source that has
                 * already said no. Measured on the dev library: MangaDex answered
                 * `No chapters found` for One Piece, which is then re-checked on every
                 * single pass for as long as it stays in the library.
                 *
                 * No fingerprint is written, because there is no chapter list to
                 * describe. `fingerprintMatches` treats a null fingerprint as current,
                 * so this cannot be mistaken for staleness either.
                 */
                SeriesState::forUser($userId)
                    ->where('suwayomi_manga_id', $mangaId)
                    ->update(['chapter_list_checked_at' => now()]);

                return $this->record($userId, $mangaId, LibraryCheck::NO_CHAPTERS, [
                    'error' => (string) $result['error'],
                ]);
            }

            return $this->record($userId, $mangaId, LibraryCheck::ERROR, [
                'error' => (string) $result['error'],
            ]);
        }

        $series = $result['series'] ?? null;

        if (! is_array($series)) {
            return $this->record($userId, $mangaId, LibraryCheck::ERROR, [
                'error' => 'The server returned no series for a refresh that reported no error.',
            ]);
        }

        $chapters = (array) ($series['chapters']['nodes'] ?? []);
        $count = (int) ($series['chapters']['totalCount'] ?? count($chapters));

        /*
         * The fingerprint goes down here and nowhere else until now, which is what
         * makes the library's staleness badge work for the first time. Measured: all
         * 14 series had a null fingerprint before this ran, so `needs_refresh` could
         * never be true and the badge was unreachable.
         */
        $this->library->recordChapterFingerprint($userId, $mangaId, $chapters);

        return $this->record($userId, $mangaId, LibraryCheck::CHECKED, [
            'chapter_count' => $count,
            'new_chapters' => $previous === null ? null : max(0, $count - (int) $previous),
        ]);
    }

    /**
     * Check a slice of what is due, stopping on the time budget.
     *
     * The budget rather than a count, because the cost per series is 361ms to 1617ms
     * and a fixed count overruns the worker on a slow day. Measured against the
     * same rule in the AniList resolver, where a title count could not respect a time
     * limit and the budget was the thing that fixed it.
     *
     * @return array{checked: int, chapters: int, new: int, failed: int, no_chapters: int, budget_out: bool}
     */
    public function checkDue(
        User|int $user,
        int $budgetSeconds = 45,
        int $maxSeries = 60,
        bool $force = false,
    ): array {
        $due = $this->candidates($user, $maxSeries, $force);

        $tally = [
            'checked' => 0,
            'chapters' => 0,
            'new' => 0,
            'failed' => 0,
            'no_chapters' => 0,
            'budget_out' => false,
        ];

        $started = microtime(true);

        foreach ($due as $state) {
            if (microtime(true) - $started >= $budgetSeconds) {
                $tally['budget_out'] = true;

                break;
            }

            $row = $this->check($user, (int) $state->suwayomi_manga_id, $force);
            $tally['checked']++;

            match ($row->state) {
                LibraryCheck::CHECKED => $this->tallySuccess($tally, $row),
                LibraryCheck::NO_CHAPTERS => $tally['no_chapters']++,
                default => $tally['failed']++,
            };
        }

        return $tally;
    }

    /**
     * The series a pass would visit.
     *
     * A forced pass takes the whole library, not what is due. The floor exists to
     * stop an accidental press from putting hundreds of upstream requests into 82
     * sites, and a forced pass is a reader pressing the button on purpose, so
     * applying the floor to it would make the button do nothing for a series checked
     * an hour ago. That is not what forcing is for.
     *
     * @return Collection<int, SeriesState>
     */
    private function candidates(User|int $user, int $maxSeries, bool $force): Collection
    {
        $schedule = app(CheckSchedule::class);
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        if ($force) {
            return SeriesState::forUser($userId)
                ->where('in_library', true)
                ->orderByRaw('chapter_list_checked_at is not null')
                ->orderBy('chapter_list_checked_at')
                ->orderBy('added_at')
                ->limit($maxSeries)
                ->get();
        }

        return $schedule->due($user, $maxSeries);
    }

    /**
     * @param  array{checked: int, chapters: int, new: int, failed: int, no_chapters: int, budget_out: bool}  $tally
     */
    private function tallySuccess(array &$tally, LibraryCheck $row): void
    {
        $tally['chapters'] += (int) $row->chapter_count;

        if ($row->hasNewChapters()) {
            $tally['new'] += (int) $row->new_chapters;
        }
    }

    /**
     * Write the outcome, keeping only the most recent per series.
     *
     * `createFor()` rather than `updateOrCreate()`, because `user_id` is not
     * mass-assignable on an owned model by design and `query()->updateOrCreate()`
     * would raise MissingOwnerException. Found the hard way: the job runs with no
     * authenticated user, so the tenancy fallback finds nothing to fall back to.
     * The attribute is set explicitly by `createFor`, which is also the greppable
     * way to write a row on another reader's behalf.
     *
     * @param  array{error?: string|null, chapter_count?: int, new_chapters?: int|null}  $attributes
     */
    private function record(
        int $userId,
        int $mangaId,
        string $state,
        array $attributes = [],
    ): LibraryCheck {
        $existing = LibraryCheck::query()
            ->forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->first();

        $values = array_merge($attributes, [
            'suwayomi_manga_id' => $mangaId,
            'state' => $state,
            'checked_at' => now(),
        ]);

        if ($existing !== null) {
            $existing->fill($values);
            $existing->save();

            return $existing;
        }

        return LibraryCheck::createFor($userId, $values);
    }

    /**
     * The existing row for a series, if it has one.
     */
    private function existingRow(int $userId, int $mangaId): ?LibraryCheck
    {
        return LibraryCheck::query()
            ->forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->first();
    }

    /**
     * A row recording that the floor kept a series out of the pass.
     *
     * Written rather than returning null, because a reader who pressed "check" and
     * watched a number not move deserves to be told the series was skipped rather
     * than left to work out whether it was visited.
     */
    private function skippedRow(int $userId, int $mangaId): LibraryCheck
    {
        $row = $this->existingRow($userId, $mangaId);

        if ($row !== null) {
            return $row;
        }

        return $this->record($userId, $mangaId, LibraryCheck::SKIPPED, [
            'error' => null,
        ]);
    }

    /**
     * A reader-facing description of a failure.
     *
     * Suwayomi reports a bare exception with no detail, so this adds only what we
     * actually know, which is that the call did not complete.
     */
    private function describe(SuwayomiException $e): string
    {
        $message = trim($e->getMessage());

        return $message === ''
            ? 'The server could not be reached. Nothing was recorded as checked.'
            : $message;
    }

    // Reading ------------------------------------------------------------

    /**
     * The tallies a library page header shows.
     *
     * @return array{checked: int, new: int, failed: int, no_chapters: int, last_checked_at: Carbon|null}
     */
    public function counts(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $rows = LibraryCheck::query()->forUser($userId)->get(['state', 'new_chapters', 'checked_at']);

        $new = 0;

        foreach ($rows as $row) {
            if ($row->hasNewChapters()) {
                $new += (int) $row->new_chapters;
            }
        }

        $last = $rows->max('checked_at');

        return [
            'checked' => $rows->where('state', LibraryCheck::CHECKED)->count(),
            'new' => $new,
            'failed' => $rows->where('state', LibraryCheck::ERROR)->count(),
            'no_chapters' => $rows->where('state', LibraryCheck::NO_CHAPTERS)->count(),
            'last_checked_at' => $last,
        ];
    }

    /**
     * The latest check per series, keyed by manga id.
     *
     * One query for the whole library page, because the card badges ask about every
     * series on the page and asking once each is the mistake the library page
     * already made with its Suwayomi metadata.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, LibraryCheck>
     */
    public function latestFor(User|int $user, array $mangaIds): array
    {
        if ($mangaIds === []) {
            return [];
        }

        $rows = LibraryCheck::query()
            ->forUser($user)
            ->whereIn('suwayomi_manga_id', $mangaIds)
            ->get()
            ->sortByDesc('checked_at')
            ->groupBy('suwayomi_manga_id');

        $latest = [];

        foreach ($rows as $mangaId => $group) {
            $latest[(int) $mangaId] = $group->first();
        }

        return $latest;
    }

    /**
     * Series that turned up chapters on their last check, newest first.
     *
     * @return Collection<int, LibraryCheck>
     */
    public function withNewChapters(User|int $user, int $limit = 20): Collection
    {
        return LibraryCheck::query()
            ->forUser($user)
            ->foundChapters()
            ->withNewChapters()
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Series whose last check failed, newest first.
     *
     * @return Collection<int, LibraryCheck>
     */
    public function failures(User|int $user, int $limit = 20): Collection
    {
        return LibraryCheck::query()
            ->forUser($user)
            ->failed()
            ->orderByDesc('checked_at')
            ->limit($limit)
            ->get();
    }
}
