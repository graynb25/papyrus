<?php

namespace Tests\Feature;

use App\Import\Tachibk\MangaMerge;
use App\Import\Tachibk\ProtoBuffer;
use App\Import\Tachibk\ProtoWriter;
use App\Import\Tachibk\TachibkMerger;
use App\Import\Tachibk\TachibkSchema;
use Tests\TestCase;

/**
 * The export's byte discipline, on records built from the same wire as the real file.
 *
 * The merger's promises are about bytes, not values, so the tests read their product back
 * through {@see ProtoBuffer} and, where the promise is byte equality, compare raw strings.
 * A rewrite that decoded and re-encoded a record cleanly would pass a value level test
 * while silently normalizing a file another app made, which is exactly what the merger
 * exists not to do.
 */
class TachibkMergerTest extends TestCase
{
    public function test_a_payload_with_no_plans_comes_back_byte_for_byte(): void
    {
        $payload = $this->payload([
            $this->seriesRecord(2327480808438768017, 'rSmmf', 'Eleceed', [
                ['order' => 1, 'number' => 700.0, 'read' => false],
            ]),
        ]);

        $merged = (new TachibkMerger([], []))->merge($payload);

        $this->assertSame($payload, $merged['payload'], 'an unplanned file has nothing to gain, and every byte of it should survive');
        $this->assertSame(0, $merged['read_marks']);
        $this->assertSame(0, $merged['shelves']);
        $this->assertSame([], $merged['series']);
    }

    public function test_an_unplanned_record_passes_through_untouched_between_planned_ones(): void
    {
        $untouched = $this->seriesRecord(99, 'keepMe', 'Untouched', [
            ['order' => 7, 'number' => 12.0, 'read' => false],
        ]);
        $planned = $this->seriesRecord(2327480808438768017, 'rSmmf', 'Eleceed', [
            ['order' => 1, 'number' => 700.0, 'read' => false],
        ]);
        $payload = $this->payload([$untouched, $planned]);

        $merged = (new TachibkMerger([
            '2327480808438768017|rSmmf' => new MangaMerge(2327480808438768017, 'rSmmf', readByOrder: [1 => true]),
        ], []))->merge($payload);

        $this->assertStringContainsString($untouched, $merged['payload'], 'a record with no plan should sit in the output exactly as it went in');
        $this->assertSame(1, $merged['read_marks']);
        $this->assertCount(1, $merged['series'], 'the report should name planned records only');
        $this->assertSame('rSmmf', $merged['series'][0]['url']);
        $this->assertSame('Eleceed', (string) $merged['series'][0]['title']);
    }

    public function test_an_unread_chapter_gains_one_read_mark_and_a_marked_chapter_gains_none(): void
    {
        $record = $this->seriesRecord(10, 'a', 'Both', [
            ['order' => 1, 'number' => 700.0, 'read' => false],
            ['order' => 2, 'number' => 699.0, 'read' => true],
        ]);

        $merged = (new TachibkMerger([
            '10|a' => new MangaMerge(10, 'a', readByOrder: [1 => true, 2 => true]),
        ], []))->merge($this->payload([$record]));

        $this->assertSame(1, $merged['read_marks'], 'a chapter the export already marks read gains nothing');

        $series = $this->soleSeries($merged['payload']);
        $read = [];
        foreach (ProtoBuffer::messages($series, TachibkSchema::SERIES_CHAPTER) as $chapter) {
            $read[] = ProtoBuffer::flag($chapter, TachibkSchema::CHAPTER_READ);
        }

        $this->assertSame([true, true], $read, 'the led mark should land, the carried one should stay');
        $this->assertSame(1, $merged['series'][0]['marks']);
    }

    public function test_a_plan_with_nothing_to_write_leaves_its_record_byte_identical(): void
    {
        $record = $this->seriesRecord(10, 'a', 'Quiet', [
            ['order' => 1, 'number' => 5.0, 'read' => true],
        ]);
        $payload = $this->payload([$record]);

        $merged = (new TachibkMerger([
            '10|a' => new MangaMerge(10, 'a', readByOrder: [1 => true]),
        ], []))->merge($payload);

        $this->assertSame($payload, $merged['payload'], 'marking an already marked chapter should not move one byte');
    }

    public function test_source_order_wins_and_the_truncated_number_speaks_second(): void
    {
        $record = $this->seriesRecord(10, 'a', 'Keys', [
            // Order 3 is known and says not read; the number key would have said read.
            ['order' => 3, 'number' => 700.0, 'read' => false],
            // No carried order at all, so the number key answers, and a fractional
            // number reads by its truncated value.
            ['order' => null, 'number' => 500.5, 'read' => false],
        ]);

        $merged = (new TachibkMerger([
            '10|a' => new MangaMerge(10, 'a', readByOrder: [3 => false], readByNumber: [700 => true, 500 => true]),
        ], []))->merge($this->payload([$record]));

        $this->assertSame(1, $merged['read_marks'], 'the order key answers first, so the number key never overrules it');

        $marks = [];
        $series = $this->soleSeries($merged['payload']);
        foreach (ProtoBuffer::messages($series, TachibkSchema::SERIES_CHAPTER) as $chapter) {
            $marks[] = ProtoBuffer::flag($chapter, TachibkSchema::CHAPTER_READ);
        }

        $this->assertSame([false, true], $marks);
    }

    public function test_shelf_orders_are_appended_to_the_series_record(): void
    {
        $record = $this->seriesRecord(10, 'a', 'Filed', []);

        $merged = (new TachibkMerger([
            '10|a' => new MangaMerge(10, 'a', categoryOrders: [4, 5]),
        ], []))->merge($this->payload([$record]));

        $series = $this->soleSeries($merged['payload']);
        $orders = [];
        foreach (ProtoBuffer::spans($series) as [$number, $wire, $value]) {
            if ($number === TachibkSchema::SERIES_CATEGORY_ORDER && is_int($value)) {
                $orders[] = $value;
            }
        }

        $this->assertSame([4, 5], $orders, 'the series should gain exactly the planned shelf orders, appended at its tail');
        $this->assertSame(2, $merged['series'][0]['shelves']);
    }

    public function test_shelves_the_file_lacks_are_appended_as_whole_records(): void
    {
        $payload = $this->payload([$this->seriesRecord(10, 'a', 'A', [])]);

        $merged = (new TachibkMerger([], [
            ['name' => 'Snowflakes', 'order' => 9],
            ['name' => 'Youtube', 'order' => 10],
        ]))->merge($payload);

        $names = [];
        $orders = [];
        foreach (ProtoBuffer::messages($merged['payload'], TachibkSchema::BACKUP_CATEGORIES) as $record) {
            $names[] = ProtoBuffer::text($record, TachibkSchema::CATEGORY_NAME);
            $orders[] = ProtoBuffer::integer($record, TachibkSchema::CATEGORY_ORDER);
        }

        $this->assertSame(['Snowflakes', 'Youtube'], $names);
        $this->assertSame([9, 10], $orders, 'each appended shelf carries the order the plans will reference');
        $this->assertSame(2, $merged['shelves']);
    }

    public function test_regions_the_merger_never_reads_pass_through_byte_for_byte(): void
    {
        // Suwayomi's own 9000 blob, as the sample carries it: a key and a value.
        $unknown = ProtoWriter::messageField(1, 'webUI_migration').ProtoWriter::messageField(2, '9');
        $payload = $this->payload([$this->seriesRecord(10, 'a', 'A', [])])
            .ProtoWriter::messageField(9000, $unknown)
            .ProtoWriter::varintField(9001, 5);

        $merged = (new TachibkMerger([
            '10|a' => new MangaMerge(10, 'a', readByOrder: [1 => false]),
        ], []))->merge($payload);

        $this->assertSame([$unknown], iterator_to_array(ProtoBuffer::messages($merged['payload'], 9000)), 'a field the format never declared should reach the receiving reader exactly');
        $this->assertSame(5, ProtoBuffer::integer($merged['payload'], 9001));
    }

    public function test_only_forward_means_a_false_mark_writes_nothing(): void
    {
        $record = $this->seriesRecord(10, 'a', 'A', [
            ['order' => 1, 'number' => 1.0, 'read' => false],
        ]);

        $merged = (new TachibkMerger([
            '10|a' => new MangaMerge(10, 'a', readByOrder: [1 => false]),
        ], []))->merge($this->payload([$record]));

        $this->assertSame(0, $merged['read_marks']);
    }

    /**
     * Build a top level payload of series records, in the shape the reader walks.
     *
     * @param  list<string>  $seriesRecords  Wrapped record bytes, value level.
     */
    private function payload(array $seriesRecords): string
    {
        $bytes = '';

        foreach ($seriesRecords as $record) {
            $bytes .= ProtoWriter::messageField(TachibkSchema::BACKUP_SERIES, $record);
        }

        return $bytes;
    }

    /**
     * One series record, value level, with chapters in the shape the file carries them.
     *
     * @param  list<array{order: ?int, number: float, read: bool}>  $chapters
     */
    private function seriesRecord(int $source, string $url, string $title, array $chapters): string
    {
        $bytes = ProtoWriter::varintField(TachibkSchema::SERIES_SOURCE, $source)
            .ProtoWriter::messageField(TachibkSchema::SERIES_URL, $url)
            .ProtoWriter::messageField(TachibkSchema::SERIES_TITLE, $title);

        foreach ($chapters as $chapter) {
            $inner = ProtoWriter::messageField(TachibkSchema::CHAPTER_URL, $url.'-'.$chapter['number'])
                .ProtoWriter::floatField(TachibkSchema::CHAPTER_NUMBER, $chapter['number']);

            if ($chapter['order'] !== null) {
                $inner .= ProtoWriter::varintField(TachibkSchema::CHAPTER_LIST_POSITION, $chapter['order']);
            }

            if ($chapter['read']) {
                $inner .= ProtoWriter::varintField(TachibkSchema::CHAPTER_READ, 1);
            }

            $bytes .= ProtoWriter::messageField(TachibkSchema::SERIES_CHAPTER, $inner);
        }

        return $bytes;
    }

    /**
     * The single series record in a merged payload, unwrapped.
     */
    private function soleSeries(string $payload): string
    {
        foreach (ProtoBuffer::messages($payload, TachibkSchema::BACKUP_SERIES) as $record) {
            return $record;
        }

        $this->fail('the payload should hold a series record');
    }
}
