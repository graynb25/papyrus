<?php

namespace App\Import;

/**
 * The backup formats this server can read, in the order it tries them.
 *
 * ## Why this is a class and not a config array
 *
 * Because the importers depend on it as a constructor argument and the container cannot infer
 * a `list<BackupReader>` from anything. Registered once in `AppServiceProvider`, so adding
 * Mihon is one line there rather than a change to both the inspect command and the importer,
 * which is exactly the coupling the {@see BackupReader} interface was declared to avoid.
 *
 * ## Order
 *
 * Readers are asked in order and the first that recognises the file wins. Recognition is by
 * magic bytes rather than by extension, because the extension is written by another app:
 * Tachimanga names its own files by timestamp, so a reader who renamed one in a file manager
 * has not done anything wrong.
 */
final class BackupReaders
{
    /** @var list<BackupReader> */
    private readonly array $readers;

    /**
     * @param  list<BackupReader>  $readers  Tried in order.
     */
    public function __construct(array $readers)
    {
        $this->readers = $readers;
    }

    /**
     * The reader that recognises this file.
     *
     * @throws ImportRefused When no registered reader claims it, naming what is supported.
     */
    public function for(string $path): BackupReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->recognises($path)) {
                return $reader;
            }
        }

        throw new ImportRefused(sprintf(
            'No reader recognises that file. This server reads backups from: %s. Tachimanga writes a '
            .'.tmb and Mihon or Suwayomi writes a .tachibk.',
            $this->supported(),
        ));
    }

    /**
     * The reader that recognises this file, or null.
     *
     * A second shape of the same question, for callers that report rather than refuse. The
     * inspect command wants to say "no reader recognises this, here is what we do read" and
     * carry on, and doing that through an exception means catching one to do nothing with it.
     */
    public function tryFor(string $path): ?BackupReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->recognises($path)) {
                return $reader;
            }
        }

        return null;
    }

    /**
     * The format names, for a refusal that says what is available.
     */
    public function supported(): string
    {
        return implode(', ', array_map(
            fn (BackupReader $reader): string => $reader->name(),
            $this->readers,
        ));
    }

    /**
     * Every reader, for a caller that wants to survey rather than pick.
     *
     * @return list<BackupReader>
     */
    public function all(): array
    {
        return $this->readers;
    }
}
