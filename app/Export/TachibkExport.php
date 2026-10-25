<?php

namespace App\Export;

use App\Import\SeriesWriter;
use App\Import\Tachibk\MangaMerge;
use App\Import\Tachibk\ProtoBuffer;
use App\Import\Tachibk\TachibkMerger;
use App\Import\Tachibk\TachibkSchema;
use App\Import\Tachibk\TachibkStream;
use App\Models\User;
use App\Support\Library;
use App\Support\ReadingState;
use App\Suwayomi\BackupGateway;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;

/**
 * The outward `.tachibk`: the fresh library this server just exported, with the reader's
 * own arrangement merged into it before anything is written down.
 *
 * ## What this sits between
 *
 * Suwayomi's own export is the truth about a library's shape: series, chapters, read state
 * as the server learned it, and the file's own shelves. The reader half (`chapter_states`,
 * `series_states`, `categories`, `category_series`) is the truth about what the server
 * never learned: the read marks Papyrus carried, and the shelves the reader actually uses.
 * Merged outward, the file carries both, which is what a receiving reader has to have to
 * reconstruct the arrangement rather than one pile of series.
 *
 * ## The rules the walk keeps
 *
 * Everything byte level is {@see TachibkMerger}'s job, and this class plans rather than
 * writes. The plan decisions this class owns, each with its reason:
 *
 * - The export basis is the reader's in-library series, because the collection map and the
 *   ledger describe that set; a series the reader has no state for needs no merge at all.
 * - A series is planned when it carries state the file could be missing: shelf membership
 *   or read marks. A series with neither gains nothing, and planning it would only spend
 *   a rebuild on a byte identical record.
 * - Shelves the file already carries are referenced at their existing order; shelves it
   does not are appended as new records at fresh orders above everything the file used,
   because `order` values must be unique across the file (the receiving restorer
   associates by order) while the names do the actual matching on that side.
 * - Read marks join through the server's own chapter list by order first, then truncated
   number, the same two keys the importer pairs by, so one measured join serves both
   directions.
 */
final class TachibkExport
{
    public function __construct(
        private readonly BackupGateway $backup,
        private readonly SeriesGateway $series,
        private readonly SeriesWriter $writer,
        private readonly ReadingState $reading,
        private readonly Library $library,
    ) {}

    /**
     * Run one export for a reader.
     *
     * @param  string  $sink  The file the export writes, staged fresh and then trimmed with
     *                        the merged payload over it. The caller names the path; the job
     *                        that owns the run row picks where products live.
     * @param  callable(string): void  $note
     *
     * @throws SuwayomiException When the server refuses the export or the transfer breaks.
     */
    public function run(User|int $user, string $sink, callable $note): TachibkExportResult
    {
        $note('Asking the server for a fresh backup of its own library.');
        $path = $this->backup->createBackup();

        $this->backup->download($path, $sink);

        /*
         * The stream decompresses the sink without also holding its compressed bytes,
         * which is the difference between one copy of a large export in memory and two.
         * The merger still needs the payload as one string because it rewrites a record
         * and hands back a new message; the saving here is the file that carried it.
         */
        $payload = TachibkStream::payload($sink);

        if ($payload === false) {
            throw new \RuntimeException("The server's fresh export is not decodable gzip: {$sink}");
        }

        $note('Read the fresh export and mapped what it already carries.');

        $file = $this->walkFileRecords($payload);

        /*
         * The reader's own state, in the three reads the export needs: who is in the
         * library, what the shelves hold, and which chapters carry a mark the server
         * never learned. One query each, keyed by manga id.
         */
        $inLibrary = $this->library->inLibraryIds($user);
        $memberships = $this->library->shelfMembers($user);
        $ledger = $this->reading->readChapterIdsByManga($user, $inLibrary);

        /*
         * Only stateful series go to the merger: a shelf or a mark is the thing a file
         * can be missing, and this is the set that can be missing one.
         */
        $stateful = [];
        foreach ($inLibrary as $mangaId) {
            if (($memberships[$mangaId] ?? []) !== [] || ($ledger[$mangaId] ?? []) !== []) {
                $stateful[$mangaId] = true;
            }
        }

        $identities = $this->series->identities(array_keys($stateful));

        /*
         * Shelves, by name, each at one file order. A shelf the file carries is referenced
         * where it already sits; every other shelf is appended once, above everything the
         * file used, so no two shelf records share an order.
         */
        $shelfNames = [];
        foreach ($memberships as $names) {
            foreach ($names as $name) {
                $shelfNames[$name] = true;
            }
        }

        $nextOrder = $file['maxOrder'] + 1;
        $shelfOrders = [];
        $newShelves = [];

        foreach (array_keys($shelfNames) as $name) {
            $existing = $file['categoryOrders'][$name] ?? null;

            if ($existing !== null) {
                $shelfOrders[$name] = $existing;

                continue;
            }

            $shelfOrders[$name] = $nextOrder;
            $newShelves[] = ['name' => $name, 'order' => $nextOrder];
            $nextOrder++;
        }

        /*
         * One plan per series the reader has something for, keyed exactly the way the
         * merger finds records: source id, their pipe, the source url.
         */
        $plans = [];
        $joined = [];

        foreach ($stateful as $mangaId => $_) {
            $identity = $identities[$mangaId] ?? null;

            if ($identity === null) {
                $joined[$mangaId] = 'the server answers no source and url pair to match the file record by';

                continue;
            }

            $readIds = array_fill_keys($ledger[$mangaId] ?? [], true);
            $theirs = $this->writer->theirChapters((int) $mangaId);

            /*
             * The join inverted: the importer's maps hand chapter ids out to a backup's
             * keys, and here the ledger's chapter ids hand key values in. Both directions
             * ask the same two questions of the same two maps, so one measured join
             * serves both.
             */
            $readByOrder = [];
            $readByNumber = [];

            foreach ($theirs['by_order'] as $order => $chapterId) {
                if (isset($readIds[$chapterId])) {
                    $readByOrder[(int) $order] = true;
                }
            }

            foreach ($theirs['by_number'] as $number => $chapterId) {
                if (isset($readIds[$chapterId])) {
                    $readByNumber[(int) $number] = true;
                }
            }

            $categoryOrders = array_map(
                fn (string $name): int => $shelfOrders[$name],
                $memberships[$mangaId] ?? [],
            );

            $plans[$identity['source'].'|'.$identity['url']] = new MangaMerge(
                sourceId: (int) $identity['source'],
                url: $identity['url'],
                categoryOrders: array_values(array_unique($categoryOrders)),
                readByOrder: $readByOrder,
                readByNumber: $readByNumber,
            );
        }

        $note(count($stateful).' series carry reader state to merge.');

        $merger = new TachibkMerger($plans, $newShelves);
        $merged = $merger->merge($payload);

        /*
         * The merger reports each applied record's source and url, the same two halves
         * the plans were keyed by, so the skipped set falls out of one comparison: a
         * plan whose key no applied report carries planned work the file could not
         * take. Matched counts what writing actually happened to.
         */
        $applied = count($merged['series']);
        $appliedKeys = [];

        foreach ($merged['series'] as $item) {
            $appliedKeys[$item['source'].'|'.$item['url']] = true;
        }

        if ($applied < count($plans)) {
            // One joined entry per plan key, because a fixed literal key would let the
            // second unmatched plan overwrite the first's reason in this very array.
            foreach (array_keys($plans) as $key) {
                if (! isset($appliedKeys[$key])) {
                    $joined[$key] = 'the fresh export carries the series record, but no record matched it';
                }
            }
        }

        /*
         * Written through zlib rather than encoded into a string first, so the merged
         * message and its compressed copy never both exist in memory. The waterfall
         * compressed the record and then wrote it with `file_put_contents`, which is
         * what made a large export need room for both halves at the peak.
         */
        if (! TachibkStream::write($sink, $merged['payload'])) {
            throw new \RuntimeException('The merged export could not be encoded as gzip.');
        }

        /*
         * `gzopen()` writes outside PHP's stat cache, and the sink already existed as the
         * server's downloaded export. Without this clear, `filesize()` can answer with the
         * size of the file that was replaced rather than the one just written.
         */
        clearstatcache(true, $sink);
        $bytes = @filesize($sink);

        if ($bytes === false) {
            throw new \RuntimeException("The merged export could not be read back from the sink: {$sink}");
        }

        return new TachibkExportResult(
            file: $sink,
            fileBytes: $bytes,
            seriesInFile: $file['series'],
            // Planned names every stateful series, whether or not the server could
            // name one: matched plus skipped equals planned only with it counted
            // whole, and a run row that leaves a refusal uncounted underreads itself.
            seriesPlanned: count($stateful),
            seriesMatched: $applied,
            shelvesAppended: $merged['shelves'],
            readMarks: $merged['read_marks'],
            series: $merged['series'],
            skipped: array_values($joined),
        );
    }

    /**
     * What the file already carries of its own collection map.
     *
     * One walk, three facts: how many series records sit in the file, what each shelf
     * record names and what order it claims, and the highest order anyone claimed. A
     * record with no order field reads as order zero on the receiving side, because
     * protobuf omits its defaults, so absence claims zero here too.
     *
     * @return array{series: int, categoryOrders: array<string, int>, maxOrder: int}
     */
    private function walkFileRecords(string $payload): array
    {
        $series = 0;
        $categoryOrders = [];

        foreach (ProtoBuffer::spans($payload) as [$number, $wire, $value]) {
            if ($number === TachibkSchema::BACKUP_SERIES && $wire === ProtoBuffer::WIRE_BYTES) {
                $series++;

                continue;
            }

            if ($number !== TachibkSchema::BACKUP_CATEGORIES || $wire !== ProtoBuffer::WIRE_BYTES || ! is_string($value)) {
                continue;
            }

            $name = ProtoBuffer::text($value, TachibkSchema::CATEGORY_NAME);

            if (! is_string($name) || $name === '' || array_key_exists($name, $categoryOrders)) {
                continue;
            }

            // Absent order is order zero, which is exactly what a receiving reader decodes.
            $order = ProtoBuffer::integer($value, TachibkSchema::CATEGORY_ORDER);
            $categoryOrders[$name] = $order ?? 0;
        }

        return [
            'series' => $series,
            'categoryOrders' => $categoryOrders,
            'maxOrder' => $categoryOrders === [] ? 0 : max($categoryOrders),
        ];
    }
}
