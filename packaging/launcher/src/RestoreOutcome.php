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
     * @param  array<string, string>  $rollback  Half name to the path the previous file is now at.
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
    public function lines(): array
    {
        return [
            sprintf('Restored %s.', $this->pair->describe()),
            sprintf('  reader half : %s', $this->pair->papyrusFile),
            sprintf('  manga half  : %s, from Suwayomi %s', $this->pair->suwayomiFile, $this->pair->suwayomiVersion),
            sprintf(
                '  it now holds : %s series and %s chapter states',
                number_format($this->seriesStates),
                number_format($this->chapterStates),
            ),
            '',
            'Your previous files were kept rather than deleted, in case this was the wrong backup:',
        ];
    }

    /**
     * One line per kept file, naming where it is.
     *
     * @return list<string>
     */
    public function rollbackLines(): array
    {
        $lines = [];

        foreach ($this->rollback as $half => $path) {
            $lines[] = sprintf('  %-12s %s', $half, $path);
        }

        return $lines;
    }
}
