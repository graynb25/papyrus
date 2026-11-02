<?php

namespace App\Support;

use App\Models\ReadingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The page side of the two reading ledgers.
 *
 * Read only. A render must not write rows, and nothing here does: the native totals
 * are sums over what the reader's own chapters produced, and the imported ones are
 * sums over what a backup stated. The number of queries per render is fixed and
 * grouped in SQL, because the alternative was a loop over rows in the page (the
 * chapterStates lesson).
 *
 * ## Native sittings and imported days are different facts
 *
 * A native row knows a chapter, a start and an end. An imported day knows a date and
 * a number of seconds, and nothing else. So the two are summed for the number shown
 * and kept apart in `native_seconds` and `imported_seconds`: the page can then say
 * which part was read here and which came from another app, instead of presenting a
 * number the reader cannot account for.
 *
 * ## The same day in two backups is counted once
 *
 * Two exports of one phone overlap, and importing both would otherwise count January
 * twice. The ledger keeps each file's rows separately so neither import destroys the
 * other, and the imported query takes the largest value for a series and day across
 * files before summing. The same day cannot contribute twice, whichever file was
 * imported first.
 *
 * Slices are honest because attribution is coarse: a native row contributes its whole
 * reading time to the window it *began* in, and an imported row to the day it names,
 * never partially to two of them, so no reload or filter change can ever double count
 * a window's minutes.
 */
class ReadingInsights
{
    /**
     * Reading time by series, largest first.
     *
     * One query per ledger, both grouped by series in SQL. An imported-only series
     * reports zero pages and zero sittings rather than none at all, because zero is
     * the true count: the backup never stated one.
     *
     * @param  CarbonImmutable|null  $since  Null means everything both ledgers hold.
     * @return Collection<int, array{manga_id: int, seconds: int, native_seconds: int, imported_seconds: int, pages: int, sessions: int, last_active_at: CarbonImmutable}>
     */
    public function bySeries(User|int $user, ?CarbonImmutable $since): Collection
    {
        $userId = UserKey::of($user);

        /** @var Collection<int, array{manga_id: int, seconds: int, native_seconds: int, imported_seconds: int, pages: int, sessions: int, last_active_at: CarbonImmutable}> $merged */
        $merged = ReadingSession::forUser($userId)
            ->selectRaw('suwayomi_manga_id, SUM(read_seconds) as seconds, SUM(pages_turned) as pages, COUNT(*) as sessions, MAX(last_active_at) as last_active_at')
            ->when(
                $since,
                fn ($query) => $query->where('started_at', '>=', $since),
            )
            ->groupBy('suwayomi_manga_id')
            ->get()
            ->keyBy(fn (ReadingSession $session): int => (int) $session->suwayomi_manga_id)
            ->map(fn (ReadingSession $session): array => [
                'manga_id' => (int) $session->suwayomi_manga_id,
                'seconds' => (int) $session->getAttribute('seconds'),
                'native_seconds' => (int) $session->getAttribute('seconds'),
                'imported_seconds' => 0,
                'pages' => (int) $session->getAttribute('pages'),
                'sessions' => (int) $session->getAttribute('sessions'),
                'last_active_at' => CarbonImmutable::parse($session->getAttribute('last_active_at')),
            ]);

        /*
         * The inner query answers one value per series and day, not per file: two
         * backups that both carry 2026-10-08 describe the same day's reading, and the
         * later one is not a second day. The outer query can then sum safely.
         */
        $perDay = DB::table('imported_reading_days')
            ->selectRaw('user_id, suwayomi_manga_id, day, MAX(seconds) as seconds')
            ->where('user_id', $userId)
            ->when(
                $since !== null,
                fn ($query) => $query->where('day', '>=', $since->toDateString()),
            )
            ->groupBy('user_id', 'suwayomi_manga_id', 'day');

        $imported = DB::query()
            ->fromSub($perDay, 'per_day')
            ->selectRaw('suwayomi_manga_id, SUM(seconds) as seconds, MAX(day) as last_day')
            ->groupBy('suwayomi_manga_id')
            ->get();

        foreach ($imported as $row) {
            $mangaId = (int) $row->suwayomi_manga_id;
            $seconds = (int) $row->seconds;
            $lastDay = CarbonImmutable::parse((string) $row->last_day)->startOfDay();
            $existing = $merged->get($mangaId);

            if ($existing === null) {
                $merged->put($mangaId, [
                    'manga_id' => $mangaId,
                    'seconds' => $seconds,
                    'native_seconds' => 0,
                    'imported_seconds' => $seconds,
                    'pages' => 0,
                    'sessions' => 0,
                    'last_active_at' => $lastDay,
                ]);

                continue;
            }

            $merged->put($mangaId, [
                'manga_id' => $mangaId,
                'seconds' => $existing['seconds'] + $seconds,
                'native_seconds' => $existing['native_seconds'],
                'imported_seconds' => $seconds,
                'pages' => $existing['pages'],
                'sessions' => $existing['sessions'],
                'last_active_at' => $existing['last_active_at']->greaterThan($lastDay)
                    ? $existing['last_active_at']
                    : $lastDay,
            ]);
        }

        return $merged
            ->sort(fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']
                ?: $b['last_active_at']->getTimestamp() <=> $a['last_active_at']->getTimestamp())
            ->values();
    }
}
