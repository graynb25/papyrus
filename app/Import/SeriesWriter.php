<?php

namespace App\Import;

use App\Models\User;
use App\Support\ReadingState;
use App\Suwayomi\SeriesGateway;
use Illuminate\Support\Facades\DB;

/**
 * Pairs a backup's chapters onto Suwayomi's, and writes the result through ReadingState.
 *
 * ## The pairing key, measured rather than assumed
 *
 * `Chapter.id` in a backup is a local autoincrement and means nothing here, so the two lists
 * have to be joined on something both apps agree on. Measured against a series both sides
 * hold, after fetching Suwayomi's chapter list:
 *
 * ```
 * backup    92 chapters, source_order 1..92
 * suwayomi  92 chapters, sourceOrder 1..92
 * 92 of 92 chapters pair, and 92 of 92 read chapters pair
 * ```
 *
 * `sourceOrder` is the position the *source site* gives a chapter, so it survives one app
 * re-numbering its own ids. Chapter numbers agree too on that sample, so they are the
 * fallback, but a source that inserts a chapter in the middle renumbers everything after it
 * and a positional index has the same fault.
 *
 * ## What writing is allowed to do
 *
 * Forward only. A chapter is marked read if either side says so, a bookmark is set and never
 * cleared, and a series already in the library is added to rather than rewritten. Nothing
 * here can turn a read back into an unread, because that is the one change a reader cannot
 * undo and the one an import has no business making.
 *
 * ## The pointer moves forward only
 *
 * The resume point is the one field where "newer wins" is right: it is where the reader most
 * recently stopped on the device that made the backup. But a pointer that moved *backwards*
 * would send a reader to a chapter they have already passed, so the position is compared and
 * only a forward move is taken. That asymmetry is deliberate.
 *
 * ## Why the transaction is here and not in ReadingState
 *
 * ReadingState has no `transaction()` of its own: each of its methods opens one where its own
 * writes need to be atomic, and `moveSeries` uses `DB::transaction` directly for the same
 * reason. A series import touches a shelf entry, many chapter rows and a pointer, so it
 * wraps them the same way rather than adding a method that would have to reason about which
 * callers want a transaction and which do not.
 */
final class SeriesWriter
{
    /**
     * How far forward a pointer may move before the move is treated as a reshuffle rather
     * than progress.
     *
     * A source that reorders its catalogue can make a large forward jump meaningless, and a
     * pointer that leaps most of the way through a series is a different fact from one that
     * advanced by three chapters.
     */
    private const POINTER_SANE_JUMP = 200;

    public function __construct(
        private readonly ReadingState $reading,
        private readonly SeriesGateway $series,
    ) {}

    /**
     * Write one resolved series.
     *
     * @param  User|int  $user  Explicit rather than read from the session, because an import
     *                          runs as a job and there may be no session to read.
     * @return array{written: bool, read_chapters: int, chapters_seen: int, pointer: string|null, reason: string|null}
     */
    public function write(User|int $user, ImportedSeries $series, Resolution $resolution): array
    {
        if (! $resolution->resolved || $resolution->suwayomiMangaId === null) {
            return [
                'written' => false,
                'read_chapters' => 0,
                'chapters_seen' => 0,
                'pointer' => null,
                'reason' => $resolution->reason ?? 'not resolved',
            ];
        }

        $mangaId = $resolution->suwayomiMangaId;
        $theirs = $this->theirChapters($mangaId);

        $readIds = [];
        $bookmarkIds = [];

        foreach ($series->chapters as $chapter) {
            $ours = $this->pair($chapter, $theirs);

            if ($ours === null) {
                // A chapter that has gone upstream, or that this server has not fetched.
                // Normal, and not worth a line per chapter.
                continue;
            }

            if ($chapter->isRead) {
                $readIds[] = $ours;
            }

            if ($chapter->isBookmarked) {
                $bookmarkIds[] = $ours;
            }
        }

        DB::transaction(function () use ($user, $mangaId, $readIds, $bookmarkIds): void {
            $this->reading->setInLibrary($user, $mangaId, true);

            if ($readIds !== []) {
                $this->reading->markManyRead($user, $mangaId, $readIds, true);
            }

            foreach ($bookmarkIds as $chapterId) {
                $this->reading->setBookmark($user, $mangaId, $chapterId, true);
            }
        });

        $pointer = $this->advancePointer($user, $series, $mangaId, $theirs);

        return [
            'written' => true,
            'read_chapters' => count($readIds),
            'chapters_seen' => $series->chapters->count(),
            'pointer' => $pointer,
            'reason' => null,
        ];
    }

    /**
     * Find the Suwayomi chapter that is the same chapter as one in the backup.
     *
     * @param  array{by_order: array<int, int>, by_number: array<int, int>}  $theirs
     */
    private function pair(ImportedChapter $chapter, array $theirs): ?int
    {
        if ($chapter->sourceOrder !== null && isset($theirs['by_order'][$chapter->sourceOrder])) {
            return $theirs['by_order'][$chapter->sourceOrder];
        }

        if ($chapter->number !== null && isset($theirs['by_number'][(int) $chapter->number])) {
            return $theirs['by_number'][(int) $chapter->number];
        }

        return null;
    }

    /**
     * Suwayomi's chapter list for a series, indexed by the two keys a backup can join on.
     *
     * Goes through {@see SeriesGateway} rather than a second query of its own: that class
     * already fetches `sourceOrder` and `chapterNumber` for every chapter it reads, and a
     * second fetcher for the same rows would be the same document in two places with two
     * chances to disagree about the shape.
     *
     * ## Why this refreshes, and why that was the read state's whole life
     *
     * A manga entry the server created from a search has never been initialised, and it answers
     * `initialized: false` with an empty chapter list and `totalCount: 0`. Measured against
     * the real backup on the 40 imported series sampled here: **31 of 40 answered that way.**
     *
     * So reading the destination with `find()` alone gives the pairing nothing to join on for
     * most of a library, every chapter falls out of {@see pair()}, and every read chapter in
     * the backup is silently dropped. Measured on the same import: the file carries 40,160 read
     * chapters, the run recorded 30,687 chapters seen and applied **1,053** read chapters, and
     * series showed "read 0 of 97" for series the reader had finished.
     *
     * The 1,053 were the series that happened to already be initialised, which is why the
     * import looked like it mostly worked. It is the same stub {@see CandidateFinder} documents
     * for ranking, reached here through a different door.
     *
     * One refresh per series that needs it, at the measured 370ms to 1346ms, which is real
     * time: the alternative is an import that reports success and loses the only thing the
     * reader had.
     *
     * @return array{by_order: array<int, int>, by_number: array<int, int>, position: array<int, int>}
     */
    private function theirChapters(int $mangaId): array
    {
        $found = $this->series->find($mangaId);

        if ($found === null || ! ($found['initialized'] ?? false)) {
            $refreshed = $this->series->refresh($mangaId);

            /*
             * A refresh that fails leaves the empty list in place rather than throwing.
             *
             * One source that will not answer must not end an import of a thousand series: the
             * other series still pair, and this one is reported as having paired nothing. The
             * alternative is the shape this whole class already had, where a transport
             * failure looked exactly like a series with nothing in it.
             */
            if (($refreshed['error'] ?? null) === null && ($refreshed['series'] ?? null) !== null) {
                $found = $refreshed['series'];
            }
        }

        $nodes = $found['chapters']['nodes'] ?? [];

        $byOrder = [];
        $byNumber = [];
        $position = [];

        foreach ($nodes as $index => $node) {
            $id = (int) ($node['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $position[$id] = $index;

            $order = $node['sourceOrder'] ?? null;
            if (is_numeric($order) && ! isset($byOrder[(int) $order])) {
                $byOrder[(int) $order] = $id;
            }

            $number = $node['chapterNumber'] ?? null;
            if (is_numeric($number) && ! isset($byNumber[(int) $number])) {
                $byNumber[(int) $number] = $id;
            }
        }

        return ['by_order' => $byOrder, 'by_number' => $byNumber, 'position' => $position];
    }

    /**
     * Move the resume pointer forward, and only forward.
     *
     * @param  array{by_order: array<int, int>, by_number: array<int, int>, position: array<int, int>}  $theirs
     * @return string|null Why the pointer could not move, for the report. Null when it did, or did not need to.
     */
    private function advancePointer(User|int $user, ImportedSeries $series, int $mangaId, array $theirs): ?string
    {
        if ($series->backupResumeChapterId === null) {
            return null;
        }

        $source = $series->chapters->firstWhere('backupId', $series->backupResumeChapterId);

        if ($source === null) {
            return 'the chapter the backup last read is gone, so the resume point was left alone';
        }

        $target = $this->pair($source, $theirs);

        if ($target === null) {
            return 'the chapter the backup last read has no counterpart here, so the resume point was left alone';
        }

        $state = $this->reading->findSeries($user, $mangaId);
        $current = $state?->last_read_chapter_id;
        $currentPosition = $current === null ? null : ($theirs['position'][$current] ?? null);
        $targetPosition = $theirs['position'][$target] ?? null;

        if ($currentPosition !== null && $targetPosition !== null) {
            if ($targetPosition <= $currentPosition) {
                return null;   // Already further on. Correct to leave alone.
            }

            if ($targetPosition - $currentPosition > self::POINTER_SANE_JUMP) {
                return 'the resume point moved too far to be progress, so it was left alone';
            }
        }

        $this->reading->setPointer($user, $mangaId, $target);

        return null;
    }
}
