<?php

namespace Tests\Feature;

use App\Import\Tachibk\MalformedProto;
use App\Import\Tachibk\ProtoBuffer;
use App\Import\Tachibk\TachibkFile;
use App\Import\Tachibk\TachibkSchema;
use App\Import\Tachibk\TachibkStream;
use Tests\Support\BuildsTachibkBackups;
use Tests\TestCase;

/**
 * The buffered walk a large `.tachibk` is read through.
 *
 * ## Why this class exists rather than only reader tests
 *
 * The reader tests prove the library comes out right. They do not prove the file was
 * walked without becoming memory, and that is the change a 256MB upload ceiling made
 * necessary. A field the caller does not ask for has to be discarded from the gzip
 * stream, not decoded into a string, or a backup the server now accepts can still
 * exhaust the process that reads it.
 */
class TachibkStreamTest extends TestCase
{
    use BuildsTachibkBackups;

    public function test_it_walks_only_the_fields_the_caller_asked_for(): void
    {
        $this->withCategories = true;
        $stream = TachibkStream::open($this->buildTachibkBackup());

        $this->assertNotNull($stream, 'the fixture is a gzip backup');

        $numbers = [];
        $titles = [];

        try {
            $wanted = [TachibkSchema::BACKUP_SERIES, TachibkSchema::BACKUP_CATEGORIES];

            foreach ($stream->fields($wanted) as $number => [$wire, $value]) {
                $numbers[] = $number;

                if ($number === TachibkSchema::BACKUP_SERIES && $wire === ProtoBuffer::WIRE_BYTES) {
                    $titles[] = ProtoBuffer::text($value, TachibkSchema::SERIES_TITLE);
                }
            }
        } finally {
            $stream->close();
        }

        $this->assertSame(
            [TachibkSchema::BACKUP_SERIES, TachibkSchema::BACKUP_SERIES, TachibkSchema::BACKUP_CATEGORIES],
            $numbers,
            'the walk returns the requested fields in file order and nothing else',
        );
        $this->assertSame(['Naruto', 'A Short Shelf'], $titles);
    }

    public function test_it_skips_a_large_unwanted_field_without_allocating_it(): void
    {
        $this->largeStateFieldBytes = 8 * 1024 * 1024;
        $path = $this->buildTachibkBackup();

        /*
         * Build the fixture first, then reset the peak. The builder necessarily holds the
         * payload while it writes it, and that allocation is not what this test is about.
         */
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $stream = TachibkStream::open($path);
        $this->assertNotNull($stream);

        $titles = [];

        try {
            foreach ($stream->fields([TachibkSchema::BACKUP_SERIES]) as [$wire, $value]) {
                if ($wire === ProtoBuffer::WIRE_BYTES) {
                    $titles[] = ProtoBuffer::text($value, TachibkSchema::SERIES_TITLE);
                }
            }
        } finally {
            $stream->close();
        }

        $peak = memory_get_peak_usage();

        $this->assertSame(['Naruto', 'A Short Shelf'], $titles);
        $this->assertLessThan(
            4 * 1024 * 1024,
            $peak - $before,
            'an 8MB field the walk was not asked for must be discarded a buffer at a time, '
            .'not decoded into an 8MB string',
        );
    }

    /**
     * A length is read before its value, so this is the only place a cap can stop an
     * allocation rather than measure one after it happened. The check is against the
     * declared length, which is why a field that claims far more than the payload holds
     * is refused as too large rather than as cut short.
     */
    public function test_a_field_longer_than_the_cap_is_refused_before_it_is_read(): void
    {
        config(['papyrus.import.max_field_bytes' => 16]);

        $stream = TachibkStream::open($this->buildTachibkBackup());
        $this->assertNotNull($stream);

        try {
            foreach ($stream->fields([TachibkSchema::BACKUP_SERIES]) as $field) {
                $this->fail('a field over the cap should not have produced a value');
            }

            $this->fail('the walk should have refused the oversized field');
        } catch (MalformedProto $e) {
            $this->assertStringContainsString(
                'larger than the 16 B this server reads in a single field',
                $e->getMessage(),
            );
            $this->assertStringContainsString('Nothing has been changed', $e->getMessage());
        } finally {
            $stream->close();
        }
    }

    public function test_a_field_that_claims_more_than_the_cap_is_refused_before_allocating(): void
    {
        config(['papyrus.import.max_field_bytes' => 16]);

        $this->declaredSeriesBytes = 64 * 1024 * 1024;

        $stream = TachibkStream::open($this->buildTachibkBackup());
        $this->assertNotNull($stream);

        try {
            foreach ($stream->fields([TachibkSchema::BACKUP_SERIES]) as $field) {
                $this->fail('a field that claims 64MB should not have produced a value');
            }

            $this->fail('the walk should have refused the declared length');
        } catch (MalformedProto $e) {
            $this->assertStringContainsString(
                'larger than the 16 B this server reads in a single field',
                $e->getMessage(),
                'the cap has to be checked against the declared length, not after reading it',
            );
        } finally {
            $stream->close();
        }
    }

    public function test_a_payload_that_stops_mid_field_is_refused(): void
    {
        $this->withCutPayload = true;
        $stream = TachibkStream::open($this->buildTachibkBackup());

        $this->assertNotNull($stream);

        try {
            foreach ($stream->fields([TachibkSchema::BACKUP_SERIES]) as $field) {
                $this->fail('the truncated field should not have produced a value');
            }

            $this->fail('the walk should have reported the cut rather than reaching a clean end');
        } catch (MalformedProto $e) {
            $this->assertStringContainsString('stops in the middle of a field', $e->getMessage());
        } finally {
            $stream->close();
        }
    }

    public function test_a_file_that_is_not_gzip_is_not_opened_as_a_backup(): void
    {
        $path = $this->temporaryFile('papyrus-not-gzip-');
        file_put_contents($path, 'a plain file that starts with no gzip magic');

        $this->assertNull(TachibkStream::open($path));
    }

    public function test_a_broken_gzip_payload_is_refused_rather_than_returned_empty(): void
    {
        $this->withBrokenGzip = true;
        $path = $this->buildTachibkBackup();

        $this->assertFalse(TachibkStream::payload($path));
    }

    public function test_payload_and_write_round_trip_every_field(): void
    {
        $this->withUnknownFields = true;
        $source = $this->buildTachibkBackup();

        $payload = TachibkStream::payload($source);
        $this->assertIsString($payload, 'the fixture is a decodable gzip');

        $sink = $this->temporaryFile('papyrus-stream-round-trip-', 'tachibk');
        $this->assertTrue(TachibkStream::write($sink, $payload));

        $this->assertSame($payload, TachibkStream::payload($sink));
        $this->assertSame(
            2,
            TachibkFile::describe($sink)['series'],
            'the written file still walks as the backup it came from',
        );
    }

    public function test_write_reports_a_path_it_cannot_open(): void
    {
        $missing = $this->scratchPath('papyrus-missing-directory').DIRECTORY_SEPARATOR
            .'not-created'.DIRECTORY_SEPARATOR.'no-such-file.tachibk';

        $this->assertFalse(TachibkStream::write($missing, 'payload'));
    }
}
