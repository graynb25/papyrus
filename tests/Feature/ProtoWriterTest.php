<?php

namespace Tests\Feature;

use App\Import\Tachibk\ProtoBuffer;
use App\Import\Tachibk\ProtoWriter;
use Tests\TestCase;

/**
 * The write half of the wire, proven against the read half and against pinned bytes.
 *
 * A varint written wrong is invisible from this side: the bytes stay protobuf shaped, a
 * receiving reader decodes something plausible, and the mistake never comes back home. So
 * every test here reads its own product back through {@see ProtoBuffer}, the independent
 * reader the import runs on, and a few carry pinned byte literals whose encodings are
 * verifiable by hand without trusting either half.
 */
class ProtoWriterTest extends TestCase
{
    public function test_a_varint_field_reads_back_through_the_own_reader(): void
    {
        $bytes = ProtoWriter::varintField(4, 1);

        $fields = iterator_to_array(ProtoBuffer::fields($bytes));

        $this->assertSame([0, 1], $fields[4], 'the read flag field should come back whole');
    }

    public function test_the_encodings_match_bytes_the_wire_defines(): void
    {
        /*
         * Tag = (field << 3) | wire, then the value, each as a varint. Field 4 wire 0 is
         * 32, one byte. Field 17 wire 0 is 136, which is one byte too wide, so the tag
         * continues into a second byte. Field 1 wire 2 is 10, and an empty record carries
         * a length of zero. Each literal is checkable with a pencil.
         */
        $this->assertSame("\x20\x01", ProtoWriter::varintField(4, 1));
        $this->assertSame("\x88\x01\x01", ProtoWriter::varintField(17, 1));
        $this->assertSame("\x0a\x00", ProtoWriter::messageField(1, ''));
    }

    public function test_a_message_field_reads_back_from_the_own_reader_whole(): void
    {
        // The measured shape of the category record in the real sample, name and all.
        $record = ProtoWriter::messageField(1, 'Default');
        $bytes = ProtoWriter::messageField(2, $record);

        $inner = null;
        foreach (ProtoBuffer::messages($bytes, 2) as $message) {
            $inner = $message;
        }

        $this->assertSame($record, $inner, 'the nested record should survive one level of wrapping byte for byte');
        $this->assertSame('Default', ProtoBuffer::text((string) $inner, 1), 'the name should read back where the schema says it sits');
    }

    public function test_a_float_field_survives_the_round_trip(): void
    {
        $this->assertSame(700.5, ProtoBuffer::single(ProtoWriter::floatField(9, 700.5), 9), 'a fractional chapter number should come back exactly');
        $this->assertSame(700.0, ProtoBuffer::single(ProtoWriter::floatField(11, 700.0), 11), 'a whole number chapter should come back exactly');
    }

    public function test_a_source_id_wider_than_a_double_reads_back_exactly(): void
    {
        // Past 2^53, so any float copy of it would round and then match nothing.
        $id = 2327480808438768017;

        $this->assertSame($id, ProtoBuffer::value(ProtoWriter::varintField(1, $id), 1));
    }

    public function test_a_multi_byte_value_reads_back_whole(): void
    {
        $this->assertSame(128, ProtoBuffer::value(ProtoWriter::varintField(5, 128), 5));
        $this->assertSame(1791653554000, ProtoBuffer::value(ProtoWriter::varintField(2, 1791653554000), 2));
    }

    public function test_records_the_format_never_declares_pass_through_byte_for_byte(): void
    {
        // The measured shape of Suwayomi's 9000 blob: a key record and a value record.
        $unknownInner = ProtoWriter::messageField(1, 'webUI_migration')
            .ProtoWriter::messageField(2, '9');

        $writerSide = ProtoWriter::messageField(2, 'Default')
            .ProtoWriter::varintField(3, 5)
            .ProtoWriter::messageField(9000, $unknownInner);

        // Walk everything back, in file order, with nothing rewritten.
        $walked = [];
        foreach (ProtoBuffer::fields($writerSide) as $number => [$wire, $value]) {
            $walked[$number][] = $value;
        }

        $this->assertSame(['Default'], $walked[2], 'the wrapped record should arrive where it was written');
        $this->assertSame([5], $walked[3], 'the value field should arrive where it was written');
        $this->assertSame([$unknownInner], $walked[9000], 'an unknown field number should arrive byte for byte, so the writer serves a file it never needs to understand');
    }

    public function test_a_field_number_below_one_is_refused_before_anything_is_written(): void
    {
        $this->expectExceptionMessage('a protobuf field number starts at 1, got 0');

        ProtoWriter::varintField(0, 1);
    }

    public function test_a_message_field_with_a_field_number_below_one_is_refused(): void
    {
        $this->expectExceptionMessage('a protobuf field number starts at 1, got 0');

        ProtoWriter::messageField(0, 'anything');
    }

    public function test_a_float_field_with_a_field_number_below_one_is_refused(): void
    {
        $this->expectExceptionMessage('a protobuf field number starts at 1, got -3');

        ProtoWriter::floatField(-3, 1.0);
    }

    public function test_a_negative_value_is_refused_rather_than_encoded(): void
    {
        /*
         * Protobuf encodes negatives as ten byte two's complement records, and nothing this
         * writer emits can be negative: a read flag, an order and a source id are all
         * non negative by nature. The honest answer to a negative input is a refusal that
         * names the field, not bytes a receiving reader would decode as a large positive
         * number and file the series under.
         */
        $this->expectExceptionMessage('field 17 was given a negative value');

        ProtoWriter::varintField(17, -1);
    }
}
