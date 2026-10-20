<?php

namespace App\Import\Tachimanga;

use App\Import\BackupReader;
use App\Import\BackupSource;
use App\Import\ImportedBackup;
use App\Import\ImportedChapter;
use App\Import\ImportedSeries;
use App\Import\ImportRefused;
use App\Support\CbzWriter;
use App\Support\ReadingState;
use App\Support\Suwayomi\Timestamps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PDO;
use PDOException;
use PDOStatement;
use ZipArchive;

/**
 * Reads a Tachimanga `.tmb` into the shape the import pipeline wants.
 *
 * The measured facts this depends on live in {@see TachimangaSchema}, and every one of
 * them was read out of a real 39.36MB backup rather than inferred from documentation.
 * This class is the plumbing that applies them.
 *
 * ## Nothing is read into memory as a string
 *
 * The archive is two zips deep and the inner one holds a 113.5MB SQLite file, against
 * this server's `memory_limit` of 128M. `ZipArchive::getFromName()` on that entry would
 * be 113MB of PHP string and would fail, so both layers are streamed to temp files with
 * `stream_copy_to_stream()` and the database is opened by path. Same reason
 * {@see CbzWriter} streams rather than buffering a whole CBZ.
 *
 * Both temp files are removed in a `finally`, including on a refusal, because a reader
 * who tries three files and gives up should not leave 150MB behind on each attempt.
 *
 * ## There is no zip-slip guard, on purpose
 *
 * The obvious one is missing here and that is deliberate. Zip-slip is what happens when
 * an archive-supplied name is used as a filesystem path. Nothing in this class does
 * that: every entry is fetched by a name this file wrote down, via `locateName()`, and
 * the only path ever written is a `tempnam()` this process created. So there is no
 * archive-controlled string reaching the filesystem, and a guard would be code that
 * cannot fail, which is worse than no code because it reads as a protection.
 *
 * ## Queries are prepared with named parameters, never built with sprintf
 *
 * An earlier version assembled these with `sprintf` and a positional list. It had one
 * more argument than placeholders, which PHP accepts silently, so `is_downloaded` was
 * substituted into the `FROM` clause and the failure surfaced as
 * `no such table: is_downloaded` against a table that exists. Named parameters cannot
 * misalign like that, and they name themselves at the point of binding.
 *
 * ## Nothing is written to this application
 *
 * This reader's whole job is to describe what was in the file. The library is only
 * touched in a later phase, through {@see ReadingState}, so a reader can
 * run this and get a report without any consequence.
 *
 * ## Soft-delete flags are ignored, deliberately
 *
 * `History.is_delete` reads 1 on 3,571 rows and 0 on 1,086, and it exists on only five
 * of the twenty tables: not on `Manga`, not on `Chapter`. Both values carry rows with a
 * live resume pointer, so 1 cannot mean "deleted" in any way that matters here, and the
 * column is too inconsistent to infer a convention from.
 *
 * Filtering on it would silently drop the resume point of 340 library series for no
 * reason a reader could see or act on. So it is not filtered, and the meaning is left
 * as unknown rather than guessed. A second backup would settle it.
 */
final class TachimangaReader implements BackupReader
{
    /**
     * The last date a backup can plausibly describe.
     *
     * Every app that writes these shipped after 2015, so anything before 2000 is not a
     * real reading date. It is the signature of a unit mistake: `Manga.in_library_at` is
     * seconds while `Category.create_at` is milliseconds, and reading the first as the
     * second yields 1970-01-21. A date that wrong does not look wrong in a UI, which is
     * why it is refused rather than written.
     */
    private const EARLIEST_PLAUSIBLE_YEAR = 2000;

    /**
     * @param  Timestamps  $timestamps  Reused rather than reimplemented. See {@see TachimangaSchema::UNITS}.
     * @param  string|null  $temporaryDirectory  Where to stage the archive, for a server whose system temp is small.
     */
    public function __construct(
        private readonly Timestamps $timestamps,
        private readonly ?string $temporaryDirectory = null,
    ) {}

    public function name(): string
    {
        return 'tachimanga';
    }

    /**
     * Both required entries, not just the ZIP magic.
     *
     * A `PK\x03\x04` header alone would claim every ZIP on the disk, and this server
     * writes CBZ exports that are ZIPs. Requiring `meta.json` and `contents.zip` is what
     * makes the answer specific.
     *
     * Never throws: a caller tries each registered reader in turn, so this runs against
     * files that are not backups at all.
     */
    public function recognises(string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $zip = new ZipArchive;

        /*
         * `$zip->close()` on an archive that never opened throws a ValueError, so the
         * open is tested before the close is reached rather than inside a `finally`.
         * Returning out of a `try` still runs its `finally`, so an earlier version that
         * closed unconditionally died on every file that was not a zip at all.
         */
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            return $zip->locateName(TachimangaSchema::META_ENTRY) !== false
                && $zip->locateName(TachimangaSchema::CONTENTS_ENTRY) !== false;
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws ImportRefused
     */
    public function read(string $path): ImportedBackup
    {
        if (! $this->recognises($path)) {
            throw new ImportRefused(
                'That file is not a Tachimanga backup. It has to be the .tmb that '
                .'Tachimanga writes from its own backup screen.'
            );
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportRefused('That backup could not be opened. It may be damaged or truncated.');
        }

        $staged = [];

        try {
            $version = $this->version($zip);

            $contents = $this->stage($zip, TachimangaSchema::CONTENTS_ENTRY, $staged);
            $zip->close();

            $inner = new ZipArchive;

            if ($inner->open($contents) !== true) {
                throw new ImportRefused('The inside of that backup could not be opened. Try exporting it again.');
            }

            try {
                if ($inner->locateName(TachimangaSchema::DATABASE_ENTRY) === false) {
                    throw new ImportRefused(
                        'That backup has no library database in it, so there is nothing to read. '
                        .'Try exporting it again.'
                    );
                }

                $database = $this->stage($inner, TachimangaSchema::DATABASE_ENTRY, $staged);
            } finally {
                $inner->close();
            }

            return $this->readDatabase($database, $version);
        } finally {
            // On every path, including a refusal. Three refused attempts must not leave
            // three sets of temp files behind.
            foreach ($staged as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * The format's own version, gated before anything is staged.
     *
     * Gated here rather than after extraction because a newer backup can carry columns
     * this reader has never seen, and reading it optimistically is how a reader gets
     * 1,277 series and no read state.
     *
     * @throws ImportRefused
     */
    private function version(ZipArchive $zip): int
    {
        $raw = $zip->getFromName(TachimangaSchema::META_ENTRY);

        if ($raw === false) {
            throw new ImportRefused('That backup is missing the file that describes its version.');
        }

        $meta = json_decode($raw, true);
        $version = is_array($meta) ? (int) ($meta['version'] ?? 0) : 0;

        if ($version < TachimangaSchema::KNOWN_VERSION) {
            throw new ImportRefused(
                "That backup is version {$version} and this server was written for "
                .TachimangaSchema::KNOWN_VERSION.'. It is probably from an older release, '
                .'and this server may not read it correctly.'
            );
        }

        if ($version > TachimangaSchema::MAXIMUM_ACCEPTED_VERSION) {
            throw new ImportRefused(
                "That backup is version {$version}, which is newer than this server understands "
                .'(up to '.TachimangaSchema::MAXIMUM_ACCEPTED_VERSION.'). Update Papyrus, or ask on the forum.'
            );
        }

        return $version;
    }

    /**
     * Copy one entry to a temp file and remember it for cleanup.
     *
     * @param  list<string>  $staged  Collected by reference so the caller's `finally` removes them.
     *
     * @throws ImportRefused
     */
    private function stage(ZipArchive $zip, string $entry, array &$staged): string
    {
        $stream = $zip->getStream($entry);

        if ($stream === false) {
            throw new ImportRefused("That backup is missing {$entry}, so it cannot be read.");
        }

        $target = tempnam($this->temporaryDirectory ?? sys_get_temp_dir(), 'papyrus-import-');

        if ($target === false) {
            fclose($stream);

            throw new ImportRefused(
                'There is no room to unpack that backup. Free up some disk space and try again.'
            );
        }

        $staged[] = $target;

        $out = fopen($target, 'wb');

        if ($out === false) {
            fclose($stream);

            throw new ImportRefused('There was a problem unpacking that backup. Try again.');
        }

        try {
            stream_copy_to_stream($stream, $out);
        } finally {
            fclose($stream);
            fclose($out);
        }

        return $target;
    }

    /**
     * @throws ImportRefused
     */
    private function readDatabase(string $path, int $version): ImportedBackup
    {
        $pdo = $this->connect($path);

        $this->assertSchema($pdo);

        $series = $this->librarySeries($pdo);
        $chapters = $this->chaptersByManga($pdo);
        $resumes = $this->resumePoints($pdo);

        $built = $series->map(function (array $row) use ($chapters, $resumes): ImportedSeries {
            $id = (int) $row['id'];
            $resume = $resumes[$id] ?? null;

            return new ImportedSeries(
                backupId: $id,
                sourceId: (int) $row['source'],
                url: ImportedSeries::normaliseUrl((string) $row['url']),
                title: (string) ($row['title'] ?? ''),
                addedAt: $this->date($row['added_at'] ?? null),
                lastReadAt: $resume['read_at'] ?? null,
                backupResumeChapterId: $resume['chapter_id'] ?? null,
                chapters: $chapters[$id] ?? new Collection,
            );
        });

        return new ImportedBackup(
            format: $this->name(),
            schemaVersion: $version,
            series: $built,
            categories: $this->categories($pdo),
            totalInFile: (int) $this->query($pdo, 'select count(*) from '.$this->t(TachimangaSchema::MANGA_TABLE))->fetchColumn(),
            sources: $this->sources($pdo),
        );
    }

    /**
     * Run a query that takes no parameters, turning a driver failure into a refusal.
     *
     * `PDO::query()` is typed `PDOStatement|false`. With `ERRMODE_EXCEPTION` set it raises
     * rather than returning false, so this is not expected to fire, and that is exactly
     * why it is worth a sentence if it ever does: a false return would otherwise reach a
     * `->fetchColumn()` call on a bool and surface as a TypeError naming an internal
     * method rather than as anything about the reader's backup.
     *
     * @throws ImportRefused
     */
    private function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);

        if ($statement === false) {
            throw new ImportRefused('That backup could not be queried. It may be damaged.');
        }

        return $statement;
    }

    /**
     * Prepare and execute a query with no parameters, returning the statement.
     *
     * Separate from {@see self::query()} because this one is iterated rather than fetched,
     * and typing each of them twice would be noise. Both refuse rather than return false,
     * for the reason {@see self::query()} gives.
     *
     * @throws ImportRefused
     */
    private function statement(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);

        if ($statement === false) {
            throw new ImportRefused('That backup could not be read. It may be damaged.');
        }

        return $statement;
    }

    /**
     * Quote an identifier.
     *
     * These are constants from {@see TachimangaSchema} rather than anything a file or a
     * request supplied, so this is belt and braces against a future edit adding a value
     * from outside. It costs one line and removes the need to reason about it.
     */
    private function t(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * @throws ImportRefused
     */
    private function connect(string $path): PDO
    {
        try {
            $pdo = new PDO('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException) {
            throw new ImportRefused(
                'The library database inside that backup could not be opened. '
                .'It may be from a newer version of the app than this server reads.'
            );
        }

        /*
         * The connection is read-only for the life of the read.
         *
         * PDO's SQLite driver takes URI filenames inconsistently across builds, so the
         * guarantee is made here instead of in the DSN. A temp file opened read-write will
         * create a journal beside it on the first write, and a stray 113MB copy of
         * somebody's library surviving a crash is worth one statement to avoid.
         */
        $pdo->exec('PRAGMA query_only = ON');

        return $pdo;
    }

    /**
     * Check the schema before reading it, columns as well as tables.
     *
     * SQLite's message for a missing column is `no such table: <the column name>`, which
     * names a table that exists and misleads whoever reads it. So every column this
     * reader binds is checked up front and the refusal says what is missing in words.
     *
     * @throws ImportRefused
     */
    private function assertSchema(PDO $pdo): void
    {
        $required = [
            TachimangaSchema::MANGA_TABLE => [
                TachimangaSchema::MANGA_ID_COLUMN,
                TachimangaSchema::MANGA_SOURCE_COLUMN,
                TachimangaSchema::MANGA_URL_COLUMN,
                TachimangaSchema::MANGA_TITLE_COLUMN,
                TachimangaSchema::MANGA_IN_LIBRARY_AT_COLUMN,
                TachimangaSchema::IN_LIBRARY_COLUMN,
            ],
            TachimangaSchema::CHAPTER_TABLE => [
                TachimangaSchema::CHAPTER_ID_COLUMN,
                TachimangaSchema::CHAPTER_MANGA_COLUMN,
                TachimangaSchema::CHAPTER_NUMBER_COLUMN,
                TachimangaSchema::CHAPTER_NAME_COLUMN,
                TachimangaSchema::CHAPTER_SOURCE_ORDER_COLUMN,
                TachimangaSchema::CHAPTER_READ_COLUMN,
                TachimangaSchema::CHAPTER_BOOKMARK_COLUMN,
                TachimangaSchema::CHAPTER_LAST_PAGE_COLUMN,
                TachimangaSchema::CHAPTER_DOWNLOADED_COLUMN,
            ],
            TachimangaSchema::HISTORY_TABLE => [
                TachimangaSchema::HISTORY_MANGA_COLUMN,
                TachimangaSchema::HISTORY_LAST_CHAPTER_COLUMN,
                TachimangaSchema::HISTORY_LAST_READ_AT_COLUMN,
            ],
            TachimangaSchema::CATEGORY_TABLE => [
                TachimangaSchema::CATEGORY_NAME_COLUMN,
            ],
            TachimangaSchema::CATEGORY_MANGA_TABLE => ['category', 'manga'],
        ];

        $problems = [];

        foreach ($required as $table => $columns) {
            // An absent table reports every column absent, which is the honest report.
            $present = array_column(
                $this->query($pdo, 'pragma table_info('.$this->t($table).')')->fetchAll(),
                'name',
            );

            foreach (array_diff($columns, $present) as $missing) {
                $problems[] = $table.'.'.$missing;
            }
        }

        if ($problems !== []) {
            throw new ImportRefused(
                'That backup does not have the '.implode(', ', $problems).' '
                .(count($problems) === 1 ? 'column' : 'columns')
                .' this server needs to read it. It is probably from a different version '
                .'of the app than this server understands.'
            );
        }
    }

    /**
     * The reader's shelf, and nothing else.
     *
     * The `in_library = 1` filter is the single most consequential line in this reader.
     * The measured file holds 132,185 manga rows of which 1,277 are the library; the
     * rest is the catalogue the app cached while browsing. Without the filter an import
     * files a reader's entire browsing history as their shelf.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function librarySeries(PDO $pdo): Collection
    {
        $statement = $pdo->prepare(sprintf(
            'select %1$s as id, %2$s as source, %3$s as url, %4$s as title, %5$s as added_at
             from %6$s where %7$s = :on_the_shelf',
            $this->t(TachimangaSchema::MANGA_ID_COLUMN),
            $this->t(TachimangaSchema::MANGA_SOURCE_COLUMN),
            $this->t(TachimangaSchema::MANGA_URL_COLUMN),
            $this->t(TachimangaSchema::MANGA_TITLE_COLUMN),
            $this->t(TachimangaSchema::MANGA_IN_LIBRARY_AT_COLUMN),
            $this->t(TachimangaSchema::MANGA_TABLE),
            $this->t(TachimangaSchema::IN_LIBRARY_COLUMN),
        ));

        // Bound as a named parameter, so the value cannot be confused with a column.
        $statement->execute(['on_the_shelf' => 1]);

        return new Collection($statement->fetchAll());
    }

    /**
     * Chapters for the library only, grouped by manga, in one query.
     *
     * 54,870 rows measured across the 1,277 library series, largest series 633. One
     * query rather than one per series: a loop of 1,277 selects against a 113MB file is
     * the kind of thing that reads as "the import is stuck" at 1,200.
     *
     * `is_delete` is not filtered. See the class docblock for why.
     *
     * @return Collection<int, Collection<int, ImportedChapter>>
     */
    private function chaptersByManga(PDO $pdo): Collection
    {
        $c = TachimangaSchema::CHAPTER_TABLE;
        $m = TachimangaSchema::MANGA_TABLE;

        $statement = $pdo->prepare(
            'select c.'.$this->t(TachimangaSchema::CHAPTER_ID_COLUMN).' as id,
                    c.'.$this->t(TachimangaSchema::CHAPTER_MANGA_COLUMN).' as manga,
                    c.'.$this->t(TachimangaSchema::CHAPTER_NUMBER_COLUMN).' as number,
                    c.'.$this->t(TachimangaSchema::CHAPTER_NAME_COLUMN).' as name,
                    c.'.$this->t(TachimangaSchema::CHAPTER_SOURCE_ORDER_COLUMN).' as source_order,
                    c.'.$this->t(TachimangaSchema::CHAPTER_READ_COLUMN).' as is_read,
                    c.'.$this->t(TachimangaSchema::CHAPTER_BOOKMARK_COLUMN).' as is_bookmarked,
                    c.'.$this->t(TachimangaSchema::CHAPTER_LAST_PAGE_COLUMN).' as last_page,
                    c.'.$this->t(TachimangaSchema::CHAPTER_DOWNLOADED_COLUMN).' as is_downloaded
             from '.$this->t($c).' c
             join '.$this->t($m).' m on m.'.$this->t(TachimangaSchema::MANGA_ID_COLUMN).' = c.'.$this->t(TachimangaSchema::CHAPTER_MANGA_COLUMN).'
             where m.'.$this->t(TachimangaSchema::IN_LIBRARY_COLUMN).' = :on_the_shelf
             order by c.'.$this->t(TachimangaSchema::CHAPTER_MANGA_COLUMN).', c.'.$this->t(TachimangaSchema::CHAPTER_ID_COLUMN)
        );

        $statement->execute(['on_the_shelf' => 1]);

        /** @var Collection<int, Collection<int, ImportedChapter>> $grouped */
        $grouped = new Collection;

        foreach ($statement as $row) {
            $manga = (int) $row['manga'];

            if (! $grouped->has($manga)) {
                $grouped->put($manga, new Collection);
            }

            $grouped->get($manga)->push(new ImportedChapter(
                backupId: (int) $row['id'],
                number: $this->number($row['number']),
                name: $this->text($row['name']),
                sourceOrder: $this->intOrNull($row['source_order']),
                isRead: (bool) $row['is_read'],
                isBookmarked: (bool) $row['is_bookmarked'],
                lastPageRead: (int) ($row['last_page'] ?? 0),
                isDownloaded: (bool) ($row['is_downloaded'] ?? false),
            ));
        }

        return $grouped;
    }

    /**
     * One resume point per library series, keyed by the backup's own manga id.
     *
     * `History.manga_id`, not `History.manga` as the sibling tables use. Measured, and it
     * is the kind of difference a guess gets wrong with an error naming a column that
     * does not exist, which is at least honest.
     *
     * `UNIQUE(manga_id)` means at most one row per series, so a plain lookup is correct
     * and no "latest wins" comparison is needed.
     *
     * @return array<int, array{chapter_id: int|null, read_at: CarbonImmutable|null}>
     */
    private function resumePoints(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            'select h.'.$this->t(TachimangaSchema::HISTORY_MANGA_COLUMN).' as manga,
                    h.'.$this->t(TachimangaSchema::HISTORY_LAST_CHAPTER_COLUMN).' as chapter_id,
                    h.'.$this->t(TachimangaSchema::HISTORY_LAST_READ_AT_COLUMN).' as read_at
             from '.$this->t(TachimangaSchema::HISTORY_TABLE).' h
             join '.$this->t(TachimangaSchema::MANGA_TABLE).' m
               on m.'.$this->t(TachimangaSchema::MANGA_ID_COLUMN).' = h.'.$this->t(TachimangaSchema::HISTORY_MANGA_COLUMN).'
             where m.'.$this->t(TachimangaSchema::IN_LIBRARY_COLUMN).' = :on_the_shelf'
        );

        $statement->execute(['on_the_shelf' => 1]);

        $points = [];

        foreach ($statement as $row) {
            $points[(int) $row['manga']] = [
                'chapter_id' => $this->intOrNull($row['chapter_id']),
                'read_at' => $this->date($row['read_at']),
            ];
        }

        return $points;
    }

    /**
     * Category names with their shelf members, as the file links them.
     *
     * `CategoryManga.manga` joins `Manga.id`, the backup's own local id, measured on the
     * real file: 146 rows, every one resolving to a Manga row, none of them orphaned. Only
     * members whose series are on the shelf come back, which is what the reader app's own
     * category pills count: the file also carries members on browse-cached series
     * (Youtube 16, Snowflakes 20 of the 146 measured) and those have no place to land.
     *
     * The name is carried as the reader typed it, trailing space included; landing trims
     * it, because a shelf whose name ends in a space is a shelf the reader cannot type.
     *
     * ## If a shelf ends up with fewer members than it held
     *
     * That is the per-item report's job, not this read's: the import says which members
     * landed and which were skipped with their reasons. This method only carries what the
     * file links.
     *
     * @return Collection<string, Collection<int, int>> Category name to the backup's own manga ids on it.
     */
    private function categories(PDO $pdo): Collection
    {
        /*
         * Plain arrays first. A Collection grown by `put` hides its key and value
         * types from static analysis (the keys it cannot name end up inferred as
         * *NEVER*), while the finished shape is documented on this method.
         */
        /** @var array<string, list<int>> $shelfMembers the shelf by name to the members it carries, the same shape the reader carries to the landing pass */
        $shelfMembers = [];

        $nameById = [];

        foreach ($this->statement($pdo, 'select '.$this->t('id').', '.$this->t(TachimangaSchema::CATEGORY_NAME_COLUMN).' from '.$this->t(TachimangaSchema::CATEGORY_TABLE).' order by '.$this->t('id')) as $category) {
            $name = (string) $category[TachimangaSchema::CATEGORY_NAME_COLUMN];
            $shelfMembers[$name] = [];
            $nameById[(int) $category['id']] = $name;
        }

        $m = TachimangaSchema::MANGA_TABLE;

        $members = $pdo->prepare(
            'select cm.'.$this->t('category').' as category, cm.'.$this->t('manga').' as manga
             from '.$this->t(TachimangaSchema::CATEGORY_MANGA_TABLE).' cm
             join '.$this->t($m).' m on m.'.$this->t(TachimangaSchema::MANGA_ID_COLUMN).' = cm.'.$this->t('manga').'
             where m.'.$this->t(TachimangaSchema::IN_LIBRARY_COLUMN).' = :on_the_shelf'
        );

        $members->execute(['on_the_shelf' => 1]);

        foreach ($members as $row) {
            $name = $nameById[(int) $row['category']] ?? null;

            if ($name !== null) {
                $shelfMembers[$name][] = (int) $row['manga'];
            }
        }

        /*
         * The finished shape is what the import reads, so the plain arrays are
         * mapped over rather than built into a Collection one `put` at a time.
         * The `Deduplicator` builds its groups the same way, and the file's own
         * order rides the arrays into the collections.
         */

        return (new Collection($shelfMembers))->map(
            /** @param list<int> $members */
            fn (array $members): Collection => new Collection($members),
        );
    }

    /**
     * Every source the shelf sits on, with the package that identifies it.
     *
     * Three hops, and two of them look wrong if you guess: `Source.extension` holds a number
     * rather than a package name, and the packages live in `Extension.pkg_name`. An earlier
     * join looked for a package on `Source`, found nothing, and concluded the backup carried
     * no extension information at all.
     *
     * Only sources carrying shelf series are returned. The file names 413 sources, which is
     * every source it has ever seen, and driving an install from that list would be an action
     * the reader did not ask for. This returns the 56, and of those 52 resolve to a package.
     *
     * @return Collection<int, BackupSource>
     */
    private function sources(PDO $pdo): Collection
    {
        $sql = sprintf(
            'select s.%1$s as source_id, s.%2$s as name, e.%3$s as package,
                    e.%4$s as is_obsolete, e.%5$s as version_name, count(m.id) as series
             from %6$s m
             join %7$s s on s.%1$s = m.%8$s
             left join %9$s e on e.%10$s = s.%11$s
             where m.%12$s = 1
             group by s.%1$s, s.%2$s, e.%3$s, e.%4$s, e.%5$s
             order by series desc',
            $this->t(TachimangaSchema::SOURCE_ID_COLUMN),
            $this->t(TachimangaSchema::SOURCE_NAME_COLUMN),
            $this->t(TachimangaSchema::EXTENSION_PACKAGE_COLUMN),
            $this->t(TachimangaSchema::EXTENSION_OBSOLETE_COLUMN),
            $this->t(TachimangaSchema::EXTENSION_VERSION_COLUMN),
            $this->t(TachimangaSchema::MANGA_TABLE),
            $this->t(TachimangaSchema::SOURCE_TABLE),
            $this->t(TachimangaSchema::MANGA_SOURCE_COLUMN),
            $this->t(TachimangaSchema::EXTENSION_TABLE),
            $this->t(TachimangaSchema::EXTENSION_ID_COLUMN),
            $this->t(TachimangaSchema::SOURCE_EXTENSION_COLUMN),
            $this->t(TachimangaSchema::IN_LIBRARY_COLUMN),
        );

        $sources = new Collection;

        foreach ($this->statement($pdo, $sql) as $row) {
            $package = $row['package'] === null ? null : (string) $row['package'];

            $sources->push(new BackupSource(
                sourceId: (int) $row['source_id'],
                package: $package,
                seriesCount: (int) $row['series'],
                obsolete: (bool) $row['is_obsolete'],
                name: $row['name'] === null ? null : (string) $row['name'],
                versionName: $row['version_name'] === null ? null : (string) $row['version_name'],
            ));
        }

        return $sources;
    }

    /**
    /**
     * Read one date, refusing one that says the unit was wrong.
     *
     * {@see Timestamps::fromEpochAuto()} detects the unit from the magnitude at a 1e11
     * cutoff and classified all six measured columns correctly, so it is reused rather
     * than reimplemented. The refusal is added here rather than in that class because it
     * is an import-specific sanity check and not a fact about Suwayomi's wire format,
     * which is what that class documents.
     *
     * @throws ImportRefused
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        $date = $this->timestamps->fromEpochAuto($value);

        if ($date === null) {
            return null;
        }

        if ($date->year < self::EARLIEST_PLAUSIBLE_YEAR) {
            throw new ImportRefused(
                "A date in that backup reads as {$date->toDateString()}, which is before any of "
                .'these reader apps existed. That means the backup is using a timestamp format '
                .'this server does not recognise, and importing it would put wrong dates on '
                .'your library. Nothing has been changed.'
            );
        }

        return $date;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * SQLite hands back empty strings where a chapter has no name of its own.
     *
     * Both matter: an empty title renders as a blank row, and a chapter name of "" would
     * otherwise count as "has a name" in {@see ImportedChapter::isPairable()}.
     */
    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
