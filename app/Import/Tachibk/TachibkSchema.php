<?php

namespace App\Import\Tachibk;

use App\Import\SeriesWriter;

/**
 * The field numbers of a `.tachibk`, as measured rather than as published.
 *
 * ## Where these numbers come from
 *
 * From a real backup, read byte by byte, not from a schema file. That is the whole reason
 * this class exists. The obvious way to write this reader is to fetch the protobuf definition
 * from a reader app's repository and generate classes from it, and that approach was tried
 * first and produced a reader shaped like nothing the file actually contains. A published
 * definition describes what a writer *may* emit; the file describes what this writer *did*.
 * Those differ, and only one of them can be tested.
 *
 * So every constant below is annotated with what was observed in the file it came from. The
 * sample is the backup this reader was written against:
 *
 * ```
 * file     77,061 bytes on disk
 * gzip     one member, header 1f 8b 08 00, no FNAME, no FCOMMENT
 * payload  259,960 bytes once decompressed, a ratio of 3.4x
 * top      field 1 x4      series
 *          field 2 x1      one extension record, carrying no package name
 *          field 101 x1    one source, name "Atsumaru", id 2327480808438768017
 *          field 9000 x1   key "webUI_migration", value "9"
 *          field 9001 x1   application preferences
 * ```
 *
 * ## Fields 9000 and 9001 are Suwayomi's, and this is a Suwayomi file
 *
 * The sample was written by Suwayomi, not by Mihon. `webUI_migration` is a state flag for
 * Suwayomi's own web interface and means nothing to any other reader app, and the
 * preferences block holds a websocket URL and a cache ceiling rather than anything a phone
 * app writes. Those two field numbers are therefore recorded as belonging to the writer that
 * produced them and are not mapped here.
 *
 * That distinction matters for what this reader can claim. The core it maps, series with
 * their source, url and title, chapters with their name, number and read flag, is the
 * Tachiyomi and Mihon schema, and it was confirmed against Mihon's own exporter definition,
 * which declares the same numbers: `url: 1`, `name: 2`, `scanlator: 3`, `read: 4` and
 * `chapterNumber: 9` on a chapter, and `source: 1`, `url: 2`, `title: 3`, `author: 4`,
 * `artist: 5`, `description: 6` on a series. So a Mihon backup should read correctly on
 * those fields. It has not been tested against one, and that is a limit of the evidence
 * rather than a claim of equivalence.
 *
 * ## Three fields that are deliberately not mapped
 *
 * Each is understood well enough to be tempting and is left out for a reason.
 *
 * **Chapter {@see CHAPTER_LIST_POSITION}, a positional index.** It runs 1 to N per series in
 * the order the backup lists them, which is how the app chose to sort, not an order the
 * source published. {@see SeriesWriter::pair()} tries `sourceOrder` before
 * `number`, so mapping this would let a sort order quietly override a chapter number that is
 * definitely right, and a chapter that shifts position would take its read state with it. The
 * chapter number and the chapter name are mapped instead, and both survive re-sorting.
 *
 * **Chapter {@see CHAPTER_SECOND_FLAG}, a boolean.** It appears on exactly 1,616 of 2,911
 * chapters and is always 1, and those 1,616 are exactly the ones the read flag marks and
 * exactly the ones the history list names. So it is a real field and this file cannot
 * distinguish what it means: a backup where every read chapter is also bookmarked looks
 * identical to one where the writer copied the read flag into a second number. Importing it
 * as a bookmark would put bookmarks on a shelf this reader never bookmarked, so it is not
 * imported.
 *
 * **The timestamps, everywhere.** A series carries one at {@see SERIES_MILLIS}, a second at
 * {@see SERIES_SECONDS}, and its chapters carry two more. All four are within 91 seconds of
 * each other across all four series in the file, and the backup was taken at the end of that
 * window. Those are the moments the exporter ran, not the moments a reader added or read
 * anything. Chapter {@see CHAPTER_MILLIS} is the exception and is a real per chapter upload
 * date, 2,911 distinct values spread across two years, but nothing in this import pipeline
 * has a column to put it in.
 */
final class TachibkSchema
{
    /**
     * Not instantiated. The class holds the measured numbers and nothing else.
     */
    private function __construct() {}

    // ------------------------------------------------------------ the file itself

    /** The two bytes every gzip file starts with, which is the first thing this reader checks. */
    public const GZIP_MAGIC = "\x1f\x8b";

    // ------------------------------------------------------------ the top level

    /** One series. Repeated, and the only field that carries a reader's library. */
    public const BACKUP_SERIES = 1;

    /**
     * An extension record. Present once in the sample with the name "Default" and no package
     * anywhere in it, so there is nothing here that could identify an extension to install.
     */
    public const BACKUP_EXTENSION = 2;

    /**
     * One source, as `{name: 1, id: 2}`.
     *
     * The sample names one source for four series. It is the only place a source id gets a
     * human readable name, and without it an import could place a series but could not tell
     * a reader which of their sources it came from.
     */
    public const BACKUP_SOURCE = 101;

    // ------------------------------------------------------------ a series

    /**
     * The source this series came from, as a 64 bit id.
     *
     * 2327480808438768017 in the sample, which is past 2^53 and so would be rounded by
     * anything that decoded it as a float. It is a Suwayomi source id and this server uses
     * the same numbering, so it is carried through to be matched rather than resolved.
     */
    public const SERIES_SOURCE = 1;

    /** The series' own key on that source, for example `rSmmf`. */
    public const SERIES_URL = 2;

    /** The title the reader saw. */
    public const SERIES_TITLE = 3;

    /** The author's name. Read, and carried nowhere, because this import has no column for it. */
    public const SERIES_AUTHOR = 4;

    /** The artist's name. Same as {@see SERIES_AUTHOR}: read, not stored. */
    public const SERIES_ARTIST = 5;

    /** The description. Read, and not stored, for the same reason. */
    public const SERIES_DESCRIPTION = 6;

    /** One genre. Repeated 64 times on the first series in the sample. */
    public const SERIES_GENRE = 7;

    /** Publication status as a small number. */
    public const SERIES_STATUS = 8;

    /** A cover image URL. Read, and not stored. */
    public const SERIES_THUMBNAIL = 9;

    /** An exporter run time in milliseconds. Not a date the reader did anything. See the note above. */
    public const SERIES_MILLIS = 13;

    /** One chapter. Repeated, 2,911 times across the four series in the sample. */
    public const SERIES_CHAPTER = 16;

    /**
     * One reading history entry, as `{url: 1, at: 2}`.
     *
     * Not mapped onto anything. Its timestamps are the exporter's, and its chapter list
     * names exactly the chapters the read flag already marks, so it carries no fact this
     * reader does not already have from {@see CHAPTER_READ}. It is kept here because it is
     * what proved {@see CHAPTER_READ} was the read flag and not something else, and that
     * measurement is worth recording next to the number it settled.
     */
    public const SERIES_HISTORY = 104;

    /** An exporter run time in seconds. Not a date the reader did anything. */
    public const SERIES_SECONDS = 106;

    // ------------------------------------------------------------ a chapter

    /** The chapter's own key on the source, for example `rSmmf/2tqg5PYX`. */
    public const CHAPTER_URL = 1;

    /** The chapter title, which on most sources is the number again. */
    public const CHAPTER_NAME = 2;

    /** The scanlation group. Read, and not stored. */
    public const CHAPTER_SCANLATOR = 3;

    /**
     * Whether the reader had read this chapter.
     *
     * Present on 1,616 of 2,911 chapters in the sample and always 1, and those 1,616 are
     * exactly the chapters {@see SERIES_HISTORY} names, with no disagreement anywhere. Two
     * independent parts of the file saying the same thing is what makes this safe to trust,
     * and Mihon's own exporter declares field 4 as `read` independently of that.
     */
    public const CHAPTER_READ = 4;

    /** An exporter run time in milliseconds, repeated from the series. */
    public const CHAPTER_MILLIS = 7;

    /** A real per chapter date, in milliseconds, distinct on all 2,911 chapters. Never stored. */
    public const CHAPTER_UPLOAD_MILLIS = 8;

    /**
     * The chapter number, as a 32 bit float because it can be fractional.
     *
     * Decodes to 700.5 for "Chapter 700.5", 700 for "Chapter 700" and 699 for "Chapter 699"
     * in the sample, which is the strongest confirmation in the file that the numbers are
     * being read the way they were meant. Reading it as an integer instead loses every
     * decimal chapter, which on a source that numbers by volume is a large fraction of the
     * shelf and pairs nothing.
     */
    public const CHAPTER_NUMBER = 9;

    /**
     * A 1 based index in the backup's own listing order. Deliberately not mapped.
     *
     * It runs 1 to N per series and is the app's sort order rather than the source's, and
     * {@see SeriesWriter::pair()} tries a positional key before a chapter number.
     */
    public const CHAPTER_LIST_POSITION = 10;

    /**
     * A boolean that appears on exactly the read chapters and is always 1. Deliberately not
     * mapped, because this file cannot tell what it means. See the note above.
     */
    public const CHAPTER_SECOND_FLAG = 12;

    // ------------------------------------------------------------ a history entry

    /** The chapter key this history entry is about, matching {@see CHAPTER_URL}. */
    public const HISTORY_URL = 1;

    /** When, in milliseconds. The exporter's clock, not the reader's. */
    public const HISTORY_AT = 2;
}
