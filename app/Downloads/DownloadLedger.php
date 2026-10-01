<?php

namespace App\Downloads;

use App\Jobs\SyncDownloads;
use App\Models\Download;
use App\Models\User;
use App\Suwayomi\DownloadGateway;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What happened to this reader's downloads.
 *
 * ## Why this exists
 *
 * Suwayomi keeps nothing. Measured: a chapter leaves `downloadStatus.queue` the
 * instant it finishes, and the only surviving trace is `ChapterType.isDownloaded`.
 * `DownloadState.ERROR` arrives with no reason. So the queue can tell you what is
 * happening right now and nothing else, and a download centre that cannot answer
 * "what failed last night" needs somewhere to put that.
 *
 * ## How a download is reconciled, in two calls
 *
 * The hard part is that the queue cannot distinguish finished from failed, because
 * both look identical from outside: the entry is simply gone.
 *
 * So each sync reads the queue once, and asks the only question that can tell them
 * apart: is the chapter `isDownloaded` now. That answer is one bulk manga call, the
 * same one the library page uses, which carries chapter ids and their downloaded
 * flags for any number of series at once.
 *
 *   1. `downloadStatus` gives live progress for whatever is in flight.
 *   2. `mangas(filter: {id: {in: [...]}})` gives `isDownloaded` for the rest.
 *
 * A chapter that left the queue and is downloaded finished. A chapter that left and
 * is not, failed, and gets an error string we wrote rather than one we received.
 *
 * ## The queue is shared, so "mine" comes from here
 *
 * `downloadStatus` returns every reader's downloads because the server is one
 * tenant. Membership is decided by our rows, not by the queue, so a reader sees
 * their own work. {@see self::sharedQueue()} reports the rest without attributing it
 * to anyone.
 */
class DownloadLedger
{
    public function __construct(
        private readonly DownloadGateway $gateway,
        private readonly SeriesGateway $series,
    ) {}

    /**
     * Record an intent and ask the server for it.
     *
     * Writing the row before the call is deliberate. If the call times out, which
     * it does under load, the row is still there and the next sync resolves it
     * rather than the chapter being invisible forever.
     *
     * @param  array<int, int>  $chapterIds
     * @return array{queued: int, rejected: int}
     */
    public function enqueue(User|int $user, array $chapterIds, ?int $mangaId = null): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $chapterIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return ['queued' => 0, 'rejected' => 0];
        }

        $queued = 0;

        foreach ($ids as $chapterId) {
            $existing = Download::forUser($userId)
                ->where('suwayomi_chapter_id', $chapterId)
                ->first();

            // Re-enqueueing something already finished or queued is a no-op rather
            // than an error, because "download this again" is a thing a reader
            // clicks twice.
            if ($existing !== null && ! $existing->isSettled()) {
                continue;
            }

            if ($existing !== null) {
                $existing->state = Download::QUEUED;
                $existing->progress = 0;
                $existing->error = null;
                $existing->enqueued_at = now();
                $existing->started_at = null;
                $existing->finished_at = null;
                $existing->save();
            } else {
                Download::createFor($userId, [
                    'suwayomi_chapter_id' => $chapterId,
                    'suwayomi_manga_id' => $mangaId ?? 0,
                    'state' => Download::QUEUED,
                    'progress' => 0,
                    'enqueued_at' => now(),
                ]);
            }

            $queued++;
        }

        $accepted = $this->gateway->enqueue($ids);

        if (! $accepted) {
            /*
             * The batch was rejected. Nothing was queued anywhere, so saying
             * "queued" would be a lie the reader discovers later. Fail them here,
             * with the reason being ours because the server gives none.
             */
            $this->failAll($userId, $ids, 'Suwayomi would not accept the download request.');

            return ['queued' => 0, 'rejected' => count($ids)];
        }

        /*
         * Kick the first sync rather than waiting for the next scheduled tick.
         *
         * A reader who clicks download and opens the centre straight away should
         * see the row move, not sit at zero for ten seconds wondering whether the
         * click registered. The tick that follows is the job chaining itself, so
         * this only has to start the chain.
         *
         * Dispatched from here rather than from each of the three actions that
         * enqueue, because "there is now work the reconciler must see" is a fact
         * about the ledger and belongs in one place.
         */
        if ($queued > 0) {
            SyncDownloads::dispatch();
        }

        return ['queued' => $queued, 'rejected' => 0];
    }

    /**
     * Reconcile this reader's pending rows against the live queue.
     *
     * Two calls, always, and never one per chapter. See the class docblock.
     *
     * @return array{updated: int, finished: int, failed: int, still_pending: int}
     */
    public function sync(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $pending = Download::forUser($userId)->pending()->get();

        if ($pending->isEmpty()) {
            return ['updated' => 0, 'finished' => 0, 'failed' => 0, 'still_pending' => 0];
        }

        $queue = [];

        try {
            $queue = $this->gateway->status()['queue'];
        } catch (SuwayomiException $e) {
            // The queue is unreachable. Leaving the rows pending is correct: they
            // may well be downloading, and failing them on a transport error would
            // be wrong.
            $this->noteError($userId, $pending->pluck('suwayomi_chapter_id')->all(), $e->getMessage());

            return ['updated' => 0, 'finished' => 0, 'failed' => 0, 'still_pending' => $pending->count()];
        }

        $byChapter = [];

        foreach ($queue as $entry) {
            $byChapter[$entry['chapter_id']] = $entry;
        }

        $updated = 0;

        /** @var array<int, Download> $orphans */
        $orphans = [];

        foreach ($pending as $row) {
            $entry = $byChapter[$row->suwayomi_chapter_id] ?? null;

            if ($entry !== null) {
                $this->applyQueueEntry($row, $entry);
                $updated++;

                continue;
            }

            $orphans[] = $row;
        }

        $finished = 0;
        $failed = 0;

        if ($orphans !== []) {
            [$finished, $failed] = $this->resolveOrphans(collect($orphans));
        }

        return [
            'updated' => $updated,
            'finished' => $finished,
            'failed' => $failed,
            'still_pending' => $pending->count() - $finished - $failed,
        ];
    }

    /**
     * Chapters that left the queue without us seeing them finish.
     *
     * One bulk call for all of them, across however many series they belong to.
     *
     * @param  Collection<int, Download>  $orphans
     * @return array{0: int, 1: int} finished and failed counts
     */
    private function resolveOrphans(Collection $orphans): array
    {
        $mangaIds = $orphans
            ->pluck('suwayomi_manga_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $downloaded = $this->downloadedChapters($mangaIds);

        $finished = 0;
        $failed = 0;

        foreach ($orphans as $row) {
            $isDownloaded = $downloaded[$row->suwayomi_chapter_id] ?? false;

            if ($isDownloaded) {
                $this->markFinished($row);

                $finished++;

                continue;
            }

            /*
             * It left the queue and is not on disk. The server will not say why, so
             * the reason recorded is the observable one, not an invented cause.
             */
            $row->state = Download::FAILED;
            $row->finished_at = now();
            $row->error = 'It left the download queue without downloading. The server reports no reason.';
            $row->save();

            $failed++;
        }

        return [$finished, $failed];
    }

    /**
     * Which chapters of these series are on disk, in one call.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, bool> chapter id to downloaded
     */
    private function downloadedChapters(array $mangaIds): array
    {
        if ($mangaIds === []) {
            return [];
        }

        try {
            $rows = $this->series->fetchMany($mangaIds);
        } catch (SuwayomiException $e) {
            return [];
        }

        $map = [];

        foreach ($rows as $manga) {
            foreach ($manga['chapters']['nodes'] ?? [] as $chapter) {
                $map[(int) ($chapter['id'] ?? 0)] = (bool) ($chapter['isDownloaded'] ?? false);
            }
        }

        return $map;
    }

    /**
     * Fold one queue entry into one row.
     *
     * @param  array<string, mixed>  $entry
     */
    private function applyQueueEntry(Download $row, array $entry): void
    {
        $changed = false;

        $progress = max(0.0, min(1.0, (float) $entry['progress']));

        // Only write when something moved. A sync runs every few seconds and most
        // of the time the head of the queue has not advanced since the last one.
        if (abs($row->progress - $progress) > 0.001) {
            $row->progress = $progress;
            $changed = true;
        }

        if ($row->state !== Download::DOWNLOADING) {
            $row->state = Download::DOWNLOADING;
            $row->started_at ??= now();
            $changed = true;
        }

        if ((int) $row->suwayomi_manga_id === 0 && (int) $entry['manga_id'] > 0) {
            $row->suwayomi_manga_id = (int) $entry['manga_id'];
            $changed = true;
        }

        if ($changed) {
            $row->save();
        }
    }

    private function markFinished(Download $row): void
    {
        $row->state = Download::FINISHED;
        $row->progress = 1.0;
        $row->finished_at ??= now();
        $row->error = null;

        $row->save();

        $this->recordPageCount($row);
    }

    /**
     * Learn a downloaded chapter's length.
     *
     * Measured: `pageCount` is -1 until a chapter downloads, so this is the moment
     * the number becomes knowable at all. Best effort, and a failure here is not
     * worth failing a successful download over.
     */
    private function recordPageCount(Download $row): void
    {
        if ($row->page_count !== null || (int) $row->suwayomi_manga_id === 0) {
            return;
        }

        try {
            $chapter = $this->series->find((int) $row->suwayomi_manga_id);
        } catch (SuwayomiException) {
            return;
        }

        foreach ($chapter['chapters']['nodes'] ?? [] as $node) {
            if ((int) ($node['id'] ?? 0) === (int) $row->suwayomi_chapter_id) {
                $count = (int) ($node['pageCount'] ?? -1);

                if ($count > 0) {
                    $row->page_count = $count;
                    $row->save();
                }

                return;
            }
        }
    }

    /**
     * Remove a download and say so, without calling it a failure.
     */
    public function cancel(User|int $user, int $chapterId): bool
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $row = Download::forUser($userId)->where('suwayomi_chapter_id', $chapterId)->first();

        if ($row === null) {
            return false;
        }

        // Best effort: the row records the reader's decision either way, because a
        // transport failure should not leave the button looking like it did nothing.
        try {
            $this->gateway->dequeue($chapterId);
        } catch (SuwayomiException) {
            // Nothing to do about it; the server will not reach this chapter again.
        }

        $row->state = Download::CANCELLED;
        $row->finished_at = now();
        $row->save();

        return true;
    }

    /**
     * Delete a finished download's pages from disk.
     */
    public function forget(User|int $user, int $chapterId): bool
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $row = Download::forUser($userId)->where('suwayomi_chapter_id', $chapterId)->first();

        if ($row === null || ! $row->isSettled()) {
            return false;
        }

        try {
            $this->gateway->deleteDownloaded($chapterId);
        } catch (SuwayomiException $e) {
            $row->error = $e->getMessage();
            $row->save();

            return false;
        }

        $row->delete();

        return true;
    }

    /**
     * The queue entries that are not this reader's.
     *
     * Counted, never attributed. The downloader is one shared worker and the other
     * entries belong to another reader; naming them would be leaking one reader's
     * library into another's page.
     *
     * @return array{count: int, running: bool}
     */
    public function sharedQueue(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $mine = Download::forUser($userId)->pending()->pluck('suwayomi_chapter_id')->all();

        try {
            $status = $this->gateway->status();
        } catch (SuwayomiException) {
            return ['count' => 0, 'running' => false];
        }

        $theirs = array_filter(
            $status['queue'],
            static fn (array $entry): bool => ! in_array($entry['chapter_id'], $mine, true),
        );

        return [
            'count' => count($theirs),
            'running' => $status['downloader'] === 'STARTED',
        ];
    }

    /**
     * This reader's rows, newest first.
     *
     * @return Collection<int, Download>
     */
    public function forUser(User|int $user, int $limit = 200): Collection
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return Download::forUser($userId)
            ->orderByRaw("case when state in ('queued', 'downloading') then 0 else 1 end")
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Counts for the page header.
     *
     * @return array{pending: int, finished: int, failed: int, pages: int}
     */
    public function counts(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $byState = DB::connection()->table('downloads')
            ->select('state')
            ->selectRaw('count(*) as tally, coalesce(sum(page_count), 0) as pages')
            ->where('user_id', $userId)
            ->groupBy('state')
            ->get()
            ->keyBy('state');

        $count = static fn (string $state): int => (int) ($byState[$state]->tally ?? 0);
        $pages = static fn (string $state): int => (int) ($byState[$state]->pages ?? 0);

        $pending = 0;

        foreach (Download::PENDING as $state) {
            $pending += $count($state);
        }

        return [
            'pending' => $pending,
            'finished' => $count(Download::FINISHED),
            'failed' => $count(Download::FAILED) + $count(Download::CANCELLED),
            'pages' => $pages(Download::FINISHED),
        ];
    }

    /**
     * @param  array<int, int>  $chapterIds
     */
    private function failAll(int $userId, array $chapterIds, string $reason): void
    {
        Download::forUser($userId)
            ->whereIn('suwayomi_chapter_id', $chapterIds)
            ->whereIn('state', Download::PENDING)
            ->update([
                'state' => Download::FAILED,
                'error' => $reason,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<int, int>  $chapterIds
     */
    private function noteError(int $userId, array $chapterIds, string $reason): void
    {
        Download::forUser($userId)
            ->whereIn('suwayomi_chapter_id', $chapterIds)
            ->whereIn('state', Download::PENDING)
            ->update(['error' => $reason, 'updated_at' => now()]);
    }
}
