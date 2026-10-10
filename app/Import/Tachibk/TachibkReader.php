<?php

namespace App\Import\Tachibk;

use App\Import\BackupReader;
use App\Import\BackupSource;
use App\Import\ImportedBackup;
use App\Import\ImportedChapter;
use App\Import\ImportedSeries;
use App\Import\ImportRefused;
use Illuminate\Support\Collection;

/**
 * Reads a `.tachibk`, which is a protobuf message inside a gzip, and which Mihon, Tachiyomi
 * and Suwayomi between them all write.
 *
 * ## What this reader is trusted with
 *
 * It runs before anything is validated, on a file from an app this server has never seen, and
 * its output becomes a reader's library. So the two rules it follows are: never guess at a
 * value, and refuse rather than import something that looks wrong.
 *
 * The guessing is the interesting half, because this format invites it. Almost every field in
 * a `.tachibk` *could* be mapped onto something, and three of them are understood well enough
 * to be mapped wrongly. {@see TachibkSchema} records which those are and why each is left
 * alone. In short: the exporter's own timestamps are not the reader's dates, a positional
 * index is a sort order rather than a source order, and a second read shaped flag on exactly
 * the read chapters cannot be told apart from a bookmark without a file that separates them.
 *
 * ## What a `.tachibk` does not carry
 *
 * Categories, downloads, ratings, resume points and package names. Each of those is a thing
 * a reader has and expects to keep, and each is simply not in the file. The importer reports
 * what arrived rather than implying the rest came with it, so "4 series, 1,616 chapters read,
 * 0 categories" is the honest outcome for this format and not a failure.
 *
 * ## Why a hand written protobuf walk and not a dependency
 *
 * The wire format is four cases and {@see ProtoBuffer} is all of them. See that class for why
 * a runtime and a code generator are not worth it to read one file type.
 *
 * ## What has and has not been tested against
 *
 * This was written against a real Suwayomi written backup of four series, 2,911 chapters and
 * 1,616 read chapters, read byte by byte. The numbers in {@see TachibkSchema} are that file's
 * and not a published schema's. Mihon's own exporter declares the same field numbers for the
 * fields mapped here, so a Mihon backup should read correctly, but that has not been tested
 * against a file Mihon wrote and this reader does not claim it has.
 */
final class TachibkReader implements BackupReader
{
    /**
     * Two bytes, and then a walk.
     *
     * The gzip magic on its own would claim every compressed file on the disk, so it is only
     * the first check. What settles it is that the decompressed bytes parse as a protobuf
     * message and carry at least one series shaped entry inside: a length delimited field 1
     * that itself parses and holds a title. That is narrow enough to miss a CBZ, which is
     * also gzip, and wide enough that no reader app has to care.
     *
     * Never throws. This runs against files that are not backups at all, so every path out of
     * it is a boolean and a malformed file is a `false` rather than an exception.
     */
    public function recognises(string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $magic = @file_get_contents($path, false, null, 0, 2);

        if ($magic !== TachibkSchema::GZIP_MAGIC) {
            return false;
        }

        try {
            /*
             * `@`, because a file cut short is one of the things this has to answer rather
             * than complain about. gzdecode emits a warning before it returns false, and a
             * refusal printed above a stack trace is worse than the refusal.
             */
            $payload = @gzdecode((string) @file_get_contents($path));

            if ($payload === false) {
                return false;
            }

            foreach (ProtoBuffer::messages($payload, TachibkSchema::BACKUP_SERIES) as $series) {
                if (ProtoBuffer::text($series, TachibkSchema::SERIES_TITLE) !== null) {
                    return true;
                }
            }

            return false;
        } catch (MalformedProto) {
            return false;
        }
    }

    public function name(): string
    {
        return 'tachibk';
    }

    /**
     * @throws ImportRefused
     */
    public function read(string $path): ImportedBackup
    {
        if (! $this->recognises($path)) {
            throw new ImportRefused(
                'That file is not a Mihon or Suwayomi backup. It has to be the .tachibk that one '
                .'of those apps writes from its own backup screen.'
            );
        }

        $payload = @gzdecode((string) @file_get_contents($path));

        if ($payload === false) {
            throw new ImportRefused(
                'That backup could not be decompressed. It may be damaged, so export it again '
                .'and nothing needs to change on this server.'
            );
        }

        try {
            $names = $this->sourceNames($payload);
            [$series, $total] = $this->series($payload);
        } catch (MalformedProto $e) {
            /*
             * `recognises()` stops at the first series that looks right, so it can return true
             * on a file that goes wrong further in. This is where that second walk happens and
             * where such a file is actually turned away, rather than halfway through writing.
             */
            throw new ImportRefused($e->getMessage());
        }

        return new ImportedBackup(
            format: $this->name(),
            /*
             * This format states no version anywhere. Null says so honestly, where 0 would be
             * a number the file never wrote and the report would print as though it had.
             */
            schemaVersion: null,
            series: new Collection($series),
            categories: new Collection,
            totalInFile: $total,
            sources: $this->sources($series, $names),
        );
    }

    /**
     * Source id to source name, from the top level list of sources.
     *
     * A series only ever carries the source's id, which is a number a reader has never seen
     * and would not recognise. The name is what turns "2327480808438768017" into "Atsumaru",
     * and it travels in the one top level field that carries one.
     *
     * @return array<int, string>
     *
     * @throws MalformedProto
     */
    private function sourceNames(string $payload): array
    {
        $names = [];

        foreach (ProtoBuffer::messages($payload, TachibkSchema::BACKUP_SOURCE) as $source) {
            $id = ProtoBuffer::integer($source, 2);
            $name = ProtoBuffer::text($source, 1);

            if ($id !== null && $name !== null) {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * Every series the backup holds, which for this format is the reader's whole library.
     *
     * There is no in_library flag here to forget, which is the mistake the Tachimanga reader
     * has to be careful about. A `.tachibk` only ever holds what the reader chose to keep.
     *
     * Returns the count of series the file held alongside the ones worth importing, because
     * they are different numbers. A series with nothing to show and nothing to match on is
     * skipped rather than imported as a blank row, and reporting only the survivors would
     * quietly claim the file never held it.
     *
     * @return array{list<ImportedSeries>, int}
     *
     * @throws MalformedProto
     */
    private function series(string $payload): array
    {
        $series = [];
        $position = 0;

        foreach (ProtoBuffer::messages($payload, TachibkSchema::BACKUP_SERIES) as $value) {
            $position++;

            $title = ProtoBuffer::text($value, TachibkSchema::SERIES_TITLE);
            $url = ProtoBuffer::text($value, TachibkSchema::SERIES_URL);

            if ($title === null && $url === null) {
                continue;
            }

            $series[] = new ImportedSeries(
                /*
                 * There is no local series id in this format to borrow, so this is the position
                 * in the file. It exists only so a caller can hold a reference while inside
                 * this one backup, exactly as it does for the Tachimanga reader, and the
                 * pipeline pairs a series by url and never by this.
                 */
                backupId: $position,
                sourceId: ProtoBuffer::integer($value, TachibkSchema::SERIES_SOURCE) ?? 0,
                url: $url ?? '',
                title: $title ?? '',
                chapters: $this->chapters($value),
            );
        }

        return [$series, $position];
    }

    /**
     * One series' chapters, carrying across only what can be paired and only what is known.
     *
     * @return Collection<int, ImportedChapter>
     *
     * @throws MalformedProto
     */
    private function chapters(string $series): Collection
    {
        $chapters = new Collection;
        $position = 0;

        foreach (ProtoBuffer::messages($series, TachibkSchema::SERIES_CHAPTER) as $chapter) {
            $position++;

            $chapters->push(new ImportedChapter(
                backupId: $position,
                number: $this->number($chapter),
                name: ProtoBuffer::text($chapter, TachibkSchema::CHAPTER_NAME),
                isRead: ProtoBuffer::flag($chapter, TachibkSchema::CHAPTER_READ),
            ));
        }

        return $chapters;
    }

    /**
     * The sources the shelf sits on, largest first, so an install can be driven from it.
     *
     * Only sources with series against them. The file names one source in the sample and has
     * series on it, but the general rule holds: a backup naming every source it has ever seen
     * would install a great deal a reader never asked for.
     *
     * Two values are deliberately empty rather than guessed. `package` is null because this
     * format carries no package name anywhere in the sample, and `obsolete` is false because
     * it carries no obsolescence flag either. False here means "nothing in this backup says
     * this source is gone", which is not the same claim as "this source is alive", and the
     * installer is what turns it into one by asking the server.
     *
     * @param  list<ImportedSeries>  $series
     * @param  array<int, string>  $names
     * @return Collection<int, BackupSource>
     */
    private function sources(array $series, array $names): Collection
    {
        $counts = [];

        foreach ($series as $entry) {
            $counts[$entry->sourceId] = ($counts[$entry->sourceId] ?? 0) + 1;
        }

        arsort($counts);

        $sources = new Collection;

        foreach ($counts as $id => $count) {
            $sources->push(new BackupSource(
                sourceId: $id,
                package: null,
                seriesCount: $count,
                name: $names[$id] ?? null,
            ));
        }

        return $sources;
    }

    /**
     * A chapter number, rounded away from a float the wire format can only approximate.
     *
     * A fixed32 holds seven significant digits, so 700.5 arrives exactly and 1998.1234567 does
     * not. Rounding to three decimals rather than truncating keeps the fraction a reader would
     * recognise, and the pairing the pipeline does is on the whole part anyway.
     */
    private function number(string $chapter): ?float
    {
        $value = ProtoBuffer::single($chapter, TachibkSchema::CHAPTER_NUMBER);

        return $value === null ? null : round($value, 3);
    }
}
