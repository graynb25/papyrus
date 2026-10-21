<?php

namespace App\Import\Tachibk;

/**
 * The write half of the same four case wire format {@see ProtoBuffer} reads.
 *
 * It lives beside the reader on purpose: the format is one grammar, and a writer that sat
 * in a different file family would let a field number drift from the reader's without
 * anything noticing. {@see TachibkSchema} owns the numbers for both halves; this class
 * turns number, wire type and value into bytes and knows nothing about which number means
 * what.
 *
 * ## Why a merger needs a writer at all
 *
 * The export that uses this class never rewrites a record it did not change, so it needs
 * appends only: one field here, one record there. But append still means emit a tag and a
 * value, which is the whole of protobuf's write side, and getting a varint wrong is
 * invisible: the bytes stay plausible, the receiving reader decodes a wrong number, and
 * nothing on this side ever fails.
 */
final class ProtoWriter
{
    /**
     * Never instantiated. The class is a namespace for the emitting functions.
     */
    private function __construct() {}

    /**
     * One whole number field, the shape a read flag or a shelf order takes.
     */
    public static function varintField(int $number, int $value): string
    {
        if ($number < 1) {
            throw new \ValueError("a protobuf field number starts at 1, got $number");
        }

        if ($value < 0) {
            throw new \ValueError("field $number was given a negative value: protobuf writes negatives as ten byte two's complement records, and nothing this writer emits is ever negative");
        }

        return self::varint(($number << 3) | ProtoBuffer::WIRE_VARINT).self::varint($value);
    }

    /**
     * One length delimited field, the shape text, a nested record and a genre all take.
     */
    public static function messageField(int $number, string $bytes): string
    {
        if ($number < 1) {
            throw new \ValueError("a protobuf field number starts at 1, got $number");
        }

        return self::varint(($number << 3) | ProtoBuffer::WIRE_BYTES).self::varint(strlen($bytes)).$bytes;
    }

    /**
     * One 32 bit float field, the shape a fractional chapter number takes.
     */
    public static function floatField(int $number, float $value): string
    {
        if ($number < 1) {
            throw new \ValueError("a protobuf field number starts at 1, got $number");
        }

        /*
         * `g` for the same reason {@see ProtoBuffer::single()} reads with it: protobuf's
         * fixed32 is little endian, and the byte order is the only difference between a
         * number and a denormal.
         */
        return self::varint(($number << 3) | ProtoBuffer::WIRE_FIXED32).pack('g', $value);
    }

    /**
     * One value as one or more bytes, high bit set on every byte that has a successor.
     */
    private static function varint(int $value): string
    {
        $out = '';

        do {
            $chunk = $value & 0x7F;
            $value >>= 7;

            if ($value !== 0) {
                $chunk |= 0x80;
            }

            $out .= chr($chunk);
        } while ($value !== 0);

        return $out;
    }
}
