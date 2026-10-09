<?php

namespace App\Import;

use App\Support\ReadingState;

/**
 * Reads one other reader app's library export, whatever shape that app writes it.
 *
 * ## Why an interface exists at all
 *
 * Because a Mihon `.tachibk` is protobuf inside gzip and a Tachimanga `.tmb` is a zip
 * holding a zip holding SQLite. They share nothing below the parsing, so without this
 * boundary the resolver, the chapter remap and the writer would end up branching on a
 * file extension, and each new format would be a change to all three.
 *
 * The commitment this interface buys is specific: adding a format is a new
 * implementation, not a reshape of the pipeline. That is why it is declared here in
 * phase 1 rather than introduced when the second format arrives, when the resolver
 * would already have been written around the first one's shape.
 *
 * ## What every implementation owes the caller
 *
 * 1. Series ids from the backup are **meaningless outside it**. They are local
 *    autoincrements. A reader hands back `backupId` for its own bookkeeping and the
 *    pipeline resolves identity from {@see ImportedSeries::sourceId} and
 *    {@see ImportedSeries::url} instead.
 * 2. A file that is not this format must raise {@see ImportRefused}, not return an
 *    empty backup. An empty result and an unreadable file look identical downstream.
 * 3. Reading must not write. Every write happens in a later phase through
 *    {@see ReadingState}.
 */
interface BackupReader
{
    /**
     * The format name, for reporting and for the reader's own logs.
     */
    public function name(): string;

    /**
     * Whether this reader recognises the file, from its magic bytes.
     *
     * Sniffing rather than trusting the extension, because the extension is chosen by
     * another app and costs the reader nothing to lie about it: Tachimanga names its
     * own files by timestamp, and a reader who renamed one to `.zip` in a file manager
     * is not trying to deceive anyone.
     *
     * Must not throw, and must not read the whole file. A caller tries each reader in
     * turn, so this runs before the archive is opened.
     */
    public function recognises(string $path): bool;

    /**
     * Read the file, or raise {@see ImportRefused} explaining why it cannot be.
     *
     * @throws ImportRefused
     */
    public function read(string $path): ImportedBackup;
}
