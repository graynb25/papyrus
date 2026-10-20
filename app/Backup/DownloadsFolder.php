<?php

namespace App\Backup;

use App\Suwayomi\FailureKind;
use App\Suwayomi\Gateway;
use App\Suwayomi\SuwayomiException;

/**
 * Suwayomi's own downloads folder, and how big it is.
 *
 * ## Why a page class, when the run already asks this
 *
 * The size the reader press against lives in one folder: the one the optional downloads
 * half zips. Asking it through a class of its own is what keeps those two from splitting
 * into two askers with two answers, the way a page that measured its own path would.
 * The query text is byte for byte the one {@see LiveBackup::run()} sends, so a transport
 * that only routes one of them does not exist to route.
 *
 * ## Unknown is an answer
 *
 * The page names the size before the press. When Suwayomi cannot be asked just now
 * (unreachable, or it answers with a path that is not a folder on this machine), the
 * honest answer is that the size is unknown, and the page says so, in the same shape a
 * fallback that names a broken thing is refused: null here, a sentence there, and no
 * invented number between them.
 */
final class DownloadsFolder
{
    public function __construct(private readonly Gateway $gateway) {}

    /**
     * The folder Suwayomi writes downloads into, refusing anything that is not one.
     *
     * @throws SuwayomiException Unreachable, or the answer is not a folder on this machine.
     */
    public function path(): string
    {
        $data = $this->gateway->execute(<<<'GQL'
            query DownloadsLocation {
                settings {
                    downloadsPath
                }
            }
            GQL);

        $path = $data['settings']['downloadsPath'] ?? null;

        if (! is_string($path) || trim($path) === '' || ! is_dir($path)) {
            throw new SuwayomiException(
                sprintf(
                    'Suwayomi answered its downloads folder as %s, which does not read as a '
                    .'folder that exists.',
                    is_string($path) && trim($path) !== '' ? trim($path) : '(nothing it answered with)',
                ),
                FailureKind::Unavailable,
            );
        }

        return $path;
    }

    /**
     * The bytes under the folder, null when it cannot be measured just now.
     *
     * Counted rather than taken from the filesystem, the same reason
     * {@see BackupLibrary::leftovers()} counts its rollback folders: a folder the OS
     * reports a size for reports something different per platform and per journal, and
     * the number the reader is shown is the number of pages on their disk.
     */
    public function bytes(): ?int
    {
        try {
            $path = $this->path();
        } catch (SuwayomiException) {
            return null;
        }

        $total = 0;

        /** @var \SplFileInfo $entry */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile()) {
                $total += $entry->getSize();
            }
        }

        return $total;
    }
}
