<?php

namespace App\Support;

use App\Models\ReadingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The page side of the reading-session ledger.
 *
 * Read only. A render must not write rows, and nothing here does: the totals
 * are sums over what the reader's own chapters produced, not estimates. The
 * number of runs this queries per render is one, grouped in SQL, because the
 * alternative was a loop over rows in the page (the chapterStates lesson).
 *
 * Slices are honest because attribution is coarse: a row contributes its whole
 * reading time to the window it *began* in, never partially to two of them, so
 * no reload or filter change can ever double count; a long sitting begun just
 * before midnight is counted on the day it began.
 */
class ReadingInsights
{
    /**
     * Reading time by series, largest first.
     *
     * Columns the page needs and only those: the pages and the sitting count are
     * here because the tiles beside the totals want them, and one query supplies
     * all of it.
     *
     * @param  CarbonImmutable|null  $since  Null means everything the ledger holds.
     * @return Collection<int, array{manga_id: int, seconds: int, pages: int, sessions: int, last_active_at: CarbonImmutable}>
     */
    public function bySeries(User|int $user, ?CarbonImmutable $since): Collection
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return ReadingSession::forUser($userId)
            ->selectRaw('suwayomi_manga_id, SUM(read_seconds) as seconds, SUM(pages_turned) as pages, COUNT(*) as sessions, MAX(last_active_at) as last_active_at')
            ->when(
                $since,
                fn ($query) => $query->where('started_at', '>=', $since),
            )
            ->groupBy('suwayomi_manga_id')
            ->orderByDesc('seconds')
            ->orderByDesc('last_active_at')
            ->get()
            ->map(fn (ReadingSession $session): array => [
                'manga_id' => (int) $session->suwayomi_manga_id,
                'seconds' => (int) $session->getAttribute('seconds'),
                'pages' => (int) $session->getAttribute('pages'),
                'sessions' => (int) $session->getAttribute('sessions'),
                'last_active_at' => CarbonImmutable::parse($session->getAttribute('last_active_at')),
            ]);
    }
}
