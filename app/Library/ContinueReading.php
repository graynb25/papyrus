<?php

namespace App\Library;

use App\Models\ChapterState;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\UserKey;
use Illuminate\Support\Collection;

/**
 * Where each series in a reader's library should be picked up next.
 *
 * ## Why this is a local join and not a fetch
 *
 * The obvious implementation asks Suwayomi for each series' chapter list, and the
 * library page's existing `mangas(filter: {id: {in: [...]}})` batch call is the
 * cheaper answer than it looks. Measured: one call returns 14 series with 575 chapter
 * ids between them, in 539ms. Since the read set is ours, "the next unread chapter" is
 * a walk over ids we already hold.
 *
 * The alternative was measured and rejected: one `fetchMangaAndChapters` per series
 * at 876ms to 1099ms is 3.5 to 4.4 seconds of upstream requests for 14 series, on a
 * page that currently takes well under a second.
 *
 * ## Why "first unread" and not "the chapter after last_read_chapter_id"
 *
 * Measured on the dev library: 1 of 14 series has a `last_read_chapter_id` and there
 * are **zero** read chapter rows, so the last-read pointer is absent almost everywhere
 * and every series would point at chapter 1. A reader who finished a 41 chapter series
 * and marked nothing read would be sent back to the beginning.
 *
 * So the read set decides, and the last-read pointer is only a tiebreak for a series
 * with no read rows at all. A series where every chapter is read resolves to nothing,
 * which is correct: there is nothing to continue.
 *
 * ## Why the chapter list is walked in the order the server gave it
 *
 * `manga(id:).chapters` comes back in source order and takes no arguments, so the
 * order is the source's. Sorting by `chapterNumber` would be more principled and
 * measurably wrong for at least one real case: the dev library's two sources label
 * the same chapter `Chapter 1` and `Ch. 1`, so a label sort carries nothing. Order
 * is what the source means, and it is what the reader's own progress was measured
 * against.
 */
class ContinueReading
{
    /**
     * Where to pick up, per series, for everything in the library.
     *
     * One method rather than a per-series call, because the library page asks about
     * every card it renders and the same mistake the Suwayomi metadata already made
     * once is not worth making twice.
     *
     * @param  array<int, array<string, mixed>>  $sourceRows  the batch read, keyed by manga id
     * @return array<int, array{
     *     chapter_id: int,
     *     position: int,
     *     total: int,
     *     chapter_name: string|null,
     *     read: int
     * }>
     */
    public function forLibrary(User|int $user, array $sourceRows): array
    {
        $userId = UserKey::of($user);

        $states = SeriesState::forUser($userId)
            ->where('in_library', true)
            ->get();

        if ($states->isEmpty() || $sourceRows === []) {
            return [];
        }

        $readByManga = $this->readChapters($userId, $states->pluck('suwayomi_manga_id')->all());

        $resolutions = [];

        foreach ($states as $state) {
            $mangaId = (int) $state->suwayomi_manga_id;
            $chapters = $this->chapterList($sourceRows[$mangaId] ?? null);

            if ($chapters === []) {
                continue;
            }

            $read = $readByManga[$mangaId] ?? [];
            $next = $this->firstUnread($chapters, $read, (int) ($state->last_read_chapter_id ?? 0));

            if ($next === null) {
                // Everything is read. There is nothing to continue, and showing
                // chapter one here would be the one answer that is certainly wrong.
                continue;
            }

            $position = array_search($next, array_column($chapters, 'id'), true);

            $resolutions[$mangaId] = [
                'chapter_id' => $next,
                // 1-based, because that is how a reader counts, and a zero here would
                // read as "before the first chapter".
                'position' => $position === false ? 1 : $position + 1,
                'total' => count($chapters),
                'chapter_name' => $chapters[$position === false ? 0 : $position]['name'] ?? null,
                'read' => count($read),
            ];
        }

        return $resolutions;
    }

    /**
     * The series a reader should be able to pick up, most recent first.
     *
     * Ordered by `last_read_at` rather than by anything computed, so the top of the
     * feed is whatever this reader was last doing. A series never opened sorts last
     * within its group, which is the right answer for someone who added forty series
     * and read none of them: the feed shows what they were reading, and the shelves
     * below it are where the rest live.
     *
     * @param  array<int, array<string, mixed>>  $entries  the library page's rows
     * @param  array<int, array<string, mixed>>  $resolutions  from {@see forLibrary()}
     * @return Collection<int, array<string, mixed>>
     */
    public function feed(array $entries, array $resolutions, int $limit = 5): Collection
    {
        /*
         * Collected through an array rather than chained off `collect()`.
         *
         * A `->map()` that assigns a new key makes PHPStan infer `non-empty-array` for
         * every row, and `Collection`'s template is invariant, so the result can never
         * satisfy a declared `array<string, mixed>`. A typed array assembled by hand
         * sidesteps that, and reads the same.
         *
         * @var array<int, array<string, mixed>> $rows
         */
        $rows = [];

        foreach ($entries as $entry) {
            $mangaId = (int) $entry['manga_id'];
            $continue = $resolutions[$mangaId] ?? null;

            // Everything read has nothing to continue to, and offering chapter one
            // would be the one answer that is certainly wrong.
            if ($continue === null) {
                continue;
            }

            $entry['continue_at'] = $continue;
            $rows[] = $entry;
        }

        usort($rows, function (array $a, array $b): int {
            /*
             * Most recently read first.
             *
             * A series never opened sorts after anything with a timestamp, which is the
             * right answer for a reader who added forty series and read none of them:
             * the feed leads with what they were reading, and the shelves below are
             * where the rest live. Ties break on how far in the reader got, so a series
             * with forty chapters behind it leads one with two.
             */
            $then = (int) ($a['last_read_at']?->getTimestamp() ?? 0);
            $now = (int) ($b['last_read_at']?->getTimestamp() ?? 0);

            return $now <=> $then
                ?: ((int) ($b['continue_at']['read'] ?? 0) <=> (int) ($a['continue_at']['read'] ?? 0));
        });

        /** @var Collection<int, array<string, mixed>> $result */
        $result = new Collection(array_slice($rows, 0, $limit));

        return $result;
    }

    /**
     * The chapter list from a batch row, in the order the server gave it.
     *
     * @param  array<string, mixed>|null  $row
     * @return array<int, array{id: int, name: string|null}>
     */
    private function chapterList(?array $row): array
    {
        $nodes = $row['chapters']['nodes'] ?? null;

        if (! is_array($nodes)) {
            return [];
        }

        $chapters = [];

        foreach ($nodes as $node) {
            $id = (int) (is_array($node) ? ($node['id'] ?? 0) : 0);

            if ($id === 0) {
                continue;
            }

            $chapters[] = [
                'id' => $id,
                // The source's own label, which is worth showing when it differs from
                // the number. Measured: two sources label one chapter `Chapter 1` and
                // `Ch. 1`, so the label is not derivable from the number.
                'name' => is_array($node) && isset($node['name']) ? (string) $node['name'] : null,
            ];
        }

        return $chapters;
    }

    /**
     * The read chapter ids per series, in one grouped query.
     *
     * `is_read`, not `read`. The column mirrors Suwayomi's own `isRead`, and the
     * shorter name exists nowhere in the schema, so a query naming it returns every
     * row with the column null and no error. Found by a test that read three chapters
     * and still resolved to chapter one.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, array<int, true>>
     */
    private function readChapters(int $userId, array $mangaIds): array
    {
        if ($mangaIds === []) {
            return [];
        }

        $rows = ChapterState::query()
            ->forUser($userId)
            ->whereIn('suwayomi_manga_id', $mangaIds)
            ->where('is_read', true)
            ->get(['suwayomi_manga_id', 'suwayomi_chapter_id']);

        $byManga = [];

        foreach ($rows as $row) {
            $byManga[(int) $row->suwayomi_manga_id][(int) $row->suwayomi_chapter_id] = true;
        }

        return $byManga;
    }

    /**
     * The first chapter in the list that this reader has not read.
     *
     * @param  array<int, array{id: int, name: string|null}>  $chapters
     * @param  array<int, true>  $read
     * @return int|null the chapter id, or null when everything is read
     */
    private function firstUnread(array $chapters, array $read, int $lastReadChapterId): ?int
    {
        if ($chapters === []) {
            return null;
        }

        /*
         * The last-read pointer is tried first, not last.
         *
         * Reachable order matters more than it looks. With the pointer checked after
         * the read-set walk, a reader who opened chapter 20 and marked nothing read
         * hits "nothing is read, so chapter one" and never reaches the pointer. That
         * is precisely the case the pointer exists for: measured, 1 of 14 dev series
         * has a pointer and there are zero read rows, so the pointer is often the only
         * evidence of where a reader got to.
         *
         * The pointer is only honoured when the chapter after it exists and is itself
         * unread, so it cannot skip a chapter the reader has finished.
         */
        if ($lastReadChapterId > 0) {
            $position = array_search($lastReadChapterId, array_column($chapters, 'id'), true);

            if ($position !== false) {
                $next = $chapters[$position + 1] ?? null;

                if ($next !== null && ! isset($read[$next['id']])) {
                    return $next['id'];
                }
            }

            /*
             * A pointer naming something this series no longer has is the migration
             * case: every chapter id changes when a series moves, so a stale pointer
             * is ordinary rather than exceptional. Falls through to the read set.
             */
        }

        foreach ($chapters as $chapter) {
            if (! isset($read[$chapter['id']])) {
                return $chapter['id'];
            }
        }

        return null;
    }
}
