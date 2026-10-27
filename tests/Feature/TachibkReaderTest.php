<?php

namespace Tests\Feature;

use App\Import\BackupReaders;
use App\Import\ImportRefused;
use App\Import\Tachibk\TachibkReader;
use Tests\Support\BuildsTachibkBackups;
use Tests\TestCase;
use ZipArchive;

/**
 * Reading a `.tachibk`, against a synthetic one built from the same bytes as the real file.
 *
 * ## What this reader is trusted with
 *
 * It runs before anything is validated, on a file from an app this server has never seen, and
 * its output becomes a reader's library. So most of what follows is about three ways a reader
 * like this goes quietly wrong.
 *
 * 1. **A flag that is absent is not a flag that is false, and neither is it true.**
 *    Protobuf omits a false rather than writing zero, so `integer($bytes, 4) !== 0` is true
 *    for every chapter in a backup. That version of this reader reported 2,911 chapters read
 *    out of a file holding 1,616, and every other number it printed stayed correct. One test
 *    here exists only to keep that from coming back.
 * 2. **A source id is past 2^53.** It is also what identifies a series to the server it is
 *    being imported into, so rounding it means matching nothing and reporting series that
 *    cannot be placed with no explanation.
 * 3. **A number that means something on your screen is not automatically the right thing to
 *    import.** Three fields here could each be mapped and each would be wrong. They are
 *    covered by their own tests below.
 *
 * ## The real file is checked elsewhere
 *
 * A reader's own backup does not belong in a test suite, and it is not committed.
 * `.tools/suwayomi/probe-tachibk-read.php` reads it and prints what comes out, and
 * `.tools/suwayomi/probe-tachibk-8.py` decodes the same bytes with an independent parser in
 * another language. Two decoders agreeing on 4 series, 2,911 chapters and 1,616 read is what
 * the numbers in `TachibkSchema` rest on.
 */
class TachibkReaderTest extends TestCase
{
    use BuildsTachibkBackups;

    private ?string $built = null;

    protected function tearDown(): void
    {
        if ($this->built !== null && is_file($this->built)) {
            @unlink($this->built);
        }

        $this->built = null;

        parent::tearDown();
    }

    private function reader(): TachibkReader
    {
        return new TachibkReader;
    }

    public function test_it_recognises_a_backup_and_refuses_everything_else(): void
    {
        $this->built = $this->buildTachibkBackup();

        $this->assertTrue($this->reader()->recognises($this->built), 'a backup should be recognised');

        // A CBZ this server writes is a zip, and a gzipped page image is gzip. Neither is this.
        $cbz = (string) $this->temporaryFile('papyrus-cbz-');
        $zip = new ZipArchive;
        $zip->open($cbz, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('001.jpg', 'not really a jpeg');
        $zip->close();

        $this->assertFalse($this->reader()->recognises($cbz), 'a plain zip is not a backup');
        @unlink($cbz);

        // Gzip on its own is the whole first check, and it is not enough.
        $gzipped = (string) $this->temporaryFile('papyrus-gz-');
        file_put_contents($gzipped, (string) gzencode('compressed, and still not a backup'));

        $this->assertFalse($this->reader()->recognises($gzipped), 'gzip alone is not a backup');
        @unlink($gzipped);

        $this->assertFalse($this->reader()->recognises(__FILE__), 'a php file is not a backup');
        $this->assertFalse($this->reader()->recognises(sys_get_temp_dir().'/no-such-backup.tachibk'));
        $this->assertFalse($this->reader()->recognises(sys_get_temp_dir()));
    }

    /**
     * A field over the configured ceiling is still this format, not a foreign file.
     *
     * The stream refuses the declared length before allocating it. Recognition has to pass
     * that failure through to the read pass rather than reporting "no reader recognises",
     * because the reader did ask to import and the cap is a refusal with words they can act
     * on. A plain gzip or a CBZ is still refused as unrecognised below.
     */
    public function test_an_over_cap_field_is_recognised_and_refused_with_the_cap_sentence(): void
    {
        config(['papyrus.import.max_field_bytes' => 16]);

        $this->declaredSeriesBytes = 64 * 1024 * 1024;
        $this->built = $this->buildTachibkBackup();

        $readers = new BackupReaders([$this->reader()]);

        $this->assertSame(
            'tachibk',
            $readers->for($this->built)->name(),
            'a field over the cap is this format, so the read pass has to run',
        );

        try {
            $this->reader()->read($this->built);
            $this->fail('the cap should refuse the file during the read pass');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString(
                'one field claims a length larger than the 16 B this server reads in a single field',
                $e->getMessage(),
            );
            $this->assertStringContainsString('Nothing has been changed', $e->getMessage());
        }
    }

    /**
     * The cap marker must not turn every malformed gzip into a recognised backup.
     *
     * This is the boundary the marker exists to keep: a gzip whose payload is not protobuf
     * still gets the resolver's "No reader recognises" refusal, not a claim followed by a
     * parse error.
     */
    public function test_a_gzip_that_is_not_a_tachibk_is_still_refused_as_unrecognised(): void
    {
        $this->gzipButNotProtobuf = true;
        $this->built = $this->buildTachibkBackup();

        $readers = new BackupReaders([$this->reader()]);

        try {
            $readers->for($this->built);
            $this->fail('a gzip that is not protobuf must not be claimed');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString('No reader recognises that file', $e->getMessage());
        }
    }

    public function test_a_valid_backup_is_still_recognised_and_read_through_the_resolver(): void
    {
        $this->built = $this->buildTachibkBackup();

        $reader = (new BackupReaders([$this->reader()]))->for($this->built);

        $this->assertSame('tachibk', $reader->name());
        $this->assertSame(2, $reader->read($this->built)->series->count());
    }

    public function test_it_reads_the_library_with_its_read_state(): void
    {
        $this->built = $this->buildTachibkBackup();

        $backup = $this->reader()->read($this->built);
        $summary = $backup->summary();

        $this->assertSame('tachibk', $summary['format']);
        $this->assertSame(2, $summary['library_series']);
        $this->assertSame(2, $summary['series_in_file']);
        $this->assertSame(7, $summary['chapters']);
        $this->assertSame(3, $summary['chapters_read']);

        $naruto = $backup->series->firstWhere('title', 'Naruto');

        $this->assertNotNull($naruto, 'the longer shelf should be found by its title');
        $this->assertSame('rSmmf', $naruto->url);
        $this->assertSame(5, $naruto->chapters->count());
    }

    /**
     * The regression test for the bug this reader actually shipped with.
     *
     * Protobuf leaves out a false rather than writing zero, so a read flag that is absent has
     * to mean not read. Reading it the other way marks every chapter in the file read, and
     * does it while every other number in the same report stays correct.
     */
    public function test_a_chapter_with_no_read_flag_was_not_read(): void
    {
        $this->built = $this->buildTachibkBackup();

        $backup = $this->reader()->read($this->built);

        $naruto = $backup->series->firstWhere('title', 'Naruto');
        $byNumber = $naruto->chapters->keyBy('number');

        $this->assertTrue($byNumber[700.5]->isRead, 'a flagged chapter was read');
        $this->assertTrue($byNumber[699]->isRead, 'a flagged chapter was read');
        $this->assertFalse($byNumber[698]->isRead, 'an unflagged chapter was not read');
        $this->assertFalse($byNumber[686.5]->isRead, 'an unflagged chapter was not read');

        // The whole second shelf is unflagged, so the library total has to be the first shelf's.
        $short = $backup->series->firstWhere('title', 'A Short Shelf');

        $this->assertSame(3, $backup->readChapterCount(), 'only the flagged chapters count');
        $this->assertSame(0, $short->chapters->filter(fn ($c): bool => $c->isRead)->count());
    }

    /**
     * The second flag is a boolean on exactly the read chapters and nothing more.
     *
     * The only file this reader was written against cannot tell what it means, since every
     * read chapter in it also carries the flag. Importing it would put bookmarks on a shelf
     * nobody bookmarked, so it has to be left alone even though it is right there.
     *
     * The fixture makes this harder than the real file does. It drops the real read flag and
     * leaves the history list still naming the same chapters, so the two disagree and the
     * reader has to go on the field Mihon declares rather than whichever list it reaches
     * first. Reading this fixture as three read chapters would be a real mistake, and one a
     * reader could not see.
     */
    public function test_the_second_flag_is_not_imported_as_read_state_or_bookmarks(): void
    {
        $this->withSecondFlagOnly = true;
        $this->built = $this->buildTachibkBackup();

        $backup = $this->reader()->read($this->built);

        $this->assertSame(
            0,
            $backup->readChapterCount(),
            'a flag this reader cannot justify must not become read state',
        );
        $this->assertSame(0, $backup->summary()['chapters_bookmarked']);
        $this->assertSame(
            7,
            $backup->summary()['chapters'],
            'the chapters are all still there, only the state is refused',
        );

        $naruto = $backup->series->firstWhere('title', 'Naruto');

        $this->assertFalse(
            $naruto->chapters->contains(fn ($c): bool => $c->isRead || $c->isBookmarked),
            'no chapter should take any state from the unexplained field',
        );
    }

    /**
     * A listing position is the app's sort order, not the source's, and it outranks the
     * chapter number when the writer pairs a chapter.
     *
     * So this is not a style question. Mapping it would let a sort order override a chapter
     * number that is definitely right, and a source that inserts a chapter would move every
     * read flag after it onto the wrong chapter, with nothing on screen to show it.
     */
    public function test_a_listing_position_is_never_used_as_the_pairing_key(): void
    {
        $this->withPositionOnly = true;
        $this->built = $this->buildTachibkBackup();

        $naruto = $this->reader()->read($this->built)->series->firstWhere('title', 'Naruto');

        $this->assertSame(
            [],
            $naruto->chapters->filter(fn ($c): bool => $c->sourceOrder !== null)->map->sourceOrder->all(),
            'the positional index must not be carried across as a source order',
        );

        // With no number and no name either, there is nothing to pair on and it must say so.
        $this->assertSame(0, $naruto->chapters->filter(fn ($c): bool => $c->isPairable())->count());
    }

    public function test_a_source_id_past_2_53_arrives_unchanged(): void
    {
        $this->built = $this->buildTachibkBackup();

        $backup = $this->reader()->read($this->built);

        $this->assertGreaterThan(
            (int) (2 ** 53),
            $this->largeSourceId,
            'the fixture is only a test of this if its id is past what a double holds',
        );

        $naruto = $backup->series->firstWhere('title', 'Naruto');

        $this->assertSame($this->largeSourceId, $naruto->sourceId);

        $sources = $backup->sources->keyBy('sourceId');

        $this->assertTrue($sources->has($this->largeSourceId), 'the source list keeps the exact id');
        $this->assertSame('Atsumaru', $sources->get($this->largeSourceId)->name);
    }

    /**
     * Every timestamp in the file is within 91 seconds of when the backup was taken.
     *
     * So they are the exporter's clock and not the moments a reader added or read anything.
     * Importing them would put a real looking date on every series, all of it the day the
     * backup happened to be made.
     */
    public function test_the_exporters_own_timestamps_are_not_imported_as_dates(): void
    {
        $this->withExporterTimestamps = true;
        $this->built = $this->buildTachibkBackup();

        $naruto = $this->reader()->read($this->built)->series->firstWhere('title', 'Naruto');

        $this->assertNull($naruto->addedAt, 'the exporter run time is not when the reader added this');
        $this->assertNull($naruto->lastReadAt, 'the exporter run time is not when the reader read it');
    }

    /**
     * A newer writer adds field numbers this server has never heard of, and it must keep
     * reading the ones it does know rather than refusing a file it could mostly understand.
     */
    public function test_unknown_field_numbers_are_skipped_rather_than_failing(): void
    {
        $this->withUnknownFields = true;
        $this->built = $this->buildTachibkBackup();

        $backup = $this->reader()->read($this->built);

        $this->assertSame(2, $backup->series->count());
        $this->assertSame(7, $backup->summary()['chapters']);
        $this->assertSame('Naruto', $backup->series->first()->title);
    }

    /**
     * A series with nothing to show and nothing to match on is skipped, and still counted.
     *
     * Reporting only the survivors would claim the file never held it, which is the one thing
     * a reader comparing a count against their old app would catch.
     */
    public function test_a_series_that_can_neither_be_shown_nor_matched_is_skipped_but_still_counted(): void
    {
        $this->withUnnameableSeries = true;
        $this->built = $this->buildTachibkBackup();

        $backup = $this->reader()->read($this->built);
        $summary = $backup->summary();

        $this->assertSame(2, $summary['library_series'], 'the two real series still arrive');
        $this->assertSame(3, $summary['series_in_file'], 'and the file is reported as holding three');
    }

    /**
     * Parsing as protobuf is not the same as being this format.
     *
     * A file of compressed text is rejected because it will not parse, which is easy and
     * proves less than it looks. This one parses cleanly from end to end, carries entries in
     * exactly the field number a series goes in, and is still not a backup, because none of
     * them has a title. Without the title check a reader would claim it and then import
     * nothing from it, having told the reader their file was recognised.
     */
    public function test_a_backup_whose_series_have_no_titles_is_not_a_backup(): void
    {
        $this->withSeriesLackingTitles = true;
        $this->built = $this->buildTachibkBackup();

        $this->assertFalse($this->reader()->recognises($this->built), 'parses, and is still not this format');

        $this->expectException(ImportRefused::class);

        $this->reader()->read($this->built);
    }

    public function test_reading_something_that_is_not_a_backup_is_refused(): void
    {
        $this->built = $this->buildTachibkBackup();

        $this->expectException(ImportRefused::class);

        $this->reader()->read(__FILE__);
    }

    /**
     * A file cut in the middle of a field is refused whole.
     *
     * Not imported as far as it goes. Half a library is worse than none, because it looks
     * like a shelf and it is missing read state on everything it dropped.
     */
    public function test_a_backup_cut_short_is_refused(): void
    {
        $this->withCutPayload = true;
        $this->built = $this->buildTachibkBackup();

        $this->expectException(ImportRefused::class);

        $this->reader()->read($this->built);
    }

    public function test_a_gzip_that_is_not_protobuf_is_not_a_backup(): void
    {
        $this->gzipButNotProtobuf = true;
        $this->built = $this->buildTachibkBackup();

        $this->assertFalse($this->reader()->recognises($this->built));

        $this->expectException(ImportRefused::class);

        $this->reader()->read($this->built);
    }

    public function test_a_backup_with_a_broken_gzip_body_is_not_a_backup(): void
    {
        $this->withBrokenGzip = true;
        $this->built = $this->buildTachibkBackup();

        $this->assertFalse($this->reader()->recognises($this->built), 'decompression failing is a no');
    }

    /**
     * A number wider than a signed 64 bit integer is refused rather than rounded.
     *
     * Rounding it would turn a source id into a different number that matches no source at
     * all, and the import would report series it cannot place with nothing to explain why.
     */
    public function test_a_number_too_wide_to_hold_exactly_is_refused(): void
    {
        $this->withOversizedNumber = true;
        $this->built = $this->buildTachibkBackup();

        $this->expectException(ImportRefused::class);

        $this->reader()->read($this->built);
    }

    /**
     * A group wire type cannot appear in this format, so one means the file is something else.
     *
     * It is placed after a series the reader accepts, so this also covers the path where a
     * file is recognised and then refused on the full walk rather than half imported.
     */
    public function test_a_group_wire_type_is_refused_rather_than_half_read(): void
    {
        $this->withGroupWireType = true;
        $this->built = $this->buildTachibkBackup();

        $this->expectException(ImportRefused::class);

        $this->reader()->read($this->built);
    }

    /**
     * This format states no version anywhere, and the report has to say that.
     *
     * A 0 would be a number the file never wrote, printed as though this server had recognised
     * a version when it had recognised nothing at all.
     */
    public function test_the_summary_states_no_version_rather_than_inventing_one(): void
    {
        $this->built = $this->buildTachibkBackup();

        $this->assertNull($this->reader()->read($this->built)->summary()['schema_version']);
    }

    public function test_sources_carry_a_name_and_claim_nothing_the_file_does_not_say(): void
    {
        $this->built = $this->buildTachibkBackup();

        $backup = $this->reader()->read($this->built);

        $this->assertCount(2, $backup->sources, 'only the sources with series on them');

        foreach ($backup->sources as $source) {
            $this->assertNull(
                $source->package,
                'this format carries no package name, so none may be invented for an install',
            );
            $this->assertFalse(
                $source->obsolete,
                'nothing in the file says a source is gone, which is not the same as saying it is alive',
            );
            $this->assertNotNull($source->name);
        }

        // Largest shelf first, so an install reads top down.
        $this->assertSame(1, $backup->sources->first()->seriesCount);
    }

    public function test_without_a_source_list_the_series_still_arrive_unnamed(): void
    {
        $this->withoutSourceNames = true;
        $this->built = $this->buildTachibkBackup();

        $backup = $this->reader()->read($this->built);

        $this->assertSame(2, $backup->series->count(), 'a name is not needed to recognise a series');
        $this->assertCount(2, $backup->sources, 'and the sources are still listed for an install');
        $this->assertNull($backup->sources->first()->name);
    }

    /**
     * A decimal chapter number survives the fixed32 the wire format uses for it.
     *
     * Read as an integer it would lose every fractional chapter, and on a source that numbers
     * by volume that is a large part of the shelf pairing with nothing.
     */
    public function test_a_fractional_chapter_number_survives(): void
    {
        $this->built = $this->buildTachibkBackup();

        $naruto = $this->reader()->read($this->built)->series->firstWhere('title', 'Naruto');
        $numbers = $naruto->chapters->map(fn ($c): ?float => $c->number)->all();

        $this->assertContains(700.5, $numbers, 'a fixed32 read the wrong way round reads as zero');
        $this->assertContains(686.5, $numbers);
        $this->assertContains(699.0, $numbers);
    }
}
