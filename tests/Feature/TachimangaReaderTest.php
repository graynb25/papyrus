<?php

namespace Tests\Feature;

use App\Import\ImportedSeries;
use App\Import\ImportRefused;
use App\Import\Tachimanga\TachimangaReader;
use App\Import\Tachimanga\TachimangaSchema;
use App\Support\Suwayomi\Timestamps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsTachimangaBackups;
use Tests\TestCase;
use ZipArchive;

/**
 * Reading a Tachimanga backup, against a synthetic one shaped like the real 39MB file.
 *
 * ## What this reader is trusted with
 *
 * It is the only thing standing between a reader's phone library and a wrong library here,
 * and it runs before anything is validated. So the tests are mostly about refusals and
 * about the two ways this format lies:
 *
 * 1. **132,185 manga rows of which 1,277 are the library.** Everything else is browse
 *    cache. An import that forgets `in_library = 1` looks perfect against a fixture where
 *    every series is on the shelf, so the fixture deliberately is not.
 * 2. **Mixed timestamp units in one schema.** `Manga.in_library_at` is seconds,
 *    `Category.create_at` is milliseconds, and reading the first as the second gives
 *    1970-01-21, which does not look wrong on a page.
 *
 * ## The real file is checked elsewhere
 *
 * A 39MB file does not belong in a test suite. `.tools/suwayomi/probe-phase1.php` reads it
 * and cross-checks every count against the raw SQLite, and that is where the measured
 * figures in the docblocks came from.
 */
class TachimangaReaderTest extends TestCase
{
    use BuildsTachimangaBackups;
    use RefreshDatabase;

    private ?string $built = null;

    protected function tearDown(): void
    {
        if ($this->built !== null && is_file($this->built)) {
            @unlink($this->built);
        }

        $this->built = null;

        parent::tearDown();
    }

    private function reader(): TachimangaReader
    {
        return new TachimangaReader(new Timestamps);
    }

    public function test_it_recognises_a_backup_and_refuses_everything_else(): void
    {
        $this->built = $this->buildTachimangaBackup();
        $reader = $this->reader();

        $this->assertTrue($reader->recognises($this->built), 'a real backup should be recognised');

        // A CBZ export this server writes is also a zip. The magic bytes alone would claim it.
        $cbz = tempnam(sys_get_temp_dir(), 'papyrus-cbz-');
        $zip = new ZipArchive;
        $zip->open($cbz, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('001.jpg', 'not really a jpeg');
        $zip->close();

        $this->assertFalse($reader->recognises($cbz), 'a plain zip is not a Tachimanga backup');
        $this->assertFalse($reader->recognises(__FILE__), 'a php file is not a backup');
        $this->assertFalse($reader->recognises(sys_get_temp_dir().'/does-not-exist.tmb'));
        $this->assertFalse($reader->recognises(sys_get_temp_dir()));

        @unlink($cbz);
    }

    public function test_reading_a_non_backup_is_refused_rather_than_returning_nothing(): void
    {
        $junk = tempnam(sys_get_temp_dir(), 'papyrus-junk-');
        file_put_contents($junk, 'this is not an archive of any kind');

        try {
            $this->reader()->read($junk);
            $this->fail('a file that is not a backup must be refused, not read as empty');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString('not a Tachimanga backup', $e->getMessage());
        } finally {
            @unlink($junk);
        }
    }

    public function test_it_reads_only_the_shelf_and_not_the_browse_cache(): void
    {
        $this->built = $this->buildTachimangaBackup();
        $backup = $this->reader()->read($this->built);

        // The fixture holds 4 manga of which 2 are in_library. Reading all 4 would be the
        // single most damaging thing this reader could do.
        $this->assertCount(2, $backup->series, 'only in_library = 1 rows belong to a library');
        $this->assertSame(4, $backup->totalInFile, 'but the file total is reported so the reader sees the gap');

        $titles = $backup->series->pluck('title')->all();
        $this->assertContains('Hiding a Logistics Center', $titles);
        $this->assertContains('The Berserker\'s Second Playthrough', $titles);
        $this->assertNotContains('Browse Cache One', $titles);
        $this->assertNotContains('Browse Cache Two', $titles);
    }

    public function test_it_reports_the_counts_a_reader_would_ask_about(): void
    {
        $this->built = $this->buildTachimangaBackup();
        $summary = $this->reader()->read($this->built)->summary();

        $this->assertSame('tachimanga', $summary['format']);
        $this->assertSame(TachimangaSchema::KNOWN_VERSION, $summary['schema_version']);
        $this->assertSame(2, $summary['library_series']);
        $this->assertSame(4, $summary['series_in_file']);
        $this->assertSame(4, $summary['chapters']);
        $this->assertSame(3, $summary['chapters_read']);
        $this->assertSame(1, $summary['chapters_bookmarked']);
        $this->assertSame(1, $summary['chapters_downloaded']);
        $this->assertSame(1, $summary['series_with_resume'], 'the second shelf series has no History row');
        $this->assertSame(2, $summary['categories']);
    }

    /**
     * The mixed-unit trap. `in_library_at` is seconds; read as milliseconds every date on
     * the shelf becomes 1970, and a reader has no reason to suspect a date.
     */
    public function test_a_seconds_timestamp_is_not_read_as_milliseconds(): void
    {
        $this->built = $this->buildTachimangaBackup();
        $series = $this->reader()->read($this->built)->series->first();

        $this->assertNotNull($series->addedAt);
        $this->assertSame(2025, $series->addedAt->year, '1758301032 is 2025 in seconds, 1970 in milliseconds');
        $this->assertGreaterThan(2000, $series->addedAt->year);

        // And the resume date, which also arrives in seconds.
        $this->assertNotNull($series->lastReadAt);
        $this->assertGreaterThan(2000, $series->lastReadAt->year);
    }

    /**
     * A date that reads before 2000 is the signature of a unit mistake, so it is refused
     * rather than written. A date 56 years early does not look wrong on a page.
     */
    public function test_a_date_before_2000_is_refused(): void
    {
        $this->timestamps['in_library_at'] = 1000; // 1970-01-01 in seconds
        $this->built = $this->buildTachimangaBackup();

        try {
            $this->reader()->read($this->built);
            $this->fail('a 1970 date must be refused, not imported');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString('before any of', $e->getMessage());
            $this->assertStringContainsString('Nothing has been changed', $e->getMessage());
        }
    }

    /**
     * The two apps disagree about the trailing slash, and a join that finds nothing
     * because of it is indistinguishable from a join with nothing to find.
     */
    public function test_urls_are_normalised_so_both_apps_agree(): void
    {
        $this->assertSame('/comics/x', ImportedSeries::normaliseUrl('/comics/x'));
        $this->assertSame('/comics/x', ImportedSeries::normaliseUrl('/comics/x/'));
        $this->assertSame('/comics/x', ImportedSeries::normaliseUrl('comics/x'));
        $this->assertSame('/comics/x', ImportedSeries::normaliseUrl('comics/x/'));
        $this->assertSame('/comics/x', ImportedSeries::normaliseUrl('/comics/x?utm=1'));
        $this->assertSame('/comics/x', ImportedSeries::normaliseUrl('/comics/x#chapter-3'));
        $this->assertSame('/', ImportedSeries::normaliseUrl(''));
        $this->assertSame('/', ImportedSeries::normaliseUrl('/'));

        $this->built = $this->buildTachimangaBackup();
        $series = $this->reader()->read($this->built)->series->keyBy('title');

        /*
         * Both shelf rows, not just one. The assertion has to discriminate: with only
         * unslashed rows surviving the filter, dropping `normaliseUrl()` changes nothing
         * observable and the check passes either way. One of these two is stored with a
         * trailing slash precisely so that cannot happen.
         */
        $this->assertSame(
            '/comics/hiding-a-logistics-center-in-the-apo',
            $series['Hiding a Logistics Center']->url,
            'a trailing slash is stripped so the key matches how Suwayomi stores it'
        );
        $this->assertSame(
            '/comics/the-berserkers-second-playthrough',
            $series['The Berserker\'s Second Playthrough']->url,
            'and a row with no trailing slash is left in the same form'
        );

        foreach ($series as $title => $one) {
            $this->assertStringNotContainsString('//', $one->url, "{$title} has a doubled slash");
            $this->assertStringEndsNotWith('/', $one->url, "{$title} kept its trailing slash");
        }
    }

    /**
     * Source ids exceed 2^53. They have to survive as exact integers, and they must never
     * be JSON-encoded as numbers, which is why the summary omits them.
     */
    public function test_a_source_id_over_2_to_the_53_survives_exactly(): void
    {
        $this->built = $this->buildTachimangaBackup();
        $backup = $this->reader()->read($this->built);
        $series = $backup->series->first();

        $this->assertSame(2_848_420_679_472_350_044, $series->sourceId);
        $this->assertGreaterThan(2 ** 53, $series->sourceId);

        // The identity key is what the resolve step matches on, so it must carry it exactly.
        $this->assertStringContainsString('2848420679472350044', $series->identityKey());

        // And the summary is what a command prints or a page renders.
        $this->assertArrayNotHasKey('source_id', $backup->summary());
    }

    public function test_chapters_carry_the_state_a_reader_would_miss(): void
    {
        $this->built = $this->buildTachimangaBackup();
        $series = $this->reader()->read($this->built)->series->first();

        $this->assertCount(3, $series->chapters);
        $this->assertCount(2, $series->readChapters());
        $this->assertCount(1, $series->chapters->filter(fn ($c) => $c->isBookmarked));
        $this->assertCount(1, $series->chapters->filter(fn ($c) => $c->isDownloaded));

        // An empty chapter name is null, not "", or it would count as a pairing key.
        $unnamed = $series->chapters->firstWhere('name', null);
        $this->assertNotNull($unnamed, 'a chapter with no name of its own reads as null');
        $this->assertSame('', (string) $unnamed->name, 'the fixture stores NULL, so the reader must not invent a title');
    }

    public function test_the_resume_pointer_is_a_local_id_and_names_no_chapter_here(): void
    {
        $this->built = $this->buildTachimangaBackup();
        $series = $this->reader()->read($this->built)->series->first();

        // Carried, but meaningless here: 430617 is this backup's own numbering.
        $this->assertSame(430_617, $series->backupResumeChapterId);

        $unmatchable = $series->chapters->firstWhere('backupId', 430_617);
        $this->assertNull($unmatchable, 'no chapter carries that id, so the remap will have to report it');
    }

    public function test_categories_are_reported_as_free_text_labels(): void
    {
        $this->built = $this->buildTachimangaBackup();
        $categories = $this->reader()->read($this->built)->categories;

        $this->assertCount(2, $categories);
        $this->assertSame(1, $categories->get('Youtube '), 'the trailing space is part of what was typed');
        $this->assertSame(0, $categories->get('Snowflakes'), 'an empty category still counts as a category');
    }

    public function test_a_version_this_server_does_not_know_is_refused(): void
    {
        $this->version = 4;
        $this->built = $this->buildTachimangaBackup();

        try {
            $this->reader()->read($this->built);
            $this->fail('an unknown older version must be refused');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString('version 4', $e->getMessage());
        }

        @unlink($this->built);

        $this->version = 9999;
        $this->built = $this->buildTachimangaBackup();

        try {
            $this->reader()->read($this->built);
            $this->fail('a version newer than any this server knows must be refused');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString('newer than this server understands', $e->getMessage());
        }
    }

    public function test_a_truncated_archive_is_refused(): void
    {
        $this->withoutContents = true;
        $this->built = $this->buildTachimangaBackup();

        // recognise() is false because contents.zip is gone, which is the honest answer.
        $this->assertFalse($this->reader()->recognises($this->built));

        try {
            $this->reader()->read($this->built);
            $this->fail('an archive with no contents must be refused');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString('not a Tachimanga backup', $e->getMessage());
        }
    }

    public function test_an_archive_with_no_database_is_refused(): void
    {
        $this->withoutDatabase = true;
        $this->built = $this->buildTachimangaBackup();

        try {
            $this->reader()->read($this->built);
            $this->fail('an archive with no library database must be refused');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString('no library database', $e->getMessage());
        }
    }

    /**
     * SQLite's message for a missing column is `no such table: <the column>`, which names a
     * table that exists. The reader has to say something a reader could act on instead.
     */
    public function test_a_missing_table_is_named_rather_than_crashing(): void
    {
        $this->withoutHistoryTable = true;
        $this->built = $this->buildTachimangaBackup();

        try {
            $this->reader()->read($this->built);
            $this->fail('a missing table must be refused');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString(TachimangaSchema::HISTORY_TABLE, $e->getMessage());
            $this->assertStringContainsString('different version of the app', $e->getMessage());
        }
    }

    public function test_a_missing_column_is_named_rather_than_crashing(): void
    {
        $this->withoutSourceOrder = true;
        $this->built = $this->buildTachimangaBackup();

        try {
            $this->reader()->read($this->built);
            $this->fail('a missing column must be refused');
        } catch (ImportRefused $e) {
            $this->assertStringContainsString(
                TachimangaSchema::CHAPTER_TABLE.'.'.TachimangaSchema::CHAPTER_SOURCE_ORDER_COLUMN,
                $e->getMessage(),
                'the refusal must name the missing column, since SQLite would name a table that exists'
            );
        }
    }

    /**
     * The reader stages a 41MB archive and a 113MB database to temp files. Three refused
     * attempts must not leave 150MB behind on each one.
     */
    public function test_temp_files_are_cleaned_up_on_success_and_on_refusal(): void
    {
        $before = $this->stagedFiles();

        $this->built = $this->buildTachimangaBackup();
        $this->reader()->read($this->built);
        $this->assertSame($before, $this->stagedFiles(), 'a successful read must not stage anything');

        $this->timestamps['in_library_at'] = 1000;
        $this->built = $this->buildTachimangaBackup();

        try {
            $this->reader()->read($this->built);
        } catch (ImportRefused) {
            // Expected. The point is the cleanup that follows.
        }

        $this->assertSame($before, $this->stagedFiles(), 'a refused read must clean up after itself');
    }

    public function test_reading_a_backup_writes_nothing_to_this_application(): void
    {
        $count = fn (string $table): int => DB::table($table)->count();

        // The three tables a library import would eventually write to. Phase 1 touches none
        // of them, and the point of this test is that it stays that way until phase 3.
        $tables = ['series_states', 'chapter_states', 'categories', 'category_series'];

        $before = array_combine($tables, array_map($count, $tables));

        $this->built = $this->buildTachimangaBackup();
        $this->reader()->read($this->built);

        $this->assertSame(
            $before,
            array_combine($tables, array_map($count, $tables)),
            'phase 1 describes a library and must not touch one'
        );
    }

    /** @return list<string> */
    private function stagedFiles(): array
    {
        return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-import-*') ?: [];
    }
}
