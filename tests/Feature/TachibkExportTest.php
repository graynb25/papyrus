<?php

namespace Tests\Feature;

use App\Export\TachibkExport;
use App\Import\Tachibk\ProtoBuffer;
use App\Import\Tachibk\TachibkSchema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsTachibkBackups;
use Tests\Support\MintsTokens;
use Tests\Support\StagesTachibkExports;
use Tests\TestCase;

/**
 * The outward half of the round trip, against a file built at the fixture's own wire.
 *
 * The fresh server export is staged by a bound {@see BackupGateway} fake that names a
 * real fixture file, and the ledger half is the four real tables. Everything the class
 * promises in its own words has a test here: the merged file gains the reader's read
 * marks and shelves and loses nothing the file already carried, a series the server
 * cannot name and a series the file cannot take are reported rather than silenced, and
 * a series with nothing to merge never spends a plan.
 */
class TachibkExportTest extends TestCase
{
    use BuildsTachibkBackups, MintsTokens, RefreshDatabase, StagesTachibkExports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->answers = [];

        $this->fakeTheExportLedgerQueries();
    }

    /**
     * The arrangement the owner showed in their reader's own pills, going out whole.
     *
     * Naruto carries five chapters the file marks read for orders 1 to 3 and leaves
     * unread for 4 to 5. The ledger says the reader read orders 4 to 5, exactly the
     * chapters the server never learned about. The reader has two shelves, one the
     * file already carries as `Default` with no order and one it has never heard of.
     * Both come back: the carried one referenced at order zero, the new one appended
     * at the order above everything the file used, and the manga record gains orders
     * 0 and 1 under field 17.
     */
    public function test_an_export_merges_the_ledgers_marks_and_shelves_onto_the_fresh_file(): void
    {
        $this->withCategories = true;

        $fixture = $this->buildTachibkBackup();
        $this->stageFreshExportFrom($fixture);

        $user = User::factory()->create();
        $seriesState = $this->ownSeries($user, 76);
        $default = $this->ownShelf($user, 'Default');
        $readingSoon = $this->ownShelf($user, 'Reading soon');
        $this->fileUnder($user, $default, $seriesState);
        $this->fileUnder($user, $readingSoon, $seriesState);
        // The server's own chapter ids, exactly what the ledger's join keys on: ids 901
        // to 905 are orders 1 to 5 of the fixture's Naruto, and 904 and 905 are read
        // there according to nobody but this ledger.
        $this->markRead($user, 76, 904);
        $this->markRead($user, 76, 905);

        $this->answerIdentities([76 => ['source' => '2327480808438768017', 'url' => 'rSmmf']]);
        $this->answerSeries(76, [
            ['id' => 901, 'sourceOrder' => 1, 'chapterNumber' => 700.5],
            ['id' => 902, 'sourceOrder' => 2, 'chapterNumber' => 700],
            ['id' => 903, 'sourceOrder' => 3, 'chapterNumber' => 699],
            ['id' => 904, 'sourceOrder' => 4, 'chapterNumber' => 698],
            ['id' => 905, 'sourceOrder' => 5, 'chapterNumber' => 686.5],
        ]);

        $sink = $this->temporaryFile('papyrus-export-', '.tachibk');
        $result = app(TachibkExport::class)->run($user, $sink, $this->notes());

        $this->assertSame(2, $result->seriesInFile);
        $this->assertSame(1, $result->seriesPlanned);
        $this->assertSame(1, $result->seriesMatched);
        $this->assertSame([], $result->skipped);

        // The chapter the server never learned about is the chapter the file gains.
        $this->assertSame(2, $result->readMarks);
        $this->assertSame(1, $result->shelvesAppended);
        $this->assertSame(1, count($result->series), 'the report should carry matched records only');
        $this->assertSame(2, $result->series[0]['marks']);
        $this->assertSame(2, $result->series[0]['shelves']);
        $this->assertSame('rSmmf', $result->series[0]['url']);
        $this->assertSame('Naruto', (string) $result->series[0]['title']);
        $this->assertSame(filesize($sink), $result->fileBytes, 'the byte count should be measured off the file that actually landed');

        $payload = $this->mergedPayload($sink);

        $this->assertSame([true, true, true, true, true], $this->readFlagsOf($payload, 'Naruto'),
            'the two marks append to the chapters the file left unread, and the three carried ones stay');
        $this->assertSame([false, false], $this->readFlagsOf($payload, 'A Short Shelf'),
            'the unplanned series keeps exactly what the file gave it');

        $shelves = $this->shelvesOf($payload);

        // The file's own record first, then the appended one: the file walked in order
        // and the merger appends at the tail. The carried shelf has no order on the
        // wire, which reads as zero, and the new shelf takes the order above it.
        $this->assertSame('Default', $shelves[0]['name']);
        $this->assertTrue($shelves[0]['order'] === null || $shelves[0]['order'] === 0);
        $this->assertSame('Reading soon', $shelves[1]['name']);
        $this->assertSame(1, $shelves[1]['order']);
    }

    public function test_a_stateful_series_the_server_cannot_name_is_planned_and_reported_as_skipped(): void
    {
        $fixture = $this->buildTachibkBackup();
        $this->stageFreshExportFrom($fixture);

        $user = User::factory()->create();
        $this->ownSeries($user, 76);
        $this->markRead($user, 76, 904);

        // No identity at all: the gateway drops an id the server does not know.
        $this->answerIdentities([]);

        $sink = $this->temporaryFile('papyrus-export-', '.tachibk');
        $result = app(TachibkExport::class)->run($user, $sink, $this->notes());

        $this->assertSame(1, $result->seriesPlanned);
        $this->assertSame(0, $result->seriesMatched);
        $this->assertSame(0, $result->readMarks);
        $this->assertSame([
            'the server answers no source and url pair to match the file record by',
        ], $result->skipped);

        $this->assertSame(
            gzdecode((string) file_get_contents($fixture)),
            $this->mergedPayload($sink),
            'a series nothing can match must leave the file byte for byte, and something that re-encoded it would not',
        );
    }

    public function test_a_planned_series_whose_record_the_file_does_not_carry_is_reported_as_skipped(): void
    {
        $fixture = $this->buildTachibkBackup();
        $this->stageFreshExportFrom($fixture);

        $user = User::factory()->create();
        $this->ownSeries($user, 76);
        $this->markRead($user, 76, 904);

        // Named by the server, so a plan is built, but the pair names no record the
        // file carries. The exporter refuses to invent a weaker key out of what
        // remains, so the plan is reported rather than applied.
        $this->answerIdentities([76 => ['source' => '999', 'url' => 'ghost']]);

        $sink = $this->temporaryFile('papyrus-export-', '.tachibk');
        $result = app(TachibkExport::class)->run($user, $sink, $this->notes());

        $this->assertSame(1, $result->seriesPlanned);
        $this->assertSame(0, $result->seriesMatched);
        $this->assertSame(['the fresh export carries the series record, but no record matched it'], $result->skipped);
        $this->assertSame(0, $result->shelvesAppended);
    }

    public function test_a_series_with_neither_shelf_nor_mark_spends_no_plan_at_all(): void
    {
        $fixture = $this->buildTachibkBackup();
        $this->stageFreshExportFrom($fixture);

        $user = User::factory()->create();
        // Held in the library, and nothing else: no shelf membership, no read mark.
        // The identifier never gets called, because the stateful set is empty.
        $this->ownSeries($user, 88);

        $sink = $this->temporaryFile('papyrus-export-', '.tachibk');
        $result = app(TachibkExport::class)->run($user, $sink, $this->notes());

        $this->assertSame(2, $result->seriesInFile);
        $this->assertSame(0, $result->seriesPlanned);
        $this->assertSame(0, $result->seriesMatched);
        $this->assertSame(0, $result->shelvesAppended);
        $this->assertSame(0, $result->readMarks);
        $this->assertSame([], $result->series);
        $this->assertSame(
            gzdecode((string) file_get_contents($fixture)),
            $this->mergedPayload($sink),
            'a library the fresh export already describes returns byte for byte',
        );
    }

    /**
     * The progress lines a run's caller owns; an unowned copy would be a second writer.
     */
    private function notes(): \Closure
    {
        return function (string $line): void {};
    }

    /**
     * The merged file, read back the only way anything else will read it: decompressed.
     */
    private function mergedPayload(string $sink): string
    {
        $payload = gzdecode((string) file_get_contents($sink));

        if (! is_string($payload)) {
            $this->fail('the merged file is not readable gzip');
        }

        return $payload;
    }

    /**
     * The chapter read flags of one series in the file, in the order the file carries them.
     *
     * @return list<bool>
     */
    private function readFlagsOf(string $payload, string $title): array
    {
        $flags = [];

        foreach (ProtoBuffer::messages($payload, TachibkSchema::BACKUP_SERIES) as $series) {
            if (ProtoBuffer::text($series, TachibkSchema::SERIES_TITLE) !== $title) {
                continue;
            }

            foreach (ProtoBuffer::messages($series, TachibkSchema::SERIES_CHAPTER) as $chapter) {
                $flags[] = ProtoBuffer::flag($chapter, TachibkSchema::CHAPTER_READ);
            }
        }

        return $flags;
    }

    /**
     * @return list<array{name: string, order: int|null}>
     */
    private function shelvesOf(string $payload): array
    {
        $shelves = [];

        foreach (ProtoBuffer::messages($payload, TachibkSchema::BACKUP_CATEGORIES) as $record) {
            $shelves[] = [
                'name' => (string) ProtoBuffer::text($record, TachibkSchema::CATEGORY_NAME),
                'order' => ProtoBuffer::integer($record, TachibkSchema::CATEGORY_ORDER),
            ];
        }

        return $shelves;
    }
}
