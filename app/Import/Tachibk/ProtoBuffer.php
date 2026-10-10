<?php

namespace App\Import\Tachibk;

use Generator;

/**
 * Just enough of the protobuf wire format to walk a backup, and nothing more.
 *
 * ## Why this exists rather than a composer package
 *
 * Because the whole wire format this format uses is four cases, and every one of them is
 * below. `google/protobuf` would bring a runtime, a generated class per message, and a
 * `protoc` step to produce them, in exchange for reading one file type that a reader
 * application exports. The alternative is not "a dependency" so much as a build toolchain,
 * and there is no `protoc` on this machine and no intention to add one.
 *
 * The trade is accepted knowingly: a generated reader would reject an unknown wire type or an
 * unknown field by construction, while this one has to be told to. That is why
 * {@see fields()} skips forward on a field number it was not asked about rather than
 * failing, because the format grows new fields every year and an older file reader must
 * survive a newer file. The one thing it must never do is guess at a value, so the accessors
 * here return exactly what the bytes said and nothing inferred.
 *
 * ## What it does not do
 *
 * It does not validate, name, or describe the fields. That is {@see TachibkSchema}, which is
 * where the measured numbers live. Splitting the two means a future revision of the format is
 * a change to one file, and the parser is still correct for the new one.
 *
 * ## Numbers wider than a PHP integer
 *
 * Source ids in this format are around 2.3e18, which a 64 bit integer holds exactly but a
 * double does not. Every varint is therefore decoded into an integer or refused, never into a
 * float, because a silently rounded source id would match no source at all and the import
 * would report series it could not place with no explanation for why.
 */
final class ProtoBuffer
{
    /** A whole number, written one or more bytes with the top bit as a continuation. */
    public const WIRE_VARINT = 0;

    /** A fixed 8 byte block. Not used by any field this reader maps. */
    public const WIRE_FIXED64 = 1;

    /** A length followed by that many bytes. Strings and nested messages both arrive this way. */
    public const WIRE_BYTES = 2;

    /** A fixed 4 byte block, which is how a float arrives. */
    public const WIRE_FIXED32 = 5;

    /**
     * Never instantiated. The class is a namespace for the walking functions.
     */
    private function __construct() {}

    /**
     * Walk one message, yielding each field in the order the file holds it.
     *
     * Yields `field number => [wire type, value]`, where the value is an int for a varint
     * and a string for everything else. Keying by field number rather than yielding a triple
     * is deliberate: the format repeats field 7 for every genre on a series, so a reader
     * collects those with a loop while single-valued fields are read with a lookup, and both
     * read the same way.
     *
     * A repeated field yields once per occurrence, in file order, so two occurrences of the
     * same number are two entries rather than one overwritten value.
     *
     * The wire type and the value type cannot be correlated in a type, so a caller holding a
     * value still has to know it asked for a length delimited field. {@see messages()} is the
     * way to ask for those without repeating the check, and it is what this reader uses.
     *
     * @return Generator<int, array{int, int|string}>
     *
     * @throws MalformedProto
     */
    public static function fields(string $bytes): Generator
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $tag = self::varint($bytes, $offset, $length);
            $number = $tag >> 3;
            $wire = $tag & 7;

            if ($number === 0) {
                throw MalformedProto::fieldZero();
            }

            /*
             * A switch rather than a match, because the length delimited case has to read its
             * length before it can take the bytes, and that is two statements. Written as a
             * match expression it would depend on the offset argument being passed by
             * reference and evaluated after a nested call in the argument list, which works and
             * is unreadable.
             */
            switch ($wire) {
                case self::WIRE_VARINT:
                    $value = self::varint($bytes, $offset, $length);

                    break;

                case self::WIRE_BYTES:
                    $size = self::varint($bytes, $offset, $length);
                    $value = self::take($bytes, $offset, $length, $size);

                    break;

                case self::WIRE_FIXED32:
                    $value = self::take($bytes, $offset, $length, 4);

                    break;

                case self::WIRE_FIXED64:
                    $value = self::take($bytes, $offset, $length, 8);

                    break;

                default:
                    throw MalformedProto::wireType($wire, $number);
            }

            yield $number => [$wire, $value];
        }
    }

    /**
     * Every nested message the file put in one field, in file order.
     *
     * Strings and nested messages share a wire type, so asking for one field number means
     * getting both unless the caller filters, and every reader then has to remember the same
     * pair of checks. This does that filtering once and hands back something that is known to
     * be a message, which is the only thing a caller can actually walk into.
     *
     * A field that appears once yields once, which is why the callers below do not care
     * whether something is repeated or singular before iterating it.
     *
     * @return Generator<int, string>
     *
     * @throws MalformedProto
     */
    public static function messages(string $bytes, int $number): Generator
    {
        foreach (self::fields($bytes) as $key => [$wire, $value]) {
            if ($key !== $number || $wire !== self::WIRE_BYTES) {
                continue;
            }

            /*
             * A length delimited field always yields a string, so this cannot be false. It is
             * here because the walk reports the wire type and the value separately and a
             * reader cannot see the link between them, and a cast would be claiming a type
             * the walk never promised. Narrowing on the value states the same invariant in a
             * form a reader can check.
             */
            if (is_string($value)) {
                yield $value;
            }
        }
    }

    /**
     * The last value the message gave for one field number.
     *
     * For fields this format writes once. Where a field genuinely repeats, a caller loops
     * {@see fields()} instead, because "the last one wins" is the wrong answer for genres.
     *
     *
     * @throws MalformedProto
     */
    public static function value(string $bytes, int $number): int|string|null
    {
        $found = null;

        foreach (self::fields($bytes) as $key => [$wire, $value]) {
            if ($key === $number) {
                $found = $value;
            }
        }

        return $found;
    }

    /**
     * Read a length delimited field as text.
     *
     * Returns null for anything that is not valid UTF-8 rather than substituting, because a
     * series title that came out as mojibake is worse than one reported as missing: the first
     * is invisible to a reader and the second is visible.
     */
    public static function text(string $bytes, int $number): ?string
    {
        $raw = self::value($bytes, $number);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        return $raw;
    }

    /**
     * Read a varint field as an int, or null when it is absent or not a number.
     */
    public static function integer(string $bytes, int $number): ?int
    {
        $raw = self::value($bytes, $number);

        return is_int($raw) ? $raw : null;
    }

    /**
     * Read a flag that is true when present and non-zero.
     *
     * This exists because the obvious expression is wrong in the direction that matters.
     * `integer($bytes, 4) !== 0` reads as true for an absent field, because `null !== 0`, and
     * protobuf omits a false rather than writing zero. So the naive form reports every chapter
     * in a backup as read: on the file this reader was written against it claimed 2,911 read
     * chapters where the file holds 1,616, and it did so while every other number it printed
     * stayed correct, which is the worst way for it to be wrong.
     *
     * Absent therefore means false, and only a field that is actually there can be true.
     */
    public static function flag(string $bytes, int $number): bool
    {
        $raw = self::value($bytes, $number);

        return is_int($raw) && $raw !== 0;
    }

    /**
     * Read a 32 bit float field, which is how a fractional chapter number arrives.
     *
     * @throws MalformedProto
     */
    public static function single(string $bytes, int $number): ?float
    {
        $raw = self::value($bytes, $number);

        if (! is_string($raw) || strlen($raw) !== 4) {
            return null;
        }

        /*
         * `g`, not `G`. Protobuf writes a fixed32 little endian, and the two differ only in
         * byte order, which turns 700.5 into a denormalised number so small it reads as zero
         * rather than as an error. Chapter numbers would then all be 0.
         */
        /** @var array{1: float} $unpacked */
        $unpacked = unpack('g', $raw);

        return $unpacked[1];
    }

    /**
     * Read a varint, refusing rather than wrapping one this server cannot hold exactly.
     *
     * The shift arithmetic is the whole reason this is a function rather than an inline
     * expression. A byte past the ninth contributes bits above 63, where PHP's shift does
     * not error, it silently keeps the low bits, so the number comes back plausible and
     * wrong. The check runs before the shift rather than after, because after the damage is
     * already done.
     *
     * @throws MalformedProto
     */
    private static function varint(string $bytes, int &$offset, int $length): int
    {
        $result = 0;
        $shift = 0;

        while (true) {
            if ($offset >= $length) {
                throw MalformedProto::cutShort();
            }

            $byte = ord($bytes[$offset]);
            $offset++;

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
     * Take exactly $count bytes, refusing if the buffer ends first.
     *
     * @throws MalformedProto
     */
    private static function take(string $bytes, int &$offset, int $length, int $count): string
    {
        if ($count < 0 || $offset + $count > $length) {
            throw MalformedProto::cutShort();
        }

        $slice = substr($bytes, $offset, $count);
        $offset += $count;

        return $slice;
    }
}
