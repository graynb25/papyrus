<?php

namespace App\Import\Tachimanga;

use App\Import\ImportedSeries;
use App\Support\Suwayomi\Timestamps;

/**
 * The measured shape of a Tachimanga `.tmb`, as data rather than as code.
 *
 * Everything here was read out of a real backup produced by Tachimanga 1.4.3 on an iOS
 * device: `Tachimanga_backup_2026-10-08_18-31-01-290.tmb`, 39.36MB, containing a 113.5MB
 * SQLite file. Keeping it in one class means a second sample either confirms these or
 * corrects one line, instead of scattering corrections through the reader.
 *
 * ## The file is not encrypted
 *
 * Worth stating because it was a live question, and because the answer removes a whole
 * category of work. Byte zero of a `.tmb` is `50 4B 03 04`, a ZIP local file header, and
 * `ZipArchive` opens it with no key anywhere in the path.
 *
 * ## Two nested archives, which is the part a naive reader trips on
 *
 * ```
 * backup.tmb                          a ZIP
 * |-- meta.json                       {"version": 520, "checksum": "...", "size": ...}
 * `-- contents.zip                    a ZIP inside the ZIP
 *     |-- tachimanga.db                113.5MB, SQLite, the library
 *     |-- pref.json, pref-all.json
 *     |-- prefs/*.plist                binary, per source
 *     `-- extensions/*.jar             the installed extensions
 * ```
 *
 * A reader that opens the outer archive and looks for `tachimanga.db` finds nothing,
 * because it is one level down.
 *
 * ## The prefs can be ignored entirely
 *
 * The brief assumed the reading state lived in `prefs/`. It does not.
 * `prefs/suwayomi.tachidesk.source_<id>.plist` is named by **source** id, 413 of them
 * against 279 sources, and holds per-source app settings: `pref_use_chapters_api`,
 * `contentRating_en`, `blockedGroups_en`, `hide_locked`, `slug_map_v2`. None of those
 * have anywhere to go here, searched and found absent: `content_rating` 0 occurrences,
 * `blockedGroups` 0, `blockedUploader` 0, `usePort443` 0, `slug_map` 0, `hide_locked` 0.
 *
 * So this reader skips them, which is the largest scope reduction available and it is
 * available because the data turned out not to be there. It also means no binary plist
 * reader is needed, which matters: PHP has no built-in one and this project has no plist
 * package. `pref-all.json` is iOS device preferences (`AppleLocale`, `AppleKeyboards`),
 * so this backup came from an iPhone, and the core data is cross-platform regardless.
 */
final class TachimangaSchema
{
    /** The outer entry describing the archive. */
    public const META_ENTRY = 'meta.json';

    /** The inner archive. The database is inside this, not beside it. */
    public const CONTENTS_ENTRY = 'contents.zip';

    /** The SQLite file, inside the inner archive. */
    public const DATABASE_ENTRY = 'tachimanga.db';

    /** The version this reader was written against and measured on. */
    public const KNOWN_VERSION = 520;

    /**
     * Versions above this are refused rather than guessed at.
     *
     * Tachimanga's `version` moves with app releases, and a newer backup can carry tables
     * this reader has never seen. Reading it optimistically is how a reader gets 1,277
     * series and no read state, because the chapter columns moved. Refusing is a sentence
     * they can act on; a half-read library is not.
     *
     * Set deliberately loose. The check that matters is the floor, which is a format this
     * reader was measured against; the ceiling exists to catch a version far enough ahead
     * to be a different schema, not to track releases.
     */
    public const MAXIMUM_ACCEPTED_VERSION = 600;

    /**
     * The table that holds the reader's library, and the flag that separates it from cache.
     *
     * `select *` on this table returns 132,185 rows of which 1,277 are the library. The
     * other 131,908 are what the app cached while browsing, and importing them would put
     * a reader's entire browsing history onto their shelf with no way to tell afterwards.
     */
    public const MANGA_TABLE = 'Manga';

    /** Boolean. 1 is on the shelf. Not `CategoryManga`, which has only the 2 named categories. */
    public const IN_LIBRARY_COLUMN = 'in_library';

    /** The local autoincrement. Meaningless outside the backup. */
    public const MANGA_ID_COLUMN = 'id';

    /** A source id shared with Suwayomi. Over 2^53, so never JSON-encode as a number. */
    public const MANGA_SOURCE_COLUMN = 'source';

    /** The source-relative path, and the join key. Always populated; `real_url` often is not. */
    public const MANGA_URL_COLUMN = 'url';

    /** Present on some rows and useless as a key. See {@see ImportedSeries}. */
    public const MANGA_REAL_URL_COLUMN = 'real_url';

    public const MANGA_TITLE_COLUMN = 'title';

    /** The sources a series can sit on. `extension` is a foreign key, not a package name. */
    public const SOURCE_TABLE = 'Source';

    public const SOURCE_ID_COLUMN = 'id';

    public const SOURCE_NAME_COLUMN = 'name';

    public const SOURCE_EXTENSION_COLUMN = 'extension';

    /** The catalogue of extensions, one row per installed or known extension. */
    public const EXTENSION_TABLE = 'Extension';

    public const EXTENSION_ID_COLUMN = 'id';

    /** What Suwayomi identifies an extension by. This is the install key. */
    public const EXTENSION_PACKAGE_COLUMN = 'pkg_name';

    public const EXTENSION_VERSION_COLUMN = 'version_name';

    /**
     * The reader's own app marked this extension obsolete, so the source has gone.
     *
     * Measured on a real backup: 7 of the 52 library sources carry this, holding 178 series
     * that no amount of installing brings back. It is a fact about the source rather than
     * about this server's configuration, which is why it is kept apart from "not installed".
     */
    public const EXTENSION_OBSOLETE_COLUMN = 'is_obsolete';

    public const MANGA_IN_LIBRARY_AT_COLUMN = 'in_library_at';

    public const CHAPTER_TABLE = 'Chapter';

    /** Local autoincrement, like {@see MANGA_ID_COLUMN}. */
    public const CHAPTER_ID_COLUMN = 'id';

    /** Which manga a chapter belongs to. The only way back to {@see MANGA_ID_COLUMN}. */
    public const CHAPTER_MANGA_COLUMN = 'manga';

    public const CHAPTER_NUMBER_COLUMN = 'chapter_number';

    public const CHAPTER_NAME_COLUMN = 'name';

    /** The position on the source site, and therefore the pairing key across apps. */
    public const CHAPTER_SOURCE_ORDER_COLUMN = 'source_order';

    public const CHAPTER_READ_COLUMN = 'read';

    public const CHAPTER_BOOKMARK_COLUMN = 'bookmark';

    public const CHAPTER_LAST_PAGE_COLUMN = 'last_page_read';

    public const CHAPTER_DOWNLOADED_COLUMN = 'is_downloaded';

    public const HISTORY_TABLE = 'History';

    /**
     * One resume point per manga.
     *
     * `UNIQUE(manga_id)`, so this table is at most one row per series. Its
     * `last_chapter_id` is a **local** chapter id, which is why importing it unmodified
     * would point a reader at whatever chapter happens to hold that number in this
     * server, and every reader would silently resume at chapter one.
     */
    /**
     * Which manga a resume point belongs to.
     *
     * `manga_id`, **not** `manga` as the sibling tables use. Measured, and it is the kind
     * of difference that a guess gets wrong with an error message naming a column that
     * does not exist, which is at least honest.
     */
    public const HISTORY_MANGA_COLUMN = 'manga_id';

    public const HISTORY_LAST_CHAPTER_COLUMN = 'last_chapter_id';

    public const HISTORY_LAST_READ_AT_COLUMN = 'last_read_at';

    public const CATEGORY_TABLE = 'Category';

    public const CATEGORY_NAME_COLUMN = 'name';

    public const CATEGORY_MANGA_TABLE = 'CategoryManga';

    /**
     * Timestamp columns and the unit each one is in.
     *
     * ## The schema mixes units and nothing marks which is which
     *
     * Measured on the real file:
     *
     * | Column                  | Sample        | Unit          |
     * |-------------------------|---------------|---------------|
     * | `Manga.in_library_at`   | `1758301032`  | **seconds**   |
     * | `Manga.update_at`       | `1758302119418` | milliseconds |
     * | `History.last_read_at`  | `1758301351`  | **seconds**   |
     * | `Chapter.last_read_at`  | `1758302065`  | **seconds**   |
     * | `Chapter.date_upload`   | `1726412400000` | milliseconds |
     * | `Category.create_at`    | `1759823412610` | milliseconds |
     *
     * `Manga.in_library_at` being seconds is the one that would ship. Read as
     * milliseconds it renders as 1970-01-21, and **a date 56 years early does not look
     * obviously wrong in a UI**. Nobody notices a wrong date; they notice a missing one.
     *
     * Worth recording that the third-party converter at
     * github.com/getBoolean/manga_backup_converter reads `inLibraryAt`, `dateUpload` and
     * `lastReadAt` all through `DateTime.fromMillisecondsSinceEpoch`, which puts two of
     * those three in January 1970 on this file. Its format unpacking is a good reference.
     * Its dates are not, and should not be ported.
     *
     * ## Why this map exists when a magnitude check would do
     *
     * {@see Timestamps::fromEpochAuto()} detects the unit from the
     * magnitude, at a 1e11 cutoff, and correctly classifies all six columns above. It is
     * used for the reading. This map is kept anyway, for one reason: it is the record of
     * which unit each column *was measured as*, so a value that arrives in an unexpected
     * range can be refused rather than reinterpreted, and a future format can say what it
     * means instead of being guessed at.
     */
    public const UNITS = [
        'Manga.in_library_at' => 'second',
        'History.last_read_at' => 'second',
        'Chapter.last_read_at' => 'second',
        'Chapter.date_upload' => 'milli',
        'Category.create_at' => 'milli',
    ];
}
