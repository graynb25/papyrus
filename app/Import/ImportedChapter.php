<?php

namespace App\Import;

/**
 * One chapter as another app recorded it, with ids that mean nothing here.
 *
 * ## backupId is local and useless across apps
 *
 * On the measured Tachimanga backup, manga 217's chapters are numbered 326, 327, 328,
 * continuing a sequence from whatever the reader browsed last. The same chapter in
 * Suwayomi has a different number. So {@see $backupId} exists only so a caller can
 * refer to a chapter while it is inside this one file, and the pipeline pairs a
 * chapter across apps by {@see $sourceOrder} or {@see $number}, never by this.
 *
 * ## sourceOrder, not position, is the pairing key
 *
 * `sourceOrder` is the position the *source site* gives a chapter, and both apps read
 * it from the same place. A series that gains a chapter shifts every list index after
 * it, so pairing on index silently moves a reader's read state onto the wrong chapter
 * whenever a source inserts one in the middle. That is the same class of bug as
 * writing a backup's local manga id straight into `last_read_chapter_id`, and it has
 * no visible symptom.
 *
 * Not every source populates it, which is why {@see $number} and {@see $name} are
 * carried too. A chapter that cannot be paired three ways is reported as unresolved
 * rather than guessed at.
 */
final class ImportedChapter
{
    /**
     * @param  int  $backupId  Local to the backup file. Not portable.
     * @param  float|null  $number  The chapter number as the source states it, which may be fractional.
     * @param  string|null  $name  The chapter's own title, when it has one that is not just the number.
     * @param  int|null  $sourceOrder  The position on the source site. The pairing key.
     * @param  int  $lastPageRead  The page the reader stopped on, or 0 when they did not stop.
     * @param  bool  $isDownloaded  Whether the other app held a local copy. See the note on downloads.
     */
    public function __construct(
        public readonly int $backupId,
        public readonly ?float $number = null,
        public readonly ?string $name = null,
        public readonly ?int $sourceOrder = null,
        public readonly bool $isRead = false,
        public readonly bool $isBookmarked = false,
        public readonly int $lastPageRead = 0,
        public readonly bool $isDownloaded = false,
    ) {}

    /**
     * Whether this chapter carries enough to be paired with one in Suwayomi.
     *
     * Separate from whether it is worth importing, so the caller can report "3,918
     * chapters had no source order" without the reader having to understand why.
     */
    public function isPairable(): bool
    {
        return $this->sourceOrder !== null || $this->number !== null || $this->name !== null;
    }

    /**
     * Downloads do not travel.
     *
     * Tachimanga's own backup documentation states that downloaded chapter files are
     * not included, and the measured backup confirms it: 50 chapters carry
     * `is_downloaded` but the archive holds no chapter images at all. So
     * {@see $isDownloaded} records what the reader *had* on their phone, and the
     * honest import outcome is "read state came across, the file did not". Reporting
     * it as a failure would be wrong; reporting it as imported would be a lie.
     */
    public function needsRefetchingLocally(): bool
    {
        return $this->isDownloaded;
    }
}
