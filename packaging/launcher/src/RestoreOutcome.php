<?php

namespace Papyrus\Launcher;

/**
 * What a restore did, in the terms a reader is told.
 *
 * ## Why the rollback paths are part of the answer and not a side effect
 *
 * Because after a restore the reader's previous install is a directory of files that nothing else
 * knows about. It is the reason they can undo a restore they did not want, and it disappears the
 * first time somebody tidies the data directory.
 *
 * So it is returned, and the command prints it. A restore that reports success and leaves the old
 * files somewhere undocumented has thrown away the only recovery available for a reader who realises
 * ten minutes later that they restored the wrong backup.
 */
final class RestoreOutcome
{
    /**
     * @param  array<string, array<string, string>>  $rollback  Half name, then file name, to where
     *                                                          that file is now.
     */
    public function __construct(
        public readonly BackupPair $pair,
        public readonly array $rollback,
        public readonly int $seriesStates,
        public readonly int $chapterStates,
    ) {}

    /**
     * The lines a reader should read, in order, before anything else.
     *
     * @return list<string>
     */
    public function lines(Translator $translator): array
    {
        return [
            $translator->get('restore.restored', ['description' => $this->pair->describe($translator)]),
            $translator->get('restore.reader_half', ['file' => $this->pair->papyrusFile]),
            $translator->get('restore.manga_half', [
                'file' => $this->pair->suwayomiFile,
                'version' => $this->pair->suwayomiVersion,
            ]),
            $translator->get('restore.holds', [
                'series' => number_format($this->seriesStates),
                'chapters' => number_format($this->chapterStates),
            ]),
            '',
            $translator->get('restore.previous_kept'),
        ];
    }

    /**
     * One line per kept file, naming where it is.
     *
     * Every file, not one per half. The reader's write-ahead log is a file in its own right and it
     * held rows the main database did not have, so a list that named only `database.sqlite` would
     * leave the reader believing their rollback is complete when it is not.
     *
     * @return list<string>
     */
    public function rollbackLines(): array
    {
        $lines = [];

        foreach ($this->rollback as $half => $files) {
            foreach ($files as $name => $path) {
                $lines[] = sprintf('  %-9s %-22s %s', $half, $name, $path);
            }
        }

        return $lines;
    }
}
