<?php

namespace App\Import\Tachibk;

/**
 * What a `.tachibk` holds, read from its own top level fields, before anything is read
 * as a library.
 *
 * ## Why this exists
 *
 * {@see TachibkReader::recognises()} is narrow on purpose: gzip magic, and at least one
 * field 1 record that itself parses and holds a title. That is narrow enough to miss
 * a CBZ, which is also gzip, and wide enough that no reader app has to care.
 *
 * But the narrowness has one measured consequence that matters to a reader. A server
 * whose own library holds nothing still writes a real backup, and that file has no
 * series record in it at all. Measured against a live v2.4.2366 whose library was
 * empty: the file is 352 bytes, its payload holds exactly one category record (field
 * 2, name "Default", no order) and one Suwayomi-only field 9001, and **zero** field
 * 1 records. The reader's gate refuses it, and the refusal says the file "is not a
 * Mihon or Suwayomi backup", which is true of the bytes and wrong about the cause:
 * the server answered with a genuine backup of an empty library.
 *
 * So this class asks the narrower question first: does the file look like a backup at
 * all, and does it hold any series? The answer is a reading, not a refusal, and the
 * caller decides what to do with it.
 *
 * ## What it does not decide
 *
 * It does not decide whether a file is a backup. That is the reader's gate, and a
 * gzip that is not a backup must never be read as one. This class only ever runs on
 * a file the caller has already decided is worth looking at, and it reports what the
 * top level fields say.
 */
final class TachibkFile
{
    /**
     * What a file's own top level fields carry.
     *
     * @return array{series: int, categories: int, sources: int, bytes: int, gzip: bool}
     */
    public static function describe(string $path): array
    {
        $empty = ['series' => 0, 'categories' => 0, 'sources' => 0, 'bytes' => 0, 'gzip' => false];

        if (! is_file($path) || ! is_readable($path)) {
            return $empty;
        }

        /*
         * The file may have just been written by a download or an upload, and PHP's stat
         * cache can answer with the size it had before that write. The stream walk below
         * is the real proof, but this number is reported to the reader and has to be the
         * one on disk now.
         */
        clearstatcache(true, $path);

        $bytes = @filesize($path);

        if ($bytes === false) {
            return $empty;
        }

        $empty['bytes'] = $bytes;

        $magic = @file_get_contents($path, false, null, 0, 2);

        if ($magic !== TachibkSchema::GZIP_MAGIC) {
            return $empty;
        }

        $empty['gzip'] = true;

        $stream = TachibkStream::open($path);

        if ($stream === null) {
            return $empty;
        }

        try {
            /*
             * Only the three record numbers the counts need are returned. A backup also
             * carries application state and, in newer writers, fields with no name here;
             * skipping them at the stream is the difference between counting a large
             * library and allocating it.
             */
            $wanted = [
                TachibkSchema::BACKUP_SERIES,
                TachibkSchema::BACKUP_CATEGORIES,
                TachibkSchema::BACKUP_SOURCE,
            ];

            foreach ($stream->fields($wanted) as $number => [$wire]) {
                if ($wire !== ProtoBuffer::WIRE_BYTES) {
                    continue;
                }

                if ($number === TachibkSchema::BACKUP_SERIES) {
                    $empty['series']++;
                } elseif ($number === TachibkSchema::BACKUP_CATEGORIES) {
                    $empty['categories']++;
                } elseif ($number === TachibkSchema::BACKUP_SOURCE) {
                    $empty['sources']++;
                }
            }
        } catch (MalformedProto) {
            /*
             * A payload that stops mid-field is not a backup, and reporting the records
             * that happened to come before the break would let a truncated file with a
             * shelf record read as a genuine backup of an empty library. The whole-file
             * path answered zero for the same bytes, so this does too.
             */
            $empty['series'] = 0;
            $empty['categories'] = 0;
            $empty['sources'] = 0;

            return $empty;
        } finally {
            $stream->close();
        }

        return $empty;
    }

    /**
     * Whether the file is a backup of a server whose own library holds nothing.
     *
     * The shape is measured, not guessed: gzip magic, at least one field 2 record
     * (the server's own default shelf, which every server writes), and no field 1
     * record at all. A file that is not gzip, or that holds no category record, is
     * not this shape, and the caller says what it is instead.
     */
    public static function isAnEmptyLibraryBackup(string $path): bool
    {
        $described = self::describe($path);

        return $described['gzip']
            && $described['series'] === 0
            && $described['categories'] > 0;
    }
}
