<?php

namespace Tests\Feature;

use App\Import\Tachibk\TachibkFile;
use Tests\Support\BuildsTachibkBackups;
use Tests\TestCase;

/**
 * What a `.tachibk` holds, read from its own top level fields.
 *
 * ## Why this class is measured against a real server's empty library
 *
 * A server whose own library holds nothing still writes a real backup. Measured against
 * a live v2.4.2366 with an empty library: 352 bytes on disk, gzip magic `1f8b0800`,
 * and a payload holding exactly one category record (field 2, name "Default", no
 * order) and one Suwayomi-only field 9001, and **zero** field 1 records.
 *
 * `TachibkReader::recognises()` refuses that file, and it is right to: its gate is
 * narrow on purpose, so a CBZ is never read as a backup. But the refusal's words
 * ("not a Mihon or Suwayomi backup") are true of the bytes and wrong about the cause,
 * because the server answered with a genuine backup of an empty library.
 *
 * So this class reads the top level fields and reports what the file is, and the
 * fixture here is the measured shape rather than a guess at it.
 */
class TachibkFileTest extends TestCase
{
    use BuildsTachibkBackups;

    public function test_it_reads_the_counts_out_of_a_real_backups_own_fields(): void
    {
        $path = $this->buildTachibkBackup();

        $described = TachibkFile::describe($path);

        $this->assertSame(2, $described['series'], 'the fixture carries two series');
        $this->assertSame(2, $described['sources'], 'the fixture carries two sources');
        $this->assertTrue($described['gzip'], 'the fixture is gzip');
        $this->assertGreaterThan(0, $described['bytes']);
    }

    public function test_it_reads_a_category_record_when_the_file_carries_one(): void
    {
        $this->withCategories = true;

        $described = TachibkFile::describe($this->buildTachibkBackup());

        $this->assertSame(1, $described['categories']);
    }

    /**
     * The measured shape: a server's own backup of a library that holds nothing.
     *
     * The file is gzip, it carries the server's default shelf, and it carries no series
     * at all. This is what the standing seat's own export looks like, byte for byte in
     * shape, and the refusal the app writes has to name it rather than calling the
     * server's answer broken.
     */
    public function test_it_names_a_backup_of_an_empty_library(): void
    {
        $this->withoutSeries = true;
        $this->withCategories = true;

        $path = $this->buildTachibkBackup();

        $this->assertTrue(
            TachibkFile::isAnEmptyLibraryBackup($path),
            'a backup with shelves and no series is a server whose library holds nothing',
        );
    }

    /**
     * A file with series in it is not an empty library, whatever else it is.
     */
    public function test_a_backup_with_series_in_it_is_not_an_empty_library(): void
    {
        $this->withCategories = true;

        $this->assertFalse(TachibkFile::isAnEmptyLibraryBackup($this->buildTachibkBackup()));
    }

    /**
     * A gzip with no shelf record in it is not the measured shape either.
     *
     * The empty library shape is defined by the server's own default shelf being
     * present, so a gzip that carries neither series nor shelves is something else
     * entirely, and the caller must say what it is instead of guessing.
     */
    public function test_a_gzip_with_neither_series_nor_shelves_is_not_an_empty_library(): void
    {
        $this->withoutSeries = true;
        $this->withoutSourceNames = true;

        $this->assertFalse(TachibkFile::isAnEmptyLibraryBackup($this->buildTachibkBackup()));
    }

    /**
     * Something that is not gzip at all is not a backup, and is not read as one.
     */
    public function test_a_file_that_is_not_gzip_is_not_described_as_a_backup(): void
    {
        $path = $this->scratchPath('not-gzip').DIRECTORY_SEPARATOR.'plain.bin';
        file_put_contents($path, 'this is not a backup at all');

        $described = TachibkFile::describe($path);

        $this->assertFalse($described['gzip']);
        $this->assertSame(0, $described['series']);
        $this->assertFalse(TachibkFile::isAnEmptyLibraryBackup($path));
    }

    /**
     * A file that is not there is a zero reading, not a crash.
     */
    public function test_a_file_that_is_not_on_disk_reads_as_nothing(): void
    {
        $described = TachibkFile::describe($this->scratchPath('absent').DIRECTORY_SEPARATOR.'missing.tachibk');

        $this->assertSame(0, $described['series']);
        $this->assertSame(0, $described['bytes']);
        $this->assertFalse($described['gzip']);
    }

    /**
     * A gzip whose body does not walk as protobuf does not throw its way out.
     */
    public function test_a_gzip_whose_body_is_not_protobuf_reads_as_nothing(): void
    {
        $this->gzipButNotProtobuf = true;

        $described = TachibkFile::describe($this->buildTachibkBackup());

        $this->assertTrue($described['gzip'], 'the magic is still there');
        $this->assertSame(0, $described['series']);
    }
}
