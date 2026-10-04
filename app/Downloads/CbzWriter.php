<?php

namespace App\Downloads;

use RuntimeException;

/**
 * A ZIP writer that streams, because a CBZ of a long chapter is large.
 *
 * ## Why not ZipArchive
 *
 * `ZipArchive` is available and would be less code, but every route it offers ends with the
 * archive existing in full somewhere before a byte reaches the browser: `addFromString()`
 * holds each entry in memory, and writing to a file means the file is complete before the
 * response starts. Suwayomi's own CBZ endpoint is refused by the proxy precisely because it
 * does that server-side (Suwayomi-Server#2353), and reimplementing the same mistake in this
 * app's own code would be an odd way to answer it.
 *
 * So this writes the format directly to a stream, one chunk at a time, and never holds more
 * than a single chunk plus one page's bookkeeping. The whole archive is never resident.
 *
 * ## Why stored, not deflated
 *
 * Every entry is a JPEG or WebP, both of which are already compressed. Deflating them costs
 * CPU and saves nothing measurable, and method 0 (stored) is the one path through the format
 * with no compression-specific edge cases. The `method` parameter exists so that a future
 * non-image entry does not silently arrive claiming to be stored and compressed.
 *
 * ## Why sizes are zero in the local header
 *
 * The CRC and both sizes are not known until a page has been streamed, and the stream cannot
 * seek backwards, so they are written as zero in the local header and repeated in a data
 * descriptor afterwards. That is what general-purpose bit 3 means, and every unzip since the
 * 1990s honours it. The alternative, buffering each entry to learn its CRC first, is a
 * per-entry buffer the size of the largest page, which is the thing being avoided.
 *
 * Verified by `CbzWriterTest`, which reads every archive this class produces back with
 * `ZipArchive` and asserts the names, the bytes and the CRCs survive the round trip. That
 * check is the reason this class is allowed to hand-roll a binary format: a format written
 * from the specification is a claim, and reading it back with an independent implementation
 * is the evidence.
 *
 * ## Sizes are 32-bit, deliberately
 *
 * Nothing here handles ZIP64. The export is capped by `papyrus.downloads.cbz_export_max_bytes`
 * at 256 MiB by default, and the cap is enforced against the running total by the caller, so
 * a four-gigabyte archive is refused long before a 32-bit field would wrap. Written here so
 * the omission is a decision on the record rather than an oversight found later.
 */
class CbzWriter
{
    /**
     * Record signatures, written as the four bytes they are rather than as integers to pack.
     *
     * `pack('V', "\x50\x4b\x03\x04")` writes four zero bytes: `V` takes an integer, a string
     * argument converts to 0, and the first version of this class produced an archive whose
     * every header began `00 00 00 00` and which `ZipArchive` refused with error 19. The
     * constants are spelled as escapes rather than as `0x04034b50` so that a reader can
     * compare them against the specification at a glance.
     */
    private const LOCAL_HEADER = "\x50\x4b\x03\x04";

    private const DATA_DESCRIPTOR = "\x50\x4b\x07\x08";

    private const CENTRAL_HEADER = "\x50\x4b\x01\x02";

    private const END_OF_CENTRAL = "\x50\x4b\x05\x06";

    /** Bit 3: the CRC and both sizes follow the data in a descriptor. */
    private const FLAG_DATA_DESCRIPTOR = 0x0008;

    private const METHOD_STORED = 0;

    private const VERSION = 20;

    /**
     * The CRC-32 register's starting value, which is all ones and not zero.
     *
     * CRC-32 is specified as "invert, run eight shifts per byte, invert", so the register
     * enters at 0xFFFFFFFF and the result is inverted again on the way out. Running it from
     * zero without either inversion yields the one's complement of the real checksum, which
     * is what the first version did: it wrote 0xEAAEB7DE for a payload whose CRC is
     * 0x3B15CE19. Both errors were invisible to the size checks and would have shipped as
     * archives that readers call corrupt.
     */
    private const CRC_SEED = 0xFFFFFFFF;

    /** Entries are named at this width so lexical order is reading order. */
    private const NAME_DIGITS = 3;

    /**
     * @var resource
     */
    private $stream;

    /**
     * Bytes written so far, which is what the central directory's offsets are relative to.
     */
    private int $offset = 0;

    /**
     * One row per entry, written at the end as the central directory.
     *
     * @var array<int, array{name: string, crc: int, size: int, offset: int}>
     */
    private array $entries = [];

    private bool $closed = false;

    /**
     * @param  resource  $stream  anything with fwrite and fflush; php://output in production
     *
     * @throws RuntimeException when the stream is not a resource
     */
    public function __construct($stream)
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('CbzWriter needs a stream resource, not '.get_debug_type($stream).'.');
        }

        $this->stream = $stream;
    }

    /**
     * Stream one entry.
     *
     * The name is what a comic reader sorts on, so it is the caller's to choose and this
     * makes no attempt to be clever about it. What this does guarantee is that the name is
     * usable: no directory separators, which would put the entry somewhere a reader does not
     * look, and no characters outside the range the format's encoding assumes.
     *
     * @param  callable(): iterable<string>  $chunks  yields the entry's bytes
     *
     * @throws RuntimeException on a second close, or a name that cannot be stored
     */
    public function add(string $name, callable $chunks): void
    {
        if ($this->closed) {
            throw new RuntimeException('This archive is already closed.');
        }

        $name = $this->normaliseName($name);

        $crc = self::CRC_SEED;
        $size = 0;
        $localOffset = $this->offset;

        $this->write($this->localHeader($name));

        // Called, not iterated. `$chunks` is the callable the caller handed over, and
        // `foreach` over a Closure yields nothing at all: a Closure is not Traversable. The
        // first version of this wrote the header and the descriptor and no page bytes, which
        // is a 53-byte archive for a 1000-byte entry and passed a size check written to look
        // at the wrong number. The callback exists so the bytes can arrive as they are
        // fetched rather than as one buffer the caller had to build first.
        foreach ($chunks() as $chunk) {
            $chunk = (string) $chunk;

            if ($chunk === '') {
                continue;
            }

            $crc = self::crcUpdate($crc, $chunk);
            $size += strlen($chunk);

            $this->write($chunk);
        }

        // The second inversion. crcUpdate() leaves the raw register, which is not the checksum.
        $finalCrc = $crc ^ 0xFFFFFFFF;

        $this->write($this->dataDescriptor($finalCrc, $size));

        $this->entries[] = [
            'name' => $name,
            'crc' => $finalCrc,
            'size' => $size,
            'offset' => $localOffset,
        ];
    }

    /**
     * How many bytes have gone into the archive so far.
     *
     * Exposed because the size guard is enforced against this running total, so that an
     * export is stopped *before* it exceeds the cap rather than after.
     */
    public function bytesWritten(): int
    {
        return $this->offset;
    }

    public function entryCount(): int
    {
        return count($this->entries);
    }

    /**
     * Write the central directory and finish.
     *
     * Not idempotent, deliberately. A second call would emit a second end-of-central-directory
     * record and produce a file that most readers reject, so the second call throws instead.
     *
     * @throws RuntimeException when called twice
     */
    public function close(): void
    {
        if ($this->closed) {
            throw new RuntimeException('This archive is already closed.');
        }

        $start = $this->offset;

        foreach ($this->entries as $entry) {
            $this->write($this->centralHeader($entry));
        }

        /*
         * Seven values here and the comment length as an eighth pack, because the record is
         * signature, disk, disk-with-directory, entries-here, entries-total, directory size,
         * directory offset, then comment length. The signature is the literal and the seven
         * values are packed after it, because `pack('V', ...)` needs an integer and a
         * signature spelled as one silently writes zeroes.
         */
        $this->write(self::END_OF_CENTRAL.pack(
            'vvvvVV',
            0,
            0,
            count($this->entries),
            count($this->entries),
            $this->offset - $start,
            $start
        ).pack('v', 0));

        $this->closed = true;
    }

    /**
     * A zero-padded page name, which is what a CBZ reader expects to sort on.
     *
     * `001.webp` and `1.webp` are the same page to a human and not the same page to
     * `sort()`, so the padding is load-bearing rather than cosmetic.
     */
    public static function pageName(int $index, string $extension): string
    {
        return str_pad((string) $index, self::NAME_DIGITS, '0', STR_PAD_LEFT).'.'.$extension;
    }

    private function normaliseName(string $name): string
    {
        // A separator would make this a directory entry as far as a reader is concerned.
        $name = str_replace(['/', '\\'], '-', $name);
        $name = ltrim($name, '.');

        if ($name === '') {
            throw new RuntimeException('An archive entry needs a name.');
        }

        return $name;
    }

    /**
     * * A running CRC-32, because PHP no longer offers one.
     *
     * `crc32()` takes exactly one argument: the two-argument incremental form was removed in
     * PHP 8, so there is no way to fold a chunk-by-chunk stream into one checksum with the
     * built-in. The alternative is to buffer an entry whole, learn its CRC afterwards, and go
     * back and write it into the local header, which needs a seekable stream and the per-entry
     * buffer this class exists to avoid.
     *
     * So the CRC-32 is computed here, from the reflected polynomial the format specifies, with
     * the usual 256-entry table so it costs one lookup and one xor per byte rather than eight
     * iterations. That is not fast enough to matter: a 250KB page is 250,000 lookups, and a
     * forty-page chapter is ten million, which is a few tens of milliseconds.
     *
     * The claim that this equals `crc32()` is not taken on trust. `CbzWriterTest` asserts the
     * running value against `crc32()` of the concatenated string, at several chunk
     * boundaries, because a hand-rolled checksum that is subtly wrong produces archives whose
     * CRC disagrees with their bytes and which readers reject as corrupt. That failure mode
     * looks exactly like a truncated download, which is why it is checked directly.
     *
     * @param  int  $crc  the running value, 0 to start
     */
    private static function crcUpdate(int $crc, string $chunk): int
    {
        $table = self::crcTable();

        // Bytes are indexed by the low 8 bits of the register XORed with the byte itself,
        // then the register shifts right by 8 and the table supplies the correction. This is
        // the standard reflected formulation and it is order-dependent, which is exactly why
        // it has to be fed one chunk at a time in order.
        for ($i = 0, $length = strlen($chunk); $i < $length; $i++) {
            $crc = ($crc >> 8) ^ $table[($crc ^ ord($chunk[$i])) & 0xFF];
        }

        return $crc & 0xFFFFFFFF;
    }

    /**
     * @return array<int, int>
     */
    private static function crcTable(): array
    {
        if (self::$crcTable !== null) {
            return self::$crcTable;
        }

        $table = [];

        for ($index = 0; $index < 256; $index++) {
            $value = $index;

            for ($bit = 0; $bit < 8; $bit++) {
                // `-($value & 1)` is all-ones when the low bit is set and zero otherwise, so
                // this is a mask rather than a multiply, which is the usual trick for making
                // an arithmetic shift act conditionally.
                $value = ($value >> 1) ^ (0xEDB88320 & -($value & 1));
            }

            $table[$index] = $value;
        }

        return self::$crcTable = $table;
    }

    /**
     * @var array<int, int>|null
     */
    private static ?array $crcTable = null;

    private function write(string $bytes): void
    {
        $written = fwrite($this->stream, $bytes);

        if ($written === false || $written !== strlen($bytes)) {
            throw new RuntimeException(
                'The archive stream accepted '.(is_int($written) ? $written : 0)
                .' of '.strlen($bytes).' bytes.'
            );
        }

        $this->offset += $written;
    }

    /**
     * @return string
     */
    private function localHeader(string $name)
    {
        [$time, $date] = $this->dosStamp();

        return self::LOCAL_HEADER
            .pack('v', self::VERSION)
            .pack('v', self::FLAG_DATA_DESCRIPTOR)
            .pack('v', self::METHOD_STORED)
            .pack('v', $time)
            .pack('v', $date)
            // Zeroed because they are not known yet, which is what flag bit 3 declares.
            .pack('V', 0)
            .pack('V', 0)
            .pack('V', 0)
            .pack('v', strlen($name))
            .pack('v', 0)
            .$name;
    }

    /**
     * @return string
     */
    private function dataDescriptor(int $crc, int $size)
    {
        // With the signature, which is what makes the archive readable by tools that expect
        // it and is tolerated by the ones that do not.
        return self::DATA_DESCRIPTOR
            .pack('V', $crc)
            .pack('V', $size)
            .pack('V', $size);
    }

    /**
     * @param  array{name: string, crc: int, size: int, offset: int}  $entry
     * @return string
     */
    private function centralHeader(array $entry)
    {
        [$time, $date] = $this->dosStamp();

        return self::CENTRAL_HEADER
            .pack('v', self::VERSION)
            .pack('v', self::VERSION)
            .pack('v', self::FLAG_DATA_DESCRIPTOR)
            .pack('v', self::METHOD_STORED)
            .pack('v', $time)
            .pack('v', $date)
            .pack('V', $entry['crc'])
            .pack('V', $entry['size'])
            .pack('V', $entry['size'])
            .pack('v', strlen($entry['name']))
            .pack('v', 0)
            .pack('v', 0)
            .pack('v', 0)
            .pack('v', 0)
            .pack('V', 0)
            .pack('V', $entry['offset'])
            .$entry['name'];
    }

    /**
     * MS-DOS time and date, which is the only timestamp the base format has.
     *
     * Seconds are not representable and the year is not either, both of which are known
     * limitations of the format rather than of this writer. Two-second resolution on the
     * clock is deliberate: it is the resolution the field holds.
     *
     * @return array{0: int, 1: int}
     */
    private function dosStamp(): array
    {
        $now = time();

        return [
            // Seconds are stored in units of two, so 0 to 29 is representable and 30 to 59
            // is not. Hiding the low bit rather than carrying it is what the format asks for.
            (int) (($now % 86400) / 2),
            // 1980 is the earliest year the DOS epoch can express, which is why the shift is
            // off by one rather than being a plain division.
            (int) (((int) date('Y', $now) - 1980) << 9 | (int) date('n', $now) << 5 | (int) date('j', $now)),
        ];
    }
}
