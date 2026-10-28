<?php

namespace App\Import;

use App\Models\ImportRun;

/**
 * Closes reviews nobody answered, and removes the uploads they were holding.
 *
 * ## Why this is needed at all
 *
 * A review is the one import state that waits for a person. Everything else either runs to
 * its end or fails while somebody is watching. A reader can open a review, close the tab and
 * never come back, and without this the 40MB backup behind it would stay on disk for ever
 * while the run claimed to be waiting.
 *
 * Twenty four hours is long enough to be called away and come back the next day, short enough
 * that an abandoned upload is not permanent. {@see ImportRun::REVIEW_TTL_HOURS} is the one
 * number, used here and by the sweep below.
 *
 * ## Why expiry is a claim rather than a read then a write
 *
 * The page can confirm at the same moment this runs. Both move the row out of `awaiting`, so
 * both use a conditional update and only the winner acts. Without that, expiry could read an
 * awaiting row, a confirm could claim it, and expiry could then overwrite a live import with
 * `expired` and delete the file the worker was reading.
 *
 * ## The second sweep
 *
 * A run covers an upload whose job started. It does not cover an upload whose job never
 * started, so the same age covers files no live run references. That is deliberately the
 * second half of the same decision rather than a separate retention rule.
 */
final class ImportReviewExpiry
{
    public function __construct(private readonly ImportUploads $uploads) {}

    /**
     * Expire abandoned reviews and sweep unreferenced uploads.
     *
     * @return int How many reviews were closed.
     */
    public function expire(): int
    {
        $cutoff = now()->subHours(ImportRun::REVIEW_TTL_HOURS);
        $expired = 0;

        $runs = ImportRun::query()
            ->forAllUsers()
            ->where('state', ImportRun::AWAITING_CONFIRMATION)
            ->where('updated_at', '<', $cutoff)
            ->get();

        foreach ($runs as $run) {
            $path = $run->file_path;

            $claimed = ImportRun::query()
                ->forAllUsers()
                ->whereKey($run->id)
                ->where('state', ImportRun::AWAITING_CONFIRMATION)
                ->update([
                    'state' => ImportRun::EXPIRED,
                    'file_path' => null,
                    'finished_at' => now(),
                    'progress_note' => 'This review expired before it was answered, and any stored upload was removed.',
                ]);

            if ($claimed !== 1) {
                continue;
            }

            $this->uploads->forget($path);
            $expired++;
        }

        $this->uploads->forgetUnreferenced($cutoff);

        return $expired;
    }
}
