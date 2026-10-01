<?php

namespace App\Suwayomi;

use App\Downloads\DownloadLedger;
use App\Support\Suwayomi\Timestamps;

/**
 * The download queue, and the mutations that move it.
 *
 * Everything here is measured against Suwayomi v2.3.2243. The constraints that
 * shape this class:
 *
 * ## The queue only holds what is happening now
 *
 * A chapter leaves `downloadStatus.queue` the moment it finishes, so the queue is
 * not a history. The only surviving trace upstream is `ChapterType.isDownloaded`.
 * Anything a download centre shows about the past has to be kept by the caller;
 * see {@see DownloadLedger}.
 *
 * ## progress is a fraction, stepping once per page
 *
 * Measured over a 28 page chapter: 0, then 1/28 apart, to 1. So a bar is possible
 * but its resolution is one page and it will visibly step.
 *
 * ## The downloader is one global switch
 *
 * `downloadStatus.state` is STARTED or STOPPED and it belongs to `Admin(1)`, not to
 * a reader. Measured both useful facts about it: enqueueing a chapter while it is
 * STOPPED starts it by itself, and it stops itself when the queue empties. So this
 * class never starts or stops it. There is no call for either on purpose.
 *
 * ## The input field is `id`, not `chapterId`
 *
 * `EnqueueChapterDownloadInput` has `id` and `clientMutationId`. Sending
 * `chapterId` is rejected as an undefined field. Measured.
 *
 * ## There is no error text anywhere
 *
 * `DownloadState.ERROR` carries no reason, and neither does anything else in the
 * schema. A caller that wants to explain a failure has to explain it itself.
 */
class DownloadGateway
{
    public function __construct(
        private readonly Gateway $gateway,
        private readonly Timestamps $timestamps,
    ) {}

    /**
     * The live queue, and whether the shared downloader is running.
     *
     * @return array{downloader: string, queue: array<int, array<string, mixed>>}
     *
     * @throws SuwayomiException
     */
    public function status(): array
    {
        $data = $this->gateway->execute(<<<'GQL'
            query Downloads {
                downloadStatus {
                    state
                    queue {
                        position
                        progress
                        state
                        tries
                        chapter { id name mangaId }
                        manga { id title }
                    }
                }
            }
            GQL);

        $status = $data['downloadStatus'] ?? [];

        $queue = [];

        foreach ($status['queue'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $queue[] = [
                'position' => (int) ($entry['position'] ?? 0),
                'progress' => (float) ($entry['progress'] ?? 0),
                'state' => (string) ($entry['state'] ?? ''),
                'tries' => (int) ($entry['tries'] ?? 0),
                'chapter_id' => $this->timestamps->toInt($entry['chapter']['id'] ?? null),
                'chapter_name' => (string) ($entry['chapter']['name'] ?? ''),
                'manga_id' => $this->timestamps->toInt($entry['chapter']['mangaId'] ?? null),
                'manga_title' => (string) ($entry['manga']['title'] ?? ''),
            ];
        }

        return [
            'downloader' => (string) ($status['state'] ?? 'UNKNOWN'),
            'queue' => $queue,
        ];
    }

    /**
     * Put chapters in the queue.
     *
     * Measured: this starts a STOPPED downloader by itself, so there is no
     * separate start here and no way to leave a download queued and inert.
     *
     * @param  array<int, int>  $chapterIds
     * @return bool false when the server rejected the batch
     */
    public function enqueue(array $chapterIds): bool
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $chapterIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return true;
        }

        try {
            $this->gateway->execute(<<<'GQL'
                mutation Enqueue($input: EnqueueChapterDownloadsInput!) {
                    enqueueChapterDownloads(input: $input) { clientMutationId }
                }
                GQL, ['input' => ['ids' => $ids]]);
        } catch (SuwayomiException) {
            return false;
        }

        return true;
    }

    /**
     * Take one chapter out of the queue without deleting what it already fetched.
     *
     * @throws SuwayomiException
     */
    public function dequeue(int $chapterId): void
    {
        $this->gateway->execute(<<<'GQL'
            mutation Dequeue($input: DequeueChapterDownloadInput!) {
                dequeueChapterDownload(input: $input) { clientMutationId }
            }
            GQL, ['input' => ['id' => $chapterId]]);
    }

    /**
     * Delete a downloaded chapter's pages from disk.
     *
     * @throws SuwayomiException
     */
    public function deleteDownloaded(int $chapterId): void
    {
        $this->gateway->execute(<<<'GQL'
            mutation DeleteDownloaded($input: DeleteDownloadedChapterInput!) {
                deleteDownloadedChapter(input: $input) { clientMutationId }
            }
            GQL, ['input' => ['id' => $chapterId]]);
    }

    /**
     * Empty the queue, keeping what has already downloaded.
     *
     * @throws SuwayomiException
     */
    public function clear(): void
    {
        $this->gateway->execute(<<<'GQL'
            mutation Clear { clearDownloader(input: {}) { clientMutationId } }
            GQL);
    }
}
