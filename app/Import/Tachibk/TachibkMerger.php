<?php

namespace App\Import\Tachibk;

use App\Import\SeriesWriter;

/**
 * Writes the receive side marks into a `.tachibk` payload, appending and never rewriting.
 *
 * ## The byte discipline, and why it is the whole point
 *
 * The input is a working reader app's own backup, produced by Suwayomi from its live
 * library at the moment the export ran. It carries fields none of this code understands
 * (fields 9000 and 9001 label themselves as one writer's own state) and it carries future
 * fields no current reader has declared yet. The merger therefore has one rule: what it
 * does not plan to change passes through byte for byte, and what it does change gains
 * appended bytes only. A chapter the ledger says was read and the export does not mark as
 * read gains one field. A series filed on a shelf gains its order under field 17. The file
 * gains whole category records. Nothing is ever dropped, reordered or normalized, and the
 * read direction of the rule falls out of the shape: appends cannot un-read a chapter or
 * unfile a series any more than a ledger can.
 *
 * That is also why this class does not use the value readers to rebuild records. Walking
 * {@see ProtoBuffer::spans()} hands back where each field's bytes sit, so untouched
 * regions of the buffer can be copied rather than regenerated. A regenerate would be
 * semantically identical for a canonical varint and subtly wrong for one that is not.
 *
 * Records where something changed do get one honest exception, and it is stated here
 * rather than hidden: the changed field's tag and length prefix are re-encoded
 * canonically, because a length prefix must grow with the field it wraps. Every
 * writer this format has produced uses minimal encodings, so in practice the prefix
 * comes back byte identical; the semantics survive regardless, which is what the wire
 * defines.
 */
final class TachibkMerger
{
    /**
     * @param  array<string, MangaMerge>  $plans  Keyed `sourceId|url`, the same pair a
     *                                            Suwayomi record carries under fields 1
     *                                            and 2. Records whose key is absent pass
     *                                            through untouched.
     * @param  list<array{name: string, order: int}>  $shelves  Category records to append
     *                                                          under field 2, for shelves the
     *                                                          file does not already carry.
     *                                                          Already-carried names stay in
     *                                                          the file untouched, and the
     *                                                          caller references their
     *                                                          existing orders in the plans
     *                                                          instead.
     */
    public function __construct(
        private readonly array $plans,
        private readonly array $shelves,
    ) {}

    /**
     * Merge the plans into the payload.
     *
     * @return array{payload: string, read_marks: int, shelves: int, series: list<array{source: int, url: string, title: ?string, marks: int, shelves: int}>}
     */
    public function merge(string $payload): array
    {
        $output = '';
        $cursor = 0;
        $marks = 0;
        $seriesReport = [];

        foreach (ProtoBuffer::spans($payload) as [$number, $wire, $value, $start, $length]) {
            $output .= substr($payload, $cursor, $start - $cursor);

            if ($number !== TachibkSchema::BACKUP_SERIES || $wire !== ProtoBuffer::WIRE_BYTES || ! is_string($value)) {
                // The gap between fields is written whole, because only series records change.
                $output .= substr($payload, $start, $length);
                $cursor = $start + $length;

                continue;
            }

            $record = $value;
            $cursor = $start + $length;

            $sourceId = ProtoBuffer::value($record, TachibkSchema::SERIES_SOURCE);
            $url = ProtoBuffer::text($record, TachibkSchema::SERIES_URL);

            if (! is_int($sourceId) || $url === null) {
                // Same refusal the reader makes, written at the record level: nothing matched,
                // nothing re-encodes.
                $output .= substr($payload, $start, $length);

                continue;
            }

            $plan = $this->plans[$sourceId.'|'.$url] ?? null;

            if ($plan === null) {
                $output .= substr($payload, $start, $length);

                continue;
            }

            $merged = $this->mergeSeries($record, $plan);
            $output .= ProtoWriter::messageField(TachibkSchema::BACKUP_SERIES, $merged['bytes']);
            $marks += $merged['marks'];

            $seriesReport[] = [
                'source' => $sourceId,
                'url' => $url,
                'title' => ProtoBuffer::text($record, TachibkSchema::SERIES_TITLE),
                'marks' => $merged['marks'],
                'shelves' => count($plan->categoryOrders),
            ];
        }

        $output .= substr($payload, $cursor);
        $shelvesAppended = 0;

        foreach ($this->shelves as $shelf) {
            $record = ProtoWriter::messageField(TachibkSchema::CATEGORY_NAME, $shelf['name'])
                .ProtoWriter::varintField(TachibkSchema::CATEGORY_ORDER, $shelf['order']);

            $output .= ProtoWriter::messageField(TachibkSchema::BACKUP_CATEGORIES, $record);
            $shelvesAppended++;
        }

        return [
            'payload' => $output,
            'read_marks' => $marks,
            'shelves' => $shelvesAppended,
            'series' => $seriesReport,
        ];
    }

    /**
     * Rebuild one series record with its planned appends.
     *
     * @return array{bytes: string, marks: int}
     */
    private function mergeSeries(string $record, MangaMerge $plan): array
    {
        $output = '';
        $marks = 0;

        foreach (ProtoBuffer::spans($record) as [$number, $wire, $value, $fieldStart, $fieldLength]) {
            if ($number !== TachibkSchema::SERIES_CHAPTER || $wire !== ProtoBuffer::WIRE_BYTES || ! is_string($value)) {
                $output .= substr($record, $fieldStart, $fieldLength);

                continue;
            }

            $chapter = $value;

            if ($this->chapterShouldRead($plan, $chapter) === true
                && ProtoBuffer::flag($chapter, TachibkSchema::CHAPTER_READ) === false
            ) {
                /*
                 * The mark is appended into the chapter record, which is where it must live:
                 * field 4 belongs to the chapter, not to the series carrying it. The prefix
                 * around the chapter's own bytes is re-encoded for the only reason it has to
                 * be, a length that grew.
                 */
                $chapter .= ProtoWriter::varintField(TachibkSchema::CHAPTER_READ, 1);
                $marks++;
            }

            $output .= ProtoWriter::messageField(TachibkSchema::SERIES_CHAPTER, $chapter);
        }

        /*
         * Shelf orders and any other appended field come after the record's own tail,
         * because wire semantics read field order as free: nothing on the receiving side
         * cares which end of the record they were added at.
         */
        foreach ($plan->categoryOrders as $order) {
            $output .= ProtoWriter::varintField(TachibkSchema::SERIES_CATEGORY_ORDER, $order);
        }

        return ['bytes' => $output, 'marks' => $marks];
    }

    /**
     * Whether the ledger says this chapter was read, by the importer's own key precedence.
     *
     * Source order first, then the truncated chapter number, exactly the two keys
     * {@see SeriesWriter::pair()} joins a backup to the server by. A chapter
     * matching neither key returns null, meaning the ledger was never asked about it and
     * nothing should be written: absence of evidence is not a mark.
     *
     * @return bool|null Null when neither key was carried.
     */
    private function chapterShouldRead(MangaMerge $plan, string $chapter): ?bool
    {
        /*
         * Field 10 is the source order the file reads from, and this importer never trusts
         * it alone, but the join it goes with is the one measured working: order, then the
         * number, each key asked only for facts the ledger actually holds.
         */
        $order = ProtoBuffer::integer($chapter, TachibkSchema::CHAPTER_LIST_POSITION);

        if ($order !== null && array_key_exists($order, $plan->readByOrder)) {
            return (bool) $plan->readByOrder[$order];
        }

        $single = ProtoBuffer::single($chapter, TachibkSchema::CHAPTER_NUMBER);

        if ($single === null) {
            return null;
        }

        $number = (int) $single;

        if (array_key_exists($number, $plan->readByNumber)) {
            return (bool) $plan->readByNumber[$number];
        }

        return null;
    }
}
