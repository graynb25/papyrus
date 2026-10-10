<?php

namespace App\Import\Tachibk;

use RuntimeException;

/**
 * The bytes are not a protobuf message, or not one this reader can walk to the end.
 *
 * ## Why this is its own type rather than an ImportRefused
 *
 * Because {@see TachibkReader::recognises()} is handed every file the resolver tries, most of
 * which are not backups at all, and saying "not mine" must not be an exception. So the low
 * level parser reports through this, and the reader decides what a given caller should be
 * shown: a refusal for someone who asked to import, and silence for a probe still working
 * through the registered readers.
 *
 * Every message here names the thing that went wrong rather than saying "invalid data",
 * because the two failures a reader can act on look nothing alike. A file that stops in the
 * middle of a field was cut short, and exporting again fixes it. A gzip that holds something
 * other than this format is not a backup and no amount of retrying will make it one.
 */
final class MalformedProto extends RuntimeException
{
    /**
     * A length ran past the end of the buffer.
     *
     * Almost always a truncated file rather than a foreign one, because a foreign file that
     * happens to start with a plausible tag gets further before it fails.
     */
    public static function cutShort(): self
    {
        return new self(
            'it stops in the middle of a field. The file looks cut short, so export it again '
            .'and nothing needs to change on this server.'
        );
    }

    /**
     * Field number zero.
     *
     * Protobuf reserves it, so a tag that decodes to zero is not a field this writer meant to
     * produce. It is the first thing that goes wrong when a file is read as protobuf but is
     * actually something else.
     */
    public static function fieldZero(): self
    {
        return new self(
            'it starts with a field number of zero, which is not something a backup can contain. '
            .'That means the file is not a Mihon or Suwayomi backup.'
        );
    }

    /**
     * A wire type this format does not use.
     *
     * Groups, wire types 3 and 4, were deprecated in protobuf long before any reader app
     * existed, so seeing one means the bytes are something else that happens to be a
     * protobuf-shaped stream.
     */
    public static function wireType(int $type, int $field): self
    {
        return new self(sprintf(
            'it uses wire type %d on field %d, which this format never writes. That means the '
            .'file is not a Mihon or Suwayomi backup.',
            $type,
            $field,
        ));
    }

    /**
     * A number too large to hold exactly.
     *
     * A signed 64 bit integer is the largest this server can represent without rounding, and
     * a varint wider than that would silently become a float and change the number it names.
     * Source ids are the reason this matters: they sit past 2^53 already, so a reader that
     * decoded them carelessly would write a rounded id into the library and match nothing.
     */
    public static function tooLarge(): self
    {
        return new self(
            'it holds a number larger than this server can keep exact. Importing it could put '
            .'a rounded id on your library, so nothing has been changed.'
        );
    }
}
