<?php

namespace Tests\Feature;

use App\Downloads\CbzWriter;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * The CBZ writer, checked against an implementation that is not this one.
 *
 * ## Why this test exists at all
 *
 * {@see CbzWriter} hand-rolls a binary format, which is exactly the kind of code that can be
 * subtly wrong in a way nothing notices: a bad CRC or a mis-sized descriptor produces an
 * archive that some readers open and others reject, and the failure lands on a user's machine
 * rather than in CI. So every archive here is written by the class under test and read back
 * with `ZipArchive`, which knows nothing about how it was produced. That is the independent
 * check, and without it this class is only a claim.
 *
 * ## Why reading it back is the whole test
 *
 * There is no way to assert "the offsets are right" without a reader that follows them. So
 * these tests assert the properties a reader depends on (the names, the byte-for-byte
 * contents, and the CRCs) rather than the bytes of the headers, which would be asserting the
 * implementation back at itself.
 */
class CbzWriterTest extends TestCase
{
    /**
     * Write an archive from name => bytes and return the raw zip.
     *
     * @param  array<string, string>  $entries
     */
    private function archive(array $entries, bool $chunked = false): string
    {
        $stream = fopen('php://temp', 'r+b');

        $writer = new CbzWriter($stream);

        foreach ($entries as $name => $bytes) {
            if ($chunked) {
                // Split so the writer's per-chunk path is exercised rather than the
                // single-write fast path, which is all a naive test would ever hit.
                $writer->add($name, function () use ($bytes): iterable {
                    foreach (str_split($bytes, 7) ?: [''] as $piece) {
                        yield $piece;
                    }
                });
            } else {
                $writer->add($name, static fn (): iterable => [$bytes]);
            }
        }

        $writer->close();

        rewind($stream);
        $raw = (string) stream_get_contents($stream);
        fclose($stream);

        return $raw;
    }

    /**
     * Open an archive with an independent reader.
     *
     * The `finally` in the first version of this file called `$zip->close()` unconditionally,
     * and closing an archive that failed to open throws "Invalid or uninitialized Zip
     * object" from inside the `finally`, which replaces the assertion failure that was the
     * real message. Two genuine bugs in the writer were reported as that one line for as long
     * as it took to notice: a signature written as four zero bytes and a CRC that was its own
     * one's complement. Both were invisible, because the test threw before it could say what
     * it had actually seen.
     *
     * So the open code is returned rather than asserted inside, and a failed open is a plain
     * failure with the error code in the message, which is the number `ZipArchive` gives and
     * the only thing that says why.
     */
    private function open(string $raw): ZipArchive
    {
        $file = $this->temporaryFile('cbz');

        file_put_contents($file, $raw);

        $zip = new ZipArchive;
        $code = $zip->open($file);

        // The temp file is removed once the archive is open, because ZipArchive reads lazily
        // on some builds and a reader that cannot find its file reports a corrupt archive
        // rather than a missing one.
        if ($code !== true) {
            @unlink($file);

            $this->fail('ZipArchive refused the archive with error '.$code
                .' ('.self::zipError($code).').');
        }

        $this->scratchFiles[] = $file;

        return $zip;
    }

    /**
     * @var array<int, string>
     */
    private array $scratchFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchFiles as $file) {
            @unlink($file);
        }

        $this->scratchFiles = [];

        parent::tearDown();
    }

    private static function zipError(int $code): string
    {
        return match ($code) {
            ZipArchive::ER_NOENT => 'no such file',
            ZipArchive::ER_NOZIP => 'not a zip file',
            ZipArchive::ER_INCONS => 'inconsistent archive',
            ZipArchive::ER_CRC => 'CRC error',
            ZipArchive::ER_TRUNCATED => 'truncated',
            ZipArchive::ER_READ => 'read error',
            default => 'unrecognised',
        };
    }

    public function test_a_single_entry_survives_the_round_trip(): void
    {
        $zip = $this->open($this->archive(['001.jpg' => 'the first page']));

        $this->assertSame(1, $zip->numFiles);
        $this->assertSame('the first page', $zip->getFromName('001.jpg'));
        $this->assertTrue($zip->locateName('001.jpg') !== false);

        $zip->close();
    }

    public function test_every_entry_and_its_exact_bytes_survive(): void
    {
        // Sizes chosen to straddle buffer boundaries, because a writer that mishandles a
        // partial chunk looks fine on small input and breaks on real pages.
        $entries = [
            '001.jpg' => str_repeat('a', 1),
            '002.jpg' => str_repeat('b', 1023),
            '003.jpg' => str_repeat('c', 1024),
            '004.jpg' => str_repeat('d', 65537),
            '005.webp' => str_repeat('e', 250_000),
        ];

        $zip = $this->open($this->archive($entries, chunked: true));

        $this->assertSame(count($entries), $zip->numFiles);

        foreach ($entries as $name => $bytes) {
            $this->assertSame(
                $bytes,
                $zip->getFromName($name),
                "Entry {$name} came back with different bytes."
            );
        }

        $zip->close();
    }

    public function test_an_empty_entry_is_still_an_entry(): void
    {
        // A zero-byte page is odd but legal, and a writer that treats zero bytes as "no entry"
        // silently renumbers everything after it.
        $zip = $this->open($this->archive(['001.jpg' => '', '002.jpg' => 'after the empty one']));

        $this->assertSame(2, $zip->numFiles);
        $this->assertSame('', $zip->getFromName('001.jpg'));
        $this->assertSame('after the empty one', $zip->getFromName('002.jpg'));

        $zip->close();
    }

    public function test_the_archive_carries_no_directory_entries(): void
    {
        $zip = $this->open($this->archive(['001.jpg' => 'x', '002.jpg' => 'y']));

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            $this->assertIsArray($stat);
            $this->assertStringEndsWith('.jpg', (string) $stat['name']);
        }

        $zip->close();
    }

    public function test_a_name_with_a_separator_is_flattened_rather_than_nesting_the_entry(): void
    {
        $zip = $this->open($this->archive(['nested/001.jpg' => 'x']));

        $this->assertSame('nested-001.jpg', $zip->getNameIndex(0));

        $zip->close();
    }

    /**
     * The checksum is compared against the built-in, which is the only claim that matters.
     *
     * {@see CbzWriter} cannot use `crc32()` directly: PHP 8 removed the two-argument
     * incremental form, so a chunk-by-chunk stream has to fold its own. That is a hand-rolled
     * checksum, and the failure it produces is an archive whose CRC disagrees with its bytes,
     * which readers report as corrupt and which is indistinguishable from a truncated
     * download. So the running value is read out of the finished archive and compared with
     * `crc32()` of the same bytes, at several chunk boundaries.
     */
    public function test_the_running_crc_equals_the_builtin_checksum(): void
    {
        $payload = str_repeat('page bytes ', 5000);

        foreach ([1, 2, 3, 7, 999, 4096] as $chunkSize) {
            $stream = fopen('php://temp', 'r+b');
            $writer = new CbzWriter($stream);

            $writer->add('001.jpg', function () use ($payload, $chunkSize): iterable {
                foreach (str_split($payload, $chunkSize) as $piece) {
                    yield $piece;
                }
            });

            $writer->close();

            rewind($stream);
            $raw = (string) stream_get_contents($stream);
            fclose($stream);

            $zip = $this->open($raw);
            $stat = $zip->statIndex(0);

            $this->assertIsArray($stat);
            $this->assertSame(
                crc32($payload),
                (int) $stat['crc'],
                "The CRC written for a {$chunkSize}-byte chunking does not match crc32()."
            );

            // And the reader agrees, which is the check that would fail if the CRC were wrong
            // even though the bytes were right.
            $this->assertSame($payload, $zip->getFromIndex(0));

            $zip->close();
        }
    }

    public function test_closing_twice_is_refused(): void
    {
        $stream = fopen('php://temp', 'r+b');
        $writer = new CbzWriter($stream);
        $writer->add('001.jpg', static fn (): iterable => ['x']);
        $writer->close();

        $this->expectException(RuntimeException::class);

        $writer->close();
    }

    public function test_adding_after_closing_is_refused(): void
    {
        $stream = fopen('php://temp', 'r+b');
        $writer = new CbzWriter($stream);
        $writer->close();

        $this->expectException(RuntimeException::class);

        // An entry appended after the central directory would be invisible to every reader,
        // which is a silent failure rather than a loud one.
        $writer->add('002.jpg', static fn (): iterable => ['x']);
    }

    public function test_a_non_stream_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        // A string is the obvious wrong argument and it fails at construction rather than on
        // the first fwrite, which is where it would otherwise surface as a TypeError.
        new CbzWriter('/tmp/not-a-stream');
    }

    public function test_the_running_total_is_what_the_size_guard_reads(): void
    {
        $stream = fopen('php://temp', 'r+b');
        $writer = new CbzWriter($stream);

        $this->assertSame(0, $writer->bytesWritten());
        $this->assertSame(0, $writer->entryCount());

        $writer->add('001.jpg', static fn (): iterable => [str_repeat('a', 1000)]);

        // Strictly greater, because the headers and the descriptor are not page bytes and a
        // guard reading this total must count them.
        $this->assertGreaterThan(1000, $writer->bytesWritten());
        $this->assertSame(1, $writer->entryCount());

        $writer->close();
    }

    public function test_page_names_sort_into_reading_order(): void
    {
        // The reason the padding exists. Sorted as strings with no padding, page 10 lands
        // between 1 and 2, and a comic reader opens the archive on page 10.
        $names = [];

        foreach ([1, 2, 3, 9, 10, 11, 100, 101] as $index) {
            $names[] = CbzWriter::pageName($index, 'jpg');
        }

        $sorted = $names;
        sort($sorted, SORT_STRING);

        $this->assertSame($names, $sorted, 'Page names do not sort into reading order.');
        $this->assertSame('001.jpg', CbzWriter::pageName(1, 'jpg'));
        $this->assertSame('100.jpg', CbzWriter::pageName(100, 'jpg'));
        $this->assertSame('101.webp', CbzWriter::pageName(101, 'webp'));
    }
}
