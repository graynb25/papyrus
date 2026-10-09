<?php

namespace Tests\Support;

use App\Import\ImportedSeries;
use PDO;
use ZipArchive;

/**
 * Builds a synthetic Tachimanga `.tmb` for tests.
 *
 * ## Why not the real file
 *
 * There is a real 39.36MB backup in the repository folder, and reading it in a test would
 * be the most honest check available. It is also a 39MB dependency in a unit test, it takes
 * 0.7 seconds, and it is not committed. So this builds the shape instead, and the real file
 * is exercised by a separate probe that reports rather than asserts.
 *
 * The schema is the measured one, column for column, including the parts that are easy to
 * get wrong: `History.manga_id` where the sibling tables say `manga`, `in_library_at` in
 * seconds while `Category.create_at` is in milliseconds, and `source_order` present and
 * unique on every chapter.
 *
 * ## Knobs, not arguments
 *
 * Every way a backup can be unreadable is a named method rather than a boolean argument,
 * so a test says `->withVersion(900)` instead of `->build(900, false, false)`. A reader
 * reading these can tell what is being tested without cross-referencing the signature.
 */
trait BuildsTachimangaBackups
{
    /** The measured version of the format this reader was written against. */
    protected int $version = 520;

    /** Omit the inner archive entirely, as a truncated export would. */
    protected bool $withoutContents = false;

    /** Omit the database from inside the inner archive. */
    protected bool $withoutDatabase = false;

    /** Build the archive but leave out a table the reader requires. */
    protected bool $withoutHistoryTable = false;

    /** Build the archive but leave out a column the reader binds. */
    protected bool $withoutSourceOrder = false;

    /**
     * Write a date that reads before 2000 once a seconds column is read as milliseconds.
     *
     * The whole point of the mixed-unit hazard: `1758301032` read as milliseconds is
     * 1970-01-21, and a reader who sees that in their library has no reason to know why.
     *
     * @var array<string, int|null>
     */
    protected array $timestamps = [
        'in_library_at' => 1_758_301_032,      // seconds, measured
        'chapter_last_read_at' => 1_758_302_065, // seconds, measured
        'category_create_at' => 1_759_823_412_610, // milliseconds, measured
    ];

    /**
     * Build a backup file and return its path. The caller deletes it.
     */
    protected function buildTachimangaBackup(string $path = ''): string
    {
        $path = $path !== '' ? $path : $this->temporaryPath('tmb');

        $database = $this->buildTachimangaDatabase();
        $inner = $this->temporaryPath('contents');

        $zip = new ZipArchive;
        $zip->open($inner, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if (! $this->withoutDatabase) {
            $zip->addFile($database, 'tachimanga.db');
        }

        // `pref.json` is a real entry in the real archive, and adding it unconditionally
        // means the inner zip is valid even in the no-database fixture. A zip built with
        // zero entries writes no file at all, which made the outer `addFile` fail on a
        // path that did not exist, and the fixture bug looked like a reader bug.
        $zip->addFromString('pref.json', '{}');

        $zip->close();
        @unlink($database);

        $outer = new ZipArchive;
        $outer->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $outer->addFromString('meta.json', json_encode([
            'version' => $this->version,
            'checksum' => str_repeat('a', 40),
            // Reported for realism only. Nothing this reader does uses it, and an
            // archive built with no entries does not leave a file behind to measure.
            'size' => is_file($inner) ? filesize($inner) : 0,
            'state' => 1,
            'type' => 0,
        ]));

        if (! $this->withoutContents) {
            $outer->addFile($inner, 'contents.zip');
        }

        $outer->close();
        @unlink($inner);

        return $path;
    }

    /**
     * A SQLite file with the measured schema and three manga, one of them on the shelf.
     *
     * The 2:1 split is deliberate and load-bearing. The measured real file holds 132,185
     * manga of which 1,277 are the library, so a reader that forgets `in_library = 1` looks
     * correct against a fixture where everything is on the shelf. This one is not.
     */
    protected function buildTachimangaDatabase(string $path = ''): string
    {
        $path = $path !== '' ? $path : $this->temporaryPath('db');

        if (is_file($path)) {
            @unlink($path);
        }

        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($this->mangaTable());
        $pdo->exec($this->chapterTable());
        $pdo->exec($this->historyTable());
        $pdo->exec($this->categoryTables());
        $this->seedRows($pdo);

        return $path;
    }

    /**
     * Four series. Two are on the shelf, and the two disagree about the trailing slash.
     *
     * The source id is the real Thunder Scans one, which is over 2^53, because a fixture
     * using a small id would not exercise the part of the reader that has to hold it
     * exactly.
     *
     * The slash disagreement is deliberate and load-bearing. Suwayomi stores
     * `/comics/the-illegitimate-son-became-a-monster-summuner/` and this backup stores
     * `/comics/hiding-a-logistics-center-in-the-apo`, so the two apps disagree and the
     * reader has to settle it. When both variants lived only on the browse-cache rows,
     * the reader-level assertion passed whether or not normalisation ran, because the one
     * row that survived the `in_library` filter was already in the normalised form. A
     * mutation check caught that: deleting `normaliseUrl()` left the suite green.
     *
     * @var list<array{0: int, 1: string, 2: string, 3: int, 4: int}>
     */
    private function mangaRows(): array
    {
        return [
            // id, url, title, in_library, source
            [217, '/comics/hiding-a-logistics-center-in-the-apo/', 'Hiding a Logistics Center', 1, 2_848_420_679_472_350_044],
            [9265, '/comics/the-berserkers-second-playthrough', 'The Berserker\'s Second Playthrough', 1, 2_848_420_679_472_350_044],
            [9263, '/comics/browse-cache-one/', 'Browse Cache One', 0, 2_848_420_679_472_350_044],
            [9264, 'comics/browse-cache-two', 'Browse Cache Two', 0, 1_000_000_000_000_000_001],
        ];
    }

    private function mangaTable(): string
    {
        return <<<'SQL'
            create table Manga (
                id integer primary key autoincrement,
                url varchar(2048),
                title varchar(512),
                in_library boolean,
                in_library_at bigint,
                source bigint,
                real_url varchar(2048)
            )
            SQL;
    }

    private function chapterTable(): string
    {
        $order = $this->withoutSourceOrder ? '' : ', source_order int';

        return <<<SQL
            create table Chapter (
                id integer primary key autoincrement,
                manga int,
                chapter_number single,
                name varchar(255),
                read boolean,
                bookmark boolean,
                last_page_read int,
                last_read_at bigint,
                is_downloaded boolean{$order}
            )
            SQL;
    }

    private function historyTable(): string
    {
        if ($this->withoutHistoryTable) {
            return 'create table Unrelated (id integer primary key)';
        }

        // manga_id, not manga. The sibling tables say manga.
        return <<<'SQL'
            create table History (
                id integer primary key autoincrement,
                manga_id integer,
                last_chapter_id integer,
                last_read_at bigint
            )
            SQL;
    }

    private function categoryTables(): string
    {
        return <<<'SQL'
            create table Category (
                id integer primary key autoincrement,
                name varchar(255),
                create_at bigint
            );
            create table CategoryManga (
                id integer primary key autoincrement,
                category int,
                manga int
            )
            SQL;
    }

    /**
     * Three series. Only the first is on the shelf.
     *
     * The source id is the real Thunder Scans one, which is over 2^53, because a fixture
     * using a small id would not exercise the part of the reader that has to hold it
     * exactly. The urls differ in trailing slash on purpose, since that is the difference
     * between the two apps and the reason {@see ImportedSeries::normaliseUrl()}
     * exists.
     */
    private function seedRows(PDO $pdo): void
    {
        $inLibraryAt = $this->timestamps['in_library_at'];
        $chapterReadAt = $this->timestamps['chapter_last_read_at'];

        $manga = $pdo->prepare(
            'insert into Manga (id, url, title, in_library, in_library_at, source, real_url)
             values (:id, :url, :title, :in_library, :added, :source, :real)'
        );

        $rows = $this->mangaRows();

        foreach ($rows as [$id, $url, $title, $inLibrary, $source]) {
            $manga->execute([
                'id' => $id,
                'url' => $url,
                'title' => $title,
                'in_library' => $inLibrary,
                'added' => $inLibraryAt,
                'source' => $source,
                'real' => null,
            ]);
        }

        /*
         * One statement, one set of rows. The source_order column is optional in the
         * fixture so a test can prove the reader refuses a schema missing a column it
         * binds, and the rows stay the same either way. Two separate insert loops here
         * would double the chapter count and quietly invalidate every count assertion.
         */
        $hasOrder = ! $this->withoutSourceOrder;
        $columns = 'manga, chapter_number, name, read, bookmark, last_page_read, last_read_at, is_downloaded'
            .($hasOrder ? ', source_order' : '');
        $placeholders = $hasOrder ? '?, ?, ?, ?, ?, ?, ?, ?, ?' : '?, ?, ?, ?, ?, ?, ?, ?';

        $chapter = $pdo->prepare("insert into Chapter ({$columns}) values ({$placeholders})");

        // manga, number, name, read, bookmark, page, readAt, downloaded, sourceOrder
        $shelfChapters = [
            [217, 1.0, 'One', 1, 0, 12, $chapterReadAt, 0, 0],
            [217, 2.0, 'Two', 1, 1, 4, $chapterReadAt, 1, 1],
            [217, 3.0, null, 0, 0, 0, null, 0, 2],
            // The second shelf series, which has no chapters at all. A real backup has
            // plenty of these, and an import must not assume a series arrives with chapters.
            [9265, 1.0, 'Only', 1, 0, 3, $chapterReadAt, 0, 0],
        ];

        // A browse-cache chapter that must never appear in the result.
        $cacheChapters = [
            [9263, 1.0, 'Cache', 1, 0, 1, $chapterReadAt, 0, 0],
        ];

        foreach ([...$shelfChapters, ...$cacheChapters] as $row) {
            $chapter->execute($hasOrder ? $row : array_slice($row, 0, 8));
        }

        if (! $this->withoutHistoryTable) {
            $pdo->prepare(
                'insert into History (manga_id, last_chapter_id, last_read_at) values (?, ?, ?)'
            )->execute([217, 430_617, 1_798_301_351]);
        }

        $category = $pdo->prepare('insert into Category (id, name, create_at) values (?, ?, ?)');
        // Free-text labels, one with the trailing space Tachimanga ships.
        $category->execute([1, 'Youtube ', $this->timestamps['category_create_at']]);
        $category->execute([2, 'Snowflakes', $this->timestamps['category_create_at']]);

        $pdo->prepare('insert into CategoryManga (category, manga) values (?, ?)')->execute([1, 217]);
    }

    private function temporaryPath(string $suffix): string
    {
        return tempnam(sys_get_temp_dir(), 'papyrus-fixture-').'.'.$suffix;
    }
}
