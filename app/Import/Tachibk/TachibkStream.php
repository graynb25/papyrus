<?php

namespace App\Import\Tachibk;

use Generator;

/**
 * A `.tachibk` walked as protobuf fields without holding its payload.
 *
 * ## Why reading a whole backup into memory stopped being acceptable
 *
 * The format is a gzip around one protobuf message, and the first reader decompressed
 * the entire message with `gzdecode()`. That costs the compressed file and the payload
 * at once, and a library backup grows with the library: a file of a few hundred
 * megabytes decompresses to a multiple of itself. The import path is now given 256MB
 * of upload room, so a reader could hand it a file that the old path could never open.
 *
 * A length-delimited protobuf field is self-describing: its tag names the field and
 * its length prefix says how many bytes follow. So the top level can be walked from a
 * gzip stream one field at a time, and a field the caller did not ask for can be
 * discarded without ever becoming a PHP string. Each series is loaded whole because
 * its chapters are nested inside it, which bounds memory by one series rather than by
 * the library.
 *
 * ## Why this is not `ProtoBuffer` itself
 *
 * `ProtoBuffer` owns the wire rules and is measured against the real file. This class
 * only supplies the bytes: the tag and varint decoding below is the same algorithm,
 * but against a stream rather than a string, and the accessors in `ProtoBuffer` still
 * do the field naming. Two copies of the rules would be a way for them to disagree, so
 * the value of this class is the buffered reader, not a second schema.
 *
 * ## Failures are the parser's failures
 *
 * A gzip that is not a backup, or one cut short mid-field, reports through
 * {@see MalformedProto} exactly as the string walk does. Callers that probe a file
 * (`recognises()`, `describe()`) turn that into a false or a zero reading; the importer
 * turns it into a refusal.
 */
final class TachibkStream
{
    /** One buffered read from zlib. Big enough to keep syscalls rare, small enough to be free. */
    private const READ_CHUNK = 65536;

    /** One write to zlib, so an export never holds a second compressed copy. */
    private const WRITE_CHUNK = 1048576;

    /**
     * The open zlib handle.
     *
     * @var resource
     */
    private $handle;

    /** Bytes already read from zlib and not yet consumed. */
    private string $buffer = '';

    /** How far into {@see $buffer} the walk has read. */
    private int $offset = 0;

    /** Whether zlib has reported the end of the payload. */
    private bool $ended = false;

    /** Whether the last read failed rather than reaching a clean end. */
    private bool $failed = false;

    /**
     * @param  resource  $handle
     */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    /**
     * Open a file for a walk, or null when it is absent or not gzip.
     *
     * The magic is checked before `gzopen()` because zlib reads an uncompressed file
     * transparently. A plain file would otherwise walk as bytes and be reported as a
     * damaged backup instead of as something that was never a backup.
     */
    public static function open(string $path): ?self
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $magic = @file_get_contents($path, false, null, 0, 2);

        if ($magic !== TachibkSchema::GZIP_MAGIC) {
            return null;
        }

        $handle = @gzopen($path, 'rb');

        return $handle === false ? null : new self($handle);
    }

    /**
     * Walk the top level fields, in the order the file holds them.
     *
     * `$wanted` names the field numbers whose values the caller needs. Everything else
     * is skipped from the stream without allocating its value, which is what keeps a
     * large application-state field or a future field from becoming memory. Passing
     * null returns every field and is for tests and probes.
     *
     * @param  list<int>|null  $wanted
     * @return Generator<int, array{int, int|string}>
     *
     * @throws MalformedProto
     */
    public function fields(?array $wanted = null): Generator
    {
        while (($tag = $this->nextTag()) !== null) {
            $number = $tag >> 3;
            $wire = $tag & 7;

            if ($number === 0) {
                throw MalformedProto::fieldZero();
            }

            $keep = $wanted === null || in_array($number, $wanted, true);

            switch ($wire) {
                case ProtoBuffer::WIRE_VARINT:
                    $value = $this->varint();

                    if ($keep) {
                        yield $number => [$wire, $value];
                    }

                    break;

                case ProtoBuffer::WIRE_BYTES:
                    $size = $this->varint();

                    if ($keep) {
                        yield $number => [$wire, $this->take($size)];
                    } else {
                        $this->skip($size);
                    }

                    break;

                case ProtoBuffer::WIRE_FIXED32:
                case ProtoBuffer::WIRE_FIXED64:
                    $size = $wire === ProtoBuffer::WIRE_FIXED32 ? 4 : 8;

                    if ($keep) {
                        yield $number => [$wire, $this->take($size)];
                    } else {
                        $this->skip($size);
                    }

                    break;

                default:
                    throw MalformedProto::wireType($wire, $number);
            }
        }
    }

    /**
     * Release the zlib handle. Safe to call twice, and called by the destructor.
     */
    public function close(): void
    {
        if (is_resource($this->handle)) {
            @gzclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * The whole decompressed payload as one string, without the compressed copy too.
     *
     * The export path needs the payload as one value because the merger rewrites a
     * record and returns a new message; it cannot consume a generator. Decompressing
     * through a temporary stream means the file's compressed bytes are released as
     * they are read, so the peak is the payload plus one chunk rather than the payload
     * plus the whole `.tachibk` it came in.
     */
    public static function payload(string $path): string|false
    {
        $in = self::open($path);

        if ($in === null) {
            return false;
        }

        $out = fopen('php://temp/maxmemory:'.(2 * 1024 * 1024), 'w+b');

        if ($out === false) {
            $in->close();

            return false;
        }

        try {
            while (true) {
                $chunk = @gzread($in->handle, self::WRITE_CHUNK);

                if ($chunk === false) {
                    return false;
                }

                if ($chunk === '') {
                    break;
                }

                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    return false;
                }
            }

            rewind($out);
            $payload = stream_get_contents($out);

            return $payload === false ? false : $payload;
        } finally {
            $in->close();
            fclose($out);
        }
    }

    /**
     * Write a payload as gzip, one chunk at a time.
     *
     * The export's merger has already built the message, so only the compressed copy is
     * avoidable here. Writing through zlib means that copy never exists as a PHP string.
     */
    public static function write(string $path, string $payload): bool
    {
        $handle = @gzopen($path, 'wb6');

        if ($handle === false) {
            return false;
        }

        $length = strlen($payload);

        for ($offset = 0; $offset < $length; $offset += self::WRITE_CHUNK) {
            $chunk = substr($payload, $offset, self::WRITE_CHUNK);

            if (@gzwrite($handle, $chunk) !== strlen($chunk)) {
                @gzclose($handle);

                return false;
            }
        }

        return @gzclose($handle);
    }

    /**
     * The next field tag, or null at the clean end of the payload.
     *
     * @throws MalformedProto
     */
    private function nextTag(): ?int
    {
        return $this->readVarint();
    }

    /**
     * The next varint, which a tag has already promised exists.
     *
     * A value or a length cannot be absent at a clean end: the field header said the
     * bytes follow. Only the tag itself may end the walk, which is why this throws
     * where {@see nextTag()} answers null.
     *
     * @throws MalformedProto
     */
    private function varint(): int
    {
        $value = $this->readVarint();

        if ($value === null) {
            throw MalformedProto::cutShort();
        }

        return $value;
    }

    /**
     * A varint, or null when the payload ends before its first byte.
     *
     * @throws MalformedProto
     */
    private function readVarint(): ?int
    {
        $result = 0;
        $shift = 0;

        while (true) {
            $byte = $this->byte();

            if ($byte === null) {
                if ($shift === 0) {
                    return null;
                }

                throw MalformedProto::cutShort();
            }

            $chunk = $byte & 0x7F;

            if ($shift > 63 || ($shift === 63 && $chunk > 1)) {
                throw MalformedProto::tooLarge();
            }

            $result |= $chunk << $shift;

            if (! ($byte & 0x80)) {
                return $result;
            }

            $shift += 7;
        }
    }

    /**
     * One byte, or null at a clean end of the payload.
     *
     * @throws MalformedProto
     */
    private function byte(): ?int
    {
        if (! $this->fill()) {
            if ($this->failed) {
                throw MalformedProto::cutShort();
            }

            return null;
        }

        return ord($this->buffer[$this->offset++]);
    }

    /**
     * Exactly this many bytes, as one string.
     *
     * @throws MalformedProto
     */
    private function take(int $size): string
    {
        if ($size < 0) {
            throw MalformedProto::cutShort();
        }

        /*
         * The declared length is known before any of the value is read, so this is the
         * only point where the cap can stop the allocation instead of measuring it after
         * the fact. `skip()` is deliberately not capped: an unwanted field is discarded
         * from the stream and never becomes a string.
         */
        $limit = (int) config('papyrus.import.max_field_bytes', 16_777_216);

        if ($limit > 0 && $size > $limit) {
            throw MalformedProto::fieldTooLarge($limit);
        }

        $value = '';

        while (strlen($value) < $size) {
            if (! $this->fill()) {
                throw MalformedProto::cutShort();
            }

            $available = strlen($this->buffer) - $this->offset;
            $chunk = min($size - strlen($value), $available);

            $value .= substr($this->buffer, $this->offset, $chunk);
            $this->offset += $chunk;
        }

        return $value;
    }

    /**
     * Discard this many bytes without making a string of them.
     *
     * @throws MalformedProto
     */
    private function skip(int $size): void
    {
        if ($size < 0) {
            throw MalformedProto::cutShort();
        }

        while ($size > 0) {
            if (! $this->fill()) {
                throw MalformedProto::cutShort();
            }

            $available = strlen($this->buffer) - $this->offset;
            $take = min($size, $available);

            $this->offset += $take;
            $size -= $take;
        }
    }

    /**
     * Make sure the buffer has an unread byte, refilling it when it does not.
     *
     * @throws MalformedProto
     */
    private function fill(): bool
    {
        if ($this->offset < strlen($this->buffer)) {
            return true;
        }

        if ($this->ended) {
            return false;
        }

        $chunk = @gzread($this->handle, self::READ_CHUNK);

        if ($chunk === false) {
            $this->failed = true;
            $this->ended = true;

            return false;
        }

        if ($chunk === '') {
            $this->ended = true;

            return false;
        }

        $this->buffer = $chunk;
        $this->offset = 0;

        return true;
    }
}
