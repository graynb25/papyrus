<?php

namespace Tests\Support;

use App\Import\Tachibk\TachibkSchema;

/**
 * Builds a synthetic `.tachibk` for tests.
 *
 * ## Why it writes real protobuf rather than stubbing the parser
 *
 * Because the parser is the thing most likely to be wrong, and a fixture that hands it
 * already decoded values cannot catch a decoder that reads the wrong field. So this builds
 * the actual bytes: gzip on the outside, length prefixed varints within, and a float32 for
 * every chapter number. A test that fails here means the wire format handling is wrong,
 * which is the failure worth catching.
 *
 * ## Why not the real file
 *
 * There is a real 77KB backup, and reading it in a test would be the most honest check
 * available. It is also a reader's own library sitting in the working tree, it is not
 * committed, and a test that needs a file nobody else has is a test that quietly stops
 * running. So this builds the shape, and `.tools/suwayomi/probe-tachibk-read.php` reads the
 * real one by hand and is where the measured figures in the schema came from.
 *
 * ## The shape is the real one
 *
 * Field for field, including the parts that are easy to get wrong. Series carry a source id
 * past 2^53. Chapter numbers are floats and one of them is fractional. The read flag is
 * *omitted* on unread chapters rather than written as zero, which is what protobuf does and
 * what the reader has to survive. Read chapters also appear in a history list naming the same
 * chapters, because in the real file those two agree exactly and a fixture where they
 * disagree would be testing a file nobody has.
 *
 * ## Knobs, not arguments
 *
 * Every way a backup can be unreadable or misleading is a named method rather than a boolean
 * argument, so a test says `->withCutPayload()` instead of `->build(false, true, false)`. A
 * reader reading these can tell what is being tested without cross-referencing a signature.
 */
trait BuildsTachibkBackups
{
    /** The source id from the real backup, which is past 2^53 and must not be rounded. */
    protected int $largeSourceId = 2327480808438768017;

    /** Build a file with no series in it, only application state. */
    protected bool $withoutSeries = false;

    /**
     * Build a file that is valid protobuf throughout, and whose only series carry no title.
     *
     * The case that separates "parses as protobuf" from "is this format". A file of plain
     * compressed text fails to parse and proves nothing on its own, so it needs a second
     * shape: bytes that walk cleanly from end to end, hold entries in exactly the field a
     * series goes in, and are still not a backup because none of them has a title.
     */
    protected bool $withSeriesLackingTitles = false;

    /** Build a gzip whose contents are plain text rather than protobuf. */
    protected bool $gzipButNotProtobuf = false;

    /** Start with the gzip magic and then damage it, so decompression fails. */
    protected bool $withBrokenGzip = false;

    /** Wrap a protobuf message that stops part way through a field. */
    protected bool $withCutPayload = false;

    /** Add field numbers this reader does not map, which a newer writer would send. */
    protected bool $withUnknownFields = false;

    /** Add a series carrying neither a title nor a url. */
    protected bool $withUnnameableSeries = false;

    /**
     * Put the second flag on chapters and leave the read flag off them.
     *
     * This is the shape that would look like read state to anything mapping the wrong number.
     * In the real file the two are indistinguishable, and this is the fixture that proves
     * the reader is not reading state out of the field it cannot justify.
     */
    protected bool $withSecondFlagOnly = false;

    /**
     * Give chapters a listing position and no number and no name.
     *
     * Position is the app's sort order rather than the source's, and the writer tries a
     * positional key before a chapter number, so this is the fixture that proves an
     * untrustworthy index never becomes the pairing key.
     */
    protected bool $withPositionOnly = false;

    /** Leave out the top level source list, so no source gets a name. */
    protected bool $withoutSourceNames = false;

    /**
     * Write a source id too wide for a 64 bit integer.
     *
     * Hand assembled rather than encoded, because an encoder given such a number wraps around
     * silently and would produce a different wrong number instead of the one under test. The
     * ten bytes below are a varint whose tenth byte claims bit 63, which is one past the last
     * bit a signed 64 bit integer has. Decoding it into a float instead would round a source
     * id and quietly match nothing.
     */
    protected bool $withOversizedNumber = false;

    /**
     * Write a field using a group wire type.
     *
     * Groups were deprecated in protobuf long before any reader app existed, so one can only
     * mean the bytes are something else that happens to be a protobuf shaped stream. It also
     * arrives after a series the reader accepts, so this is the fixture that proves such a
     * file is refused on the full walk rather than half read.
     */
    protected bool $withGroupWireType = false;

    /** Add the exporter's own timestamps, which are not dates the reader did anything. */
    protected bool $withExporterTimestamps = false;

    /**
     * Write a backup to a temporary file and return its path.
     *
     * @param  string  $path  Where to write it. Empty means a temporary file.
     */
    protected function buildTachibkBackup(string $path = ''): string
    {
        if ($path === '') {
            $path = (string) tempnam(sys_get_temp_dir(), 'papyrus-tachibk-');
        }

        file_put_contents($path, $this->tachibkBytes());

        return $path;
    }

    /**
     * The whole file as bytes, so a test can damage it in a specific way.
     */
    protected function tachibkBytes(): string
    {
        if ($this->gzipButNotProtobuf) {
            return (string) gzencode('this is compressed but it is not a protobuf message at all');
        }

        $payload = $this->tachibkPayload();

        if ($this->withCutPayload) {
            /*
             * Cut inside the very first field of the very first series, rather than at some
             * fraction of the file. That series opens with its source id, which for the id this
             * fixture uses is nine bytes with the continuation bit set on the first two, so
             * stopping two bytes in leaves a number the file never finishes. A cut at an
             * arbitrary offset would sometimes land on a field boundary and produce a valid
             * shorter message, which is a different test and a misleading one.
             */
            $first = $this->seriesMessages()[0];
            $prefix = strlen($this->tag(TachibkSchema::BACKUP_SERIES, 2))
                + strlen($this->encodeVarint(strlen($first)));

            $payload = substr($payload, 0, $prefix + 2);
        }

        $bytes = (string) gzencode($payload);

        if ($this->withBrokenGzip) {
            // Keep the magic so the file still looks like a gzip, then break the body.
            $bytes = substr($bytes, 0, 12)."\x00\x00\x00\x00\x00".substr($bytes, 17);
        }

        return $bytes;
    }

    /**
     * The decompressed message: one series list, one source list, and optional app state.
     */
    private function tachibkPayload(): string
    {
        $payload = '';

        if ($this->withSeriesLackingTitles) {
            // Walks cleanly, sits in the right field, and has nothing a reader could be shown.
            $payload .= $this->bytes(
                TachibkSchema::BACKUP_SERIES,
                $this->varint(TachibkSchema::SERIES_SOURCE, $this->largeSourceId),
            );
        } elseif (! $this->withoutSeries) {
            foreach ($this->seriesMessages() as $series) {
                $payload .= $this->bytes(TachibkSchema::BACKUP_SERIES, $series);
            }
        }

        if (! $this->withoutSourceNames) {
            foreach ($this->sources() as $id => $name) {
                $payload .= $this->bytes(
                    TachibkSchema::BACKUP_SOURCE,
                    $this->bytes(1, $name).$this->varint(2, $id),
                );
            }
        }

        if ($this->withUnknownFields) {
            /*
             * Two application state fields and a field number from the middle of nowhere on a
             * series and a chapter. A reader written against a published schema would choke or
             * misread here; a reader that walks the wire does not care what a number means.
             */
            $payload .= $this->bytes(9000, $this->bytes(1, 'webUI_migration').$this->bytes(2, '9'));
            $payload .= $this->bytes(9001, $this->bytes(1, '10mb'));
        }

        if ($this->withGroupWireType) {
            // Wire type 3, which this format never writes.
            $payload .= $this->tag(77, 3)."\x00";
        }

        return $payload;
    }

    /**
     * The two series, in the shape the real file holds them.
     *
     * @return list<string>
     */
    private function seriesMessages(): array
    {
        $messages = [];

        if ($this->withUnnameableSeries) {
            // Carries a source id and a timestamp, and nothing that could be shown or matched.
            $messages[] = $this->varint(TachibkSchema::SERIES_SOURCE, $this->largeSourceId);
        }

        // A long shelf, mostly read, with fractional chapter numbers.
        $messages[] = $this->seriesMessage($this->largeSourceId, 'rSmmf', 'Naruto', [[700.5, true], [700, true], [699, true], [698, false], [686.5, false]]);

        // A short shelf, entirely unread, on a small source id.
        $messages[] = $this->seriesMessage(1234, 'tiny', 'A Short Shelf', [[1, false], [2, false]]);

        if ($this->withOversizedNumber) {
            $messages[] = $this->tag(TachibkSchema::SERIES_SOURCE, 0).str_repeat("\xFF", 9)."\x02";
        }

        return $messages;
    }

    /**
     * @return array<int, string>
     */
    private function sources(): array
    {
        return [
            $this->largeSourceId => 'Atsumaru',
            1234 => 'A Smaller Source',
        ];
    }

    /**
     * One series, with its chapters and a history list that names exactly the read ones.
     *
     * @param  list<array{float, bool}>  $chapters  Number and read flag, in listing order.
     */
    private function seriesMessage(int $sourceId, string $url, string $title, array $chapters): string
    {
        $message = $this->varint(TachibkSchema::SERIES_SOURCE, $sourceId)
            .$this->bytes(TachibkSchema::SERIES_URL, $url)
            .$this->bytes(TachibkSchema::SERIES_TITLE, $title)
            .$this->bytes(TachibkSchema::SERIES_AUTHOR, 'Masashi Kishimoto')
            .$this->bytes(TachibkSchema::SERIES_DESCRIPTION, 'A long description with **markdown** in it.')
            .$this->bytes(TachibkSchema::SERIES_GENRE, 'Shounen')
            .$this->bytes(TachibkSchema::SERIES_GENRE, 'Ninja');

        $position = 0;

        foreach ($chapters as [$number, $read]) {
            $position++;
            $chapter = $this->chapterMessage($url, $number, $read, $position);

            $message .= $this->bytes(TachibkSchema::SERIES_CHAPTER, $chapter);

            if ($read && ! $this->withPositionOnly) {
                // The real file names every read chapter here, and the two lists agree exactly.
                $message .= $this->bytes(
                    TachibkSchema::SERIES_HISTORY,
                    $this->bytes(TachibkSchema::HISTORY_URL, $url.'/'.$this->chapterKey($number))
                        .$this->varint(TachibkSchema::HISTORY_AT, 1791653562000),
                );
            }
        }

        if ($this->withExporterTimestamps) {
            $message .= $this->varint(TachibkSchema::SERIES_MILLIS, 1791653554000)
                .$this->varint(TachibkSchema::SERIES_SECONDS, 1791653554);
        }

        if ($this->withUnknownFields) {
            $message .= $this->bytes(4242, 'a field number this reader has never heard of');
        }

        return $message;
    }

    private function chapterMessage(string $seriesUrl, float $number, bool $read, int $position): string
    {
        $name = 'Chapter '.$number;

        $chapter = $this->bytes(TachibkSchema::CHAPTER_URL, $seriesUrl.'/'.$this->chapterKey($number));

        if (! $this->withPositionOnly) {
            $chapter .= $this->bytes(TachibkSchema::CHAPTER_NAME, $name)
                .$this->single(TachibkSchema::CHAPTER_NUMBER, $number);
        }

        /*
         * Omitted rather than written as zero when false, because that is what protobuf does
         * and what the real file does. A reader that treats an absent flag as false is correct;
         * one that treats it as anything else marks a whole unread shelf as read.
         *
         * Suppressed entirely under `withSecondFlagOnly`, so the unexplained flag is the only
         * thing on the chapter claiming anything about reading. The history list still names
         * those same chapters, so this is a file where the two disagree and the reader has to
         * trust the field Mihon declares rather than whichever list it finds first.
         */
        if ($read && ! $this->withSecondFlagOnly) {
            $chapter .= $this->varint(TachibkSchema::CHAPTER_READ, 1);
        }

        if ($this->withSecondFlagOnly) {
            // Present on exactly the read chapters in the real file, and never explained.
            $chapter .= $this->varint(TachibkSchema::CHAPTER_SECOND_FLAG, 1);
        }

        $chapter .= $this->varint(TachibkSchema::CHAPTER_LIST_POSITION, $position);

        if ($this->withExporterTimestamps) {
            $chapter .= $this->varint(TachibkSchema::CHAPTER_MILLIS, 1791653550000);
        }

        return $chapter;
    }

    private function chapterKey(float $number): string
    {
        return str_replace('.', '', number_format($number, 1, '.', ''));
    }

    // ------------------------------------------------------------ wire encoding

    private function varint(int $field, int $value): string
    {
        return $this->tag($field, 0).$this->encodeVarint($value);
    }

    private function bytes(int $field, string $value): string
    {
        return $this->tag($field, 2).$this->encodeVarint(strlen($value)).$value;
    }

    private function single(int $field, float $value): string
    {
        // Little endian, because that is what a fixed32 is on the wire.
        return $this->tag($field, 5).pack('g', $value);
    }

    private function tag(int $field, int $wire): string
    {
        return $this->encodeVarint(($field << 3) | $wire);
    }

    private function encodeVarint(int $value): string
    {
        $out = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;

            if ($value !== 0) {
                $byte |= 0x80;
            }

            $out .= chr($byte);
        } while ($value !== 0);

        return $out;
    }
}
