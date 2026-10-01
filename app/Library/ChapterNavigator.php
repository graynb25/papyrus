<?php

namespace App\Library;

use App\Models\ChapterState;
use App\Models\User;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Collection;

/**
 * A series-wide scrubber: one segment per chapter, in source order.
 *
 * ## What the three parts of H13 are, and why they are one class
 *
 * Boundary ticks, a read-page heat strip, and cross-chapter seek are the same data
 * seen three ways. One segment per chapter, ordered, filled by how much of it is
 * read, clickable to jump. So they are built once.
 *
 * ## Why the fill has three states and not two
 *
 * Measured on the dev library: **12 of 575 chapters carry a `pageCount`, 2.1%**. The
 * reason is that `pageCount` is `-1` until a chapter's pages have been fetched, which
 * measured happens only for downloaded chapters. So for 98% of the library there is
 * no denominator, and a fraction is not computable.
 *
 * That leaves three honest states rather than two:
 *
 *  - `read`: the reader finished it. No denominator needed.
 *  - `partial`: there is a numerator and a denominator, so a fraction is real.
 *  - `unknown`: nothing to go on, which is most of a library.
 *
 * A two-state strip would render `partial` and `unknown` identically, and a segment
 * that is empty because nobody has read it would be indistinguishable from one that
 * is empty because we cannot tell. A reader looking at a strip of 107 empty segments
 * would conclude the feature is broken, and it would be telling the truth.
 *
 * ## Why it costs one extra call
 *
 * `fetchManyWithPages()` rather than the library's `fetchMany()`, because the two
 * chapter fields this needs are not the ones a library card renders. Measured: the
 * batch is one round trip either way and the cost tracks the response rather than the
 * number of ids, 246ms for 300 ids, so this is a flat cost per chapter opened rather
 * than anything that grows.
 */
class ChapterNavigator
{
    public function __construct(
        private readonly SeriesGateway $gateway,
    ) {}

    /**
     * The scrubber for one series.
     *
     * @return array{
     *     chapters: array<int, array{
     *         id: int,
     *         name: string,
     *         number: float|null,
     *         state: string,
     *         progress: float,
     *         pages: int|null,
     *         page: int,
     *         is_current: bool
     *     }>,
     *     counts: array{read: int, partial: int, unknown: int, total: int}
     * }
     */
    public function forSeries(User|int $user, int $mangaId, int $currentChapterId): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $rows = $this->rows([$mangaId]);

        $nodes = $rows[$mangaId]['chapters']['nodes'] ?? [];

        if (! is_array($nodes) || $nodes === []) {
            return ['chapters' => [], 'counts' => $this->emptyCounts()];
        }

        $read = $this->readChapters($userId, [$mangaId]);

        $chapters = [];
        $counts = ['read' => 0, 'partial' => 0, 'unknown' => 0, 'total' => 0];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $id = (int) ($node['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $ours = $read[$id] ?? ['is_read' => false, 'last_page_read' => 0];

            /*
             * `pageCount` is the denominator and it is -1 for most chapters.
             *
             * Negative is treated as absent rather than as a zero-page chapter, so a
             * reader who has read page 3 of a chapter the server has not counted does
             * not see a nonsense fraction like 3 over -1.
             */
            $pages = (int) ($node['pageCount'] ?? -1);
            $pages = $pages > 0 ? $pages : null;

            $page = (int) $ours['last_page_read'];
            $isRead = (bool) $ours['is_read'];

            if ($isRead) {
                $state = 'read';
                $progress = 1.0;
            } elseif ($pages !== null && $page > 0) {
                $state = 'partial';

                // Clamped, because a chapter can hold more progress than pages after a
                // source renumbers and a fill over 100% overflows its track.
                $progress = max(0.0, min(1.0, $page / $pages));
            } else {
                $state = 'unknown';
                $progress = 0.0;
            }

            $counts[$state]++;
            $counts['total']++;

            $chapters[] = [
                'id' => $id,
                'name' => (string) ($node['name'] ?? ''),
                'number' => isset($node['chapterNumber']) ? (float) $node['chapterNumber'] : null,
                'state' => $state,
                'progress' => $progress,
                'pages' => $pages,
                'page' => $page,
                'is_current' => $id === $currentChapterId,
            ];
        }

        return ['chapters' => $chapters, 'counts' => $counts];
    }

    /**
     * The chapter either side, by source order, for the boundary ticks.
     *
     * Read from the same list rather than passed in, because a deep link into a
     * chapter carries no list and the navigator still has to know where the edges are.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array{previous: int|null, next: int|null, index: int, total: int}
     */
    public function position(array $chapters, int $currentChapterId): array
    {
        $ids = array_column($chapters, 'id');
        $index = array_search($currentChapterId, $ids, true);

        if ($index === false) {
            return ['previous' => null, 'next' => null, 'index' => 0, 'total' => count($ids)];
        }

        return [
            'previous' => $ids[$index - 1] ?? null,
            'next' => $ids[$index + 1] ?? null,
            'index' => $index + 1,
            'total' => count($ids),
        ];
    }

    /**
     * The batch, tolerating an unreachable server.
     *
     * A navigator is decoration on top of a chapter the reader can already read, so a
     * server that will not answer should cost the scrubber and nothing else.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $mangaIds): array
    {
        try {
            return $this->gateway->fetchManyWithPages($mangaIds);
        } catch (SuwayomiException) {
            return [];
        }
    }

    /**
     * This reader's state for the series' chapters, in one query.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, array{is_read: bool, last_page_read: int}>
     */
    private function readChapters(int $userId, array $mangaIds): array
    {
        $states = ChapterState::query()
            ->forUser($userId)
            ->whereIn('suwayomi_manga_id', $mangaIds)
            ->get(['suwayomi_chapter_id', 'is_read', 'last_page_read']);

        $byChapter = [];

        foreach ($states as $state) {
            $byChapter[(int) $state->suwayomi_chapter_id] = [
                'is_read' => (bool) $state->is_read,
                'last_page_read' => (int) $state->last_page_read,
            ];
        }

        return $byChapter;
    }

    /**
     * @return array{read: int, partial: int, unknown: int, total: int}
     */
    private function emptyCounts(): array
    {
        return ['read' => 0, 'partial' => 0, 'unknown' => 0, 'total' => 0];
    }

    /**
     * Chapters carrying a read fraction, for a page that wants the list.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return Collection<array-key, non-empty-array<string, mixed>>
     */
    public function finished(array $chapters): Collection
    {
        $done = array_values(array_filter(
            $chapters,
            static fn (array $chapter): bool => $chapter['state'] === 'read',
        ));

        /** @var Collection<array-key, non-empty-array<string, mixed>> $result */
        $result = new Collection($done);

        return $result;
    }
}
