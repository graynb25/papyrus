<?php

namespace App\Support;

use App\Http\Controllers\ReaderController;
use App\Http\Controllers\ReaderProgressController;
use App\Models\ReadingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The ledger of readings: one row per chapter open, extended by the progress
 * ping the reader already posts, closed by the next chapter they open.
 *
 * ## Why this lives in its own class and not ReadingState
 *
 * ReadingState is user state (what the reader has done to the library's pages)
 * and this table is what the reader was doing (when and how much can be sliced
 * by date). chapter_states cannot be the ledger: its read_seconds has no date
 * on it, and rewriting read-time history would be able to lie about anything.
 * A row that is appended once and never edited afterwards is the only shape
 * that keeps both kinds of truth.
 *
 * ## Never on a timer
 *
 * Everything here is an append or a stamp at an event that already exists: the
 * open in {@see ReaderController} and the debounced ping
 * in {@see ReaderProgressController}. A reader gone to
 * lunch accrues nothing, because nothing fires while nothing scrolls, and the
 * one UPDATE on a ping is the same cost family the endpoint already pays.
 *
 * ## The client's clock is not in this table
 *
 * The reader's timestamps could lie and would drift, so an accrual is measured
 * server side as time since this row's own last stamp, never above
 * self::IDLE_CAP_SECONDS. The cap gives the one case a normal ping cadence
 * cannot produce (sampling with a suspended tab) one bounded interval at most,
 * and seconds spent asleep never enter as reading.
 *
 * ## Incognito writes nothing
 *
 * {@see ReadingState::suppresses()} answers for this table too, again here
 * rather than at each call site, for the same reason it guards ReadingState:
 * a session row is the most personal record of having read anything in the app.
 */
class ReadingSessions
{
    /**
     * The most a single ping can contribute. The debounced cadence while
     * reading delivers well under this; the cap exists for the intervals that
     * would otherwise have no ceiling at all.
     */
    public const IDLE_CAP_SECONDS = 60;

    public function __construct(
        private readonly ReadingState $reading,
    ) {}

    /**
     * The chapter is open: close anything still open, then start this one.
     *
     * Closing first is what makes a resume-over-reload safe: the abandoned
     * brother of this row holds only the seconds a stamp already attributed to
     * it, and the fresh row starts from nothing, so no reload can pile the same
     * minutes onto both. It also closes the same gesture a chapter advance is:
     * there is no difference in shape between "moved on" and "opened again".
     */
    public function openFor(User|int $user, int $mangaId, int $chapterId): void
    {
        $userId = UserKey::of($user);

        if ($this->reading->suppresses($user, $mangaId)) {
            return;
        }

        ReadingSession::forUser($userId)
            ->whereNull('ended_at')
            ->update(['ended_at' => DB::raw('last_active_at')]);

        ReadingSession::createFor($userId, [
            'suwayomi_manga_id' => $mangaId,
            'suwayomi_chapter_id' => $chapterId,
            'started_at' => now(),
            'last_active_at' => now(),
            'ended_at' => null,
            'read_seconds' => 0,
            'pages_turned' => 0,
            'last_page_seen' => null,
        ]);
    }

    /**
     * The progress ping: the one moment of authorship a sitting gets.
     *
     * The page number is the reader's furthest page so far, posted only when it
     * moved, so the positive movement between stamps is pages genuinely turned.
     * The very first stamp of a row sets the base position and turns no pages,
     * because a resume from page forty must not arrive as forty pages read.
     *
     * An open row for this chapter is required to exist: a ping without one is
     * a straggler from a view whose row was closed by a newer open, and its
     * seconds belong to no row rather than to the newer open's chapter.
     */
    public function pingFor(User|int $user, int $mangaId, int $chapterId, int $page): void
    {
        $userId = UserKey::of($user);

        if ($this->reading->suppresses($user, $mangaId)) {
            return;
        }

        $session = ReadingSession::forUser($userId)
            ->where('suwayomi_manga_id', $mangaId)
            ->where('suwayomi_chapter_id', $chapterId)
            ->whereNull('ended_at')
            ->first();

        if ($session === null) {
            return;
        }

        $now = now();

        /*
         * Unix timestamps on both sides, never Carbon subtraction: the render
         * timezone and the storage timezone do not have to agree with each
         * other about anything for this to be right (trap 7).
         */
        $elapsed = max(0, $now->getTimestamp() - $session->last_active_at->getTimestamp());
        $session->read_seconds += min($elapsed, self::IDLE_CAP_SECONDS);
        $session->last_active_at = $now;

        $seen = $session->last_page_seen;

        if ($seen === null) {
            $session->last_page_seen = $page;
        } elseif ($page > $seen) {
            $session->pages_turned += $page - $seen;
            $session->last_page_seen = $page;
        }

        $session->save();
    }
}
