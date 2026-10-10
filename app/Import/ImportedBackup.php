<?php

namespace App\Import;

use Illuminate\Support\Collection;

/**
 * Everything one other app's backup turned out to hold, and the counts to prove it.
 *
 * ## The counts are the point, not a nicety
 *
 * An import that finds 1,277 series is doing something very different from one that
 * finds 132,185, and a reader who has the second should never be shown "1,277 imported"
 * with no warning that 131,908 browse-cache rows were passed over. So {@see $totalInFile}
 * travels with the result rather than being a number the reader could have computed but
 * was never shown. The measured Tachimanga file carries 132,185 manga of which exactly
 * 1,277 are `in_library = 1`; the rest is what the app cached from browsing, and
 * importing the whole table is a mistake that looks like success.
 *
 * ## Categories are reported, not matched
 *
 * Tachimanga lets a reader invent category names, and the two in the measured backup,
 * `Youtube ` and `Snowflakes`, are labels somebody typed rather than any reserved
 * vocabulary. Nothing in the file distinguishes a reader's own label from a system's, so
 * there is nothing to match a Papyrus shelf against. Matching free text across two apps
 * both misses shelves that were meant to match and invents matches where a reader once
 * used the same word for something else, so the counts are carried and the caller
 * decides. See open item 2 in docs/IMPORT-ANALYSIS.md.
 *
 * No rating or tag is carried because Tachimanga stores neither, so a reader who rated
 * 300 series on their phone has lost them and the importer cannot recover them.
 *
 * ## Why the source list is the shelf's and not the file's
 *
 * {@see $sources} is what an install is driven from, so it names the sources the reader's
 * library sits on. The Tachimanga file carries 279 `Source` rows in total, and only 52 of
 * them have library series against them; the other 227 are what the app browsed past on a
 * phone. Fetching extensions for those would be installing 227 things a reader did not ask
 * for on the strength of what they once scrolled past.
 *
 * Its series counts sum to 1,260 against a shelf of 1,277, and the 17 that are missing are
 * series whose `source` id has no row in the file's own `Source` table. That is a reader
 * having removed a source while keeping its series. They are not lost: {@see $series} still
 * carries them and the resolver finds them by url, because a series does not need its source
 * named in order to be recognised. It is only this list that is short of them.
 */
final class ImportedBackup
{
    /**
     * @param  Collection<int, ImportedSeries>  $series  The reader's library, and only their library.
     * @param  Collection<string, int>  $categories  Category name to member count, as free-text labels.
     * @param  int  $totalInFile  How many series the file holds in total, library and browse cache together.
     * @param  int  $schemaVersion  The format's own version number, which is what the reader gates on.
     * @param  Collection<int, BackupSource>  $sources  The sources the shelf sits on, largest first.
     */
    public function __construct(
        public readonly string $format,
        public readonly int $schemaVersion,
        public readonly Collection $series,
        public readonly Collection $categories,
        public readonly int $totalInFile,
        public readonly Collection $sources = new Collection,
    ) {}

    /**
     * Chapters the reader had downloaded on the other app.
     *
     * Downloaded files are not in the backup, by that app's own documentation, so this
     * counts files a reader had and does not get. Reported rather than imported, because
     * a re-download is a network cost the reader should be told about, not a failure the
     * importer should hide.
     */
    public function downloadedChapterCount(): int
    {
        return $this->series
            ->flatMap(fn (ImportedSeries $s): Collection => $s->chapters)
            ->filter(fn (ImportedChapter $c): bool => $c->needsRefetchingLocally())
            ->count();
    }

    /**
     * Chapters marked read across the whole library, which is the number a reader who
     * switches devices actually wants to know.
     */
    public function readChapterCount(): int
    {
        return $this->series
            ->flatMap(fn (ImportedSeries $s): Collection => $s->chapters)
            ->filter(fn (ImportedChapter $c): bool => $c->isRead)
            ->count();
    }

    /**
     * A shape a command can print or a test can assert on.
     *
     * Source ids are deliberately left out. They exceed 2^53, and anything json_encode
     * hands to a browser would silently round them. A summary that omits them cannot leak
     * a wrong id into a page.
     *
     * @return array<string, int|string>
     */
    public function summary(): array
    {
        return [
            'format' => $this->format,
            'schema_version' => $this->schemaVersion,
            'library_series' => $this->series->count(),
            'series_in_file' => $this->totalInFile,
            'chapters' => $this->series->sum(fn (ImportedSeries $s): int => $s->chapters->count()),
            'chapters_read' => $this->readChapterCount(),
            'chapters_downloaded' => $this->downloadedChapterCount(),
            'chapters_bookmarked' => $this->series
                ->flatMap(fn (ImportedSeries $s): Collection => $s->chapters)
                ->filter(fn (ImportedChapter $c): bool => $c->isBookmarked)
                ->count(),
            'series_with_resume' => $this->series->filter(
                fn (ImportedSeries $s): bool => $s->backupResumeChapterId !== null
            )->count(),
            'categories' => $this->categories->count(),
        ];
    }
}
