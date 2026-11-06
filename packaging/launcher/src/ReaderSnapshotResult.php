<?php

namespace Papyrus\Launcher;

/**
 * What the reader's half of a backup turned out to hold.
 *
 * ## Why these four numbers and not just "it worked"
 *
 * Because "it worked" is the failure this project keeps meeting. Six green lines over an install that
 * cannot read anything, a restore that reported success over a rollback, a health check that said a
 * scheduler was dispatching when it had written nothing for twelve hours.
 *
 * The counts are the only evidence anybody will ever have of what a backup contained. Measured on the
 * reader's own install: **867 `series_states` and 21,447 `chapter_states`**, and nothing else on this
 * machine records that. The digest and the size say the file is the one that was written; the counts
 * say it is worth having.
 *
 * They are also the answer to "did this backup work", which is the question a reader actually has
 * after a restore, and which a manifest with no counts cannot answer.
 */
final class ReaderSnapshotResult
{
    public function __construct(
        public readonly int $seriesStates,
        public readonly int $chapterStates,
        public readonly int $bytes,
        public readonly string $sha256,
    ) {}

    /**
     * One line, in the words a reader reads when they want to know what they have.
     */
    public function describe(?Translator $translator = null): string
    {
        if ($translator !== null) {
            return $translator->get('snapshot.counts', [
                'series' => number_format($this->seriesStates),
                'chapters' => number_format($this->chapterStates),
                'bytes' => number_format($this->bytes),
            ]);
        }

        return sprintf(
            '%s series and %s chapter states, %s bytes',
            number_format($this->seriesStates),
            number_format($this->chapterStates),
            number_format($this->bytes),
        );
    }
}
