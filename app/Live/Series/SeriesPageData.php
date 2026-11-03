<?php

namespace App\Live\Series;

use App\Covers\CoverStore;
use App\Models\ChapterState;
use App\Models\Download;
use App\Models\LibraryCheck;
use App\Models\Note;
use App\Models\SeriesCover;
use App\Models\SeriesState;
use App\Models\Source;
use App\Support\ChapterNotes;
use App\Support\ImageUrl;
use App\Support\ReaderSettings;
use App\Support\ReadingState;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;

/**
 * The series page's reads, kept out of the Livewire component.
 *
 * The component asks these on every render and on a few chapter actions.
 * Keeping them here lets the query shapes, the paging arithmetic, the
 * scanlator filter and the empty states be exercised without mounting a
 * component, and leaves the component owning lifecycle, actions and the
 * chapter-order state.
 *
 * Reads that depend on another read take the value the component already
 * computed, so a render still makes one series read and one chapter slice
 * rather than one per helper. The order, chunk size and visible-count rules are
 * the page's own.
 */
final class SeriesPageData
{
    /**
     * How many chapters one page holds.
     *
     * 50 is the size the page has always used. The component aliases this
     * constant so its Blade and its actions cannot drift from the reads.
     */
    public const PER_PAGE = 50;

    /**
     * The last series read's failure, if it failed.
     *
     * The page keeps its own public property because the view renders the
     * verdict; this is the value the read produced for that property, and it is
     * cleared by the next successful read in the same request.
     */
    private ?string $seriesError = null;

    /** @var array<int, string> */
    private array $downloadStatesFor = [];

    private bool $downloadStatesLoaded = false;

    public function __construct(
        private readonly SeriesGateway $gateway,
        private readonly ReadingState $reading,
        private readonly ChapterNotes $notes,
        private readonly CoverStore $covers,
        private readonly ReaderSettings $settings,
        private readonly SeriesFormatting $formatting,
    ) {}

    /**
     * The series and its whole chapter list, or null when the server cannot
     * answer for this id.
     *
     * A render-time read, so an unreachable server must render as a named
     * failure rather than a 500. Clearing on the next successful read is what
     * lets a poll or a manual retry recover the page.
     *
     * @return array<string, mixed>|null
     */
    public function series(int $manga): ?array
    {
        try {
            $series = $this->gateway->find($manga);
        } catch (SuwayomiException $e) {
            $this->seriesError = $e->getMessage();

            return null;
        }

        $this->seriesError = null;

        return $series;
    }

    /**
     * Why the last series read failed, or null when it succeeded.
     */
    public function seriesError(): ?string
    {
        return $this->seriesError;
    }

    /**
     * This series' source in our mirror, or null when we do not hold it.
     *
     * The lookup is on Suwayomi's own id rather than the name, because names
     * repeat across language variants: 61 of the 82 sources are named
     * "MangaDex (...)", so a name lookup lands on an arbitrary one. The
     * mirror's `suwayomi_source_id` is the join.
     *
     * Null is a real answer rather than a failure. A source can be uninstalled
     * from Suwayomi while the series it produced is still in the cache, and a
     * link to a page that 404s is worse than a name with nothing on it.
     *
     * @param  array<string, mixed>|null  $series
     */
    public function sourceMirror(?array $series): ?Source
    {
        $source = $series['source'] ?? null;
        $suwayomiId = is_array($source) ? ($source['id'] ?? null) : null;

        if (! is_string($suwayomiId) && ! is_int($suwayomiId)) {
            return null;
        }

        if ($suwayomiId === '') {
            return null;
        }

        return Source::query()
            ->where('suwayomi_source_id', (int) $suwayomiId)
            ->first();
    }

    /**
     * The reader's row for this series.
     */
    public function state(int $manga): SeriesState
    {
        return $this->reading->findSeries(auth()->user(), $manga)
            ?? $this->reading->series(auth()->user(), $manga);
    }

    /**
     * Every chapter the server has, before scanlator filtering and ordering.
     *
     * `manga(id:).chapters` takes no arguments at all, so the whole list
     * arrives in one response. Filtering and paging happen here.
     *
     * @param  array<string, mixed>|null  $series
     * @return array<int, array<string, mixed>>
     */
    public function allChapters(?array $series): array
    {
        if ($series === null) {
            return [];
        }

        $chapters = $series['chapters'] ?? null;
        $nodes = is_array($chapters) ? ($chapters['nodes'] ?? []) : [];

        if (! is_array($nodes)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $nodes */
        return $nodes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $allChapters
     */
    public function chapterCount(array $allChapters): int
    {
        return count($allChapters);
    }

    /**
     * Chapters after scanlator filtering and ordering.
     *
     * @param  array<int, array<string, mixed>>  $allChapters
     * @param  array<int, string>  $excludedScanlators
     * @return array<int, array<string, mixed>>
     */
    public function chapters(array $allChapters, string $order, array $excludedScanlators): array
    {
        $excluded = array_map('mb_strtolower', $excludedScanlators);

        $filtered = array_values(array_filter($allChapters, function (array $chapter) use ($excluded): bool {
            $scanlator = $this->formatting->scanlatorOf($chapter);

            // A chapter with no scanlator is never excluded, since there is
            // nothing to match against and dropping those would lose chapters.
            if ($scanlator === null) {
                return true;
            }

            return ! in_array(mb_strtolower($scanlator), $excluded, true);
        }));

        usort($filtered, function (array $a, array $b): int {
            // sourceOrder is the source's own ordering, which respects volumes
            // that a bare chapterNumber sort would flatten. chapterNumber breaks
            // ties so a source with equal sourceOrder still sorts
            // deterministically.
            $byOrder = (int) ($a['sourceOrder'] ?? 0) <=> (int) ($b['sourceOrder'] ?? 0);

            return $byOrder !== 0
                ? $byOrder
                : ((float) ($a['chapterNumber'] ?? 0) <=> (float) ($b['chapterNumber'] ?? 0));
        });

        if ($order === 'desc') {
            $filtered = array_reverse($filtered);
        }

        return $filtered;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chapters
     */
    public function visibleCount(array $chapters): int
    {
        return count($chapters);
    }

    /**
     * Read state for the chapters on the open page, keyed by chapter id.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array<int, ChapterState>
     */
    public function chapterStates(array $chapters, int $page, int $perPage = self::PER_PAGE): array
    {
        $ids = array_map(
            static fn (array $c): int => (int) ($c['id'] ?? 0),
            $this->formatting->pageSlice($chapters, $page, $perPage),
        );

        return $this->reading->chapterStates(auth()->user(), $ids);
    }

    /**
     * Read state for every filtered chapter, not only the open page.
     *
     * The row markers and `readCount()` stay on the page slice because the
     * reader can only see one page at a time. The two bulk download controls
     * are different: they act on the whole filtered list, so their view of
     * "read" has to cover that list too. Loading the page's map there made
     * every read chapter on another page look unread: measured against a 641
     * chapter series, the button offered 591 when only 8 were unread.
     *
     * Chunked because a long series can carry thousands of chapter ids, and an
     * unbounded `whereIn` eventually runs into a database's parameter limit.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array<int, ChapterState>
     */
    public function allChapterStates(array $chapters): array
    {
        $ids = array_map(static fn (array $c): int => (int) ($c['id'] ?? 0), $chapters);
        $states = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $states += $this->reading->chapterStates(auth()->user(), $chunk);
        }

        return $states;
    }

    /**
     * This reader's notes on the chapters on the open page.
     *
     * Scoped to the visible page rather than the whole series for the same
     * reason `chapterStates()` is: 107 chapters would otherwise be one note
     * lookup each, and the reader cannot see 107 chapters at once either.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array<int, Note>
     */
    public function chapterNotes(array $chapters, int $page, int $perPage = self::PER_PAGE): array
    {
        $ids = array_map(
            static fn (array $c): int => (int) ($c['id'] ?? 0),
            $this->formatting->pageSlice($chapters, $page, $perPage),
        );

        return $this->notes->forChapters(auth()->user(), $ids);
    }

    /**
     * The reader's own cover for this series, or null when they have not set one.
     */
    public function customCover(int $manga): ?SeriesCover
    {
        return $this->covers->find(auth()->user(), $manga);
    }

    /**
     * The rating as the star row should draw it.
     *
     * Null rather than zero for unrated, because zero is not a rating this app
     * offers and drawing five lit stars for "no opinion" would be a lie about a
     * state that exists.
     */
    public function rating(SeriesState $state): ?int
    {
        return $state->rating === null ? null : (int) $state->rating;
    }

    /**
     * This series' tags, for the editor.
     *
     * A method rather than the model's cast so the empty case is an empty array
     * rather than null reaching a `@foreach`.
     *
     * @return array<int, string>
     */
    public function tags(SeriesState $state): array
    {
        return array_values($state->tags ?? []);
    }

    /**
     * Why there is nothing to read, when there is nothing to read.
     *
     * `CheckLedger::check()` records what a fetch produced, and this page reads
     * it so a source answering "Cloudflare bypass currently disabled" is not
     * reported as "The source has not listed any chapters for this series",
     * which is a different and far more hopeful statement.
     *
     * The recorded text is shown verbatim because it is the server's own words
     * and rewriting them would be inventing a diagnosis. The heading is ours,
     * because "Cloudflare bypass currently disabled" is not a sentence a
     * reader can act on and the callout needs one.
     *
     * Null while a fetch is still in flight or has never run, which is what
     * keeps the plain "no chapters yet" wording for the case where we genuinely
     * do not know.
     *
     * @return array{0: string, 1: string}|null
     */
    public function emptyReason(int $manga, ?string $loadError): ?array
    {
        if ($loadError !== null) {
            /*
             * Two different failures arrive on the same property, and
             * `CheckLedger` already draws the line between them: `No chapters
             * found` is the source answering that it has nothing, and anything
             * else is the call not completing. Measured, the same message
             * arrived for a series with no chapters and for a source blocked by
             * Cloudflare, so "the chapter list did not load" was wrong for one
             * of them.
             *
             * Classified on the message rather than on the state, because the
             * manual retry sets `loadError` directly and never writes a ledger
             * row, so there is no state to read. It is the same test
             * `CheckLedger` makes, and for the same reason: it is what the
             * server's message says.
             */
            if (str_contains(strtolower($loadError), 'no chapters')) {
                return [
                    __('The source returned no chapters'),
                    __('The source answered, but it listed no chapters. If the series moved, you can move it to another source from here. The source may have changed its layout or it may be broken.'),
                ];
            }

            return [__('The chapter list did not load'), $loadError];
        }

        $check = LibraryCheck::query()
            ->forUser((int) auth()->id())
            ->where('suwayomi_manga_id', $manga)
            ->first();

        if ($check === null) {
            return null;
        }

        if ($check->state === LibraryCheck::ERROR) {
            return [
                __('This source could not be reached'),
                (string) ($check->error ?: __('The server could not be reached.')),
            ];
        }

        return [
            __('No chapters yet'),
            __('The source has not listed any chapters for this series.'),
        ];
    }

    /**
     * @param  int  $visibleCount  The filtered chapter count, not the source's.
     */
    public function lastPage(int $visibleCount, int $perPage = self::PER_PAGE): int
    {
        return max(1, (int) ceil($visibleCount / $perPage));
    }

    /**
     * How many of the filtered chapters this reader has finished.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     */
    public function readCount(array $chapters): int
    {
        $ids = array_map(static fn (array $c): int => (int) ($c['id'] ?? 0), $chapters);

        return $this->reading->readCount($chapters, $this->reading->chapterStates(auth()->user(), $ids));
    }

    /**
     * The cover to render: the reader's own if they set one, otherwise the source's.
     *
     * One accessor for both, so the template cannot ask for the wrong one.
     * Measured, source covers are already served through a Laravel route, so a
     * custom cover on its own route is no new cost class.
     */
    public function coverUrl(int $manga, ?SeriesCover $customCover): ?string
    {
        if ($customCover !== null) {
            return route('covers.show', ['manga' => $manga]);
        }

        return ImageUrl::thumbnail($manga, ImageUrl::defaultFormat());
    }

    /**
     * This series' resolved reader settings.
     *
     * Resolved the same way the reader resolves them, so the mode shown here is
     * the mode the reader will open in.
     *
     * @return array<string, mixed>
     */
    public function readingSettings(int $manga): array
    {
        return $this->settings->resolve(auth()->user(), $manga);
    }

    /**
     * Continue reading, if there is somewhere to continue from.
     *
     * Prefers the recorded pointer, then the end of the list in display order,
     * because a series opened for the first time has no pointer and "start
     * reading" should still work.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @return array<string, mixed>|null
     */
    public function resumeChapter(SeriesState $state, array $chapters, string $order): ?array
    {
        $pointer = $state->last_read_chapter_id;

        if ($pointer !== null) {
            foreach ($chapters as $chapter) {
                if ((int) $chapter['id'] === $pointer) {
                    return $chapter;
                }
            }
        }

        $ordered = $order === 'asc' ? $chapters : array_reverse($chapters);

        return $ordered[0] ?? null;
    }

    /**
     * Whether the server has this chapter's pages on disk.
     *
     * From the chapter list we already fetched, not a query per chapter. The
     * series page renders fifty rows and asking Suwayomi fifty times would be
     * the same mistake the library page made once already.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     */
    public function isDownloaded(array $chapters, int $chapterId): bool
    {
        foreach ($chapters as $chapter) {
            if ((int) $chapter['id'] === $chapterId) {
                return (bool) ($chapter['isDownloaded'] ?? false);
            }
        }

        return false;
    }

    /**
     * This reader's download state for a chapter, or null when there is none.
     *
     * One query for the visible page's chapters rather than one per row. The
     * map lives for one request, like the component fields it replaces; an
     * action that queues chapters calls `forgetDownloadStates()` so the next
     * render reads the new rows.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     */
    public function downloadState(array $chapters, int $chapterId): ?string
    {
        $this->loadDownloadStates($chapters);

        return $this->downloadStatesFor[$chapterId] ?? null;
    }

    /**
     * Drop the cached download map, for the two actions that queue chapters.
     */
    public function forgetDownloadStates(): void
    {
        $this->downloadStatesFor = [];
        $this->downloadStatesLoaded = false;
    }

    /**
     * How many chapters would "download unread" queue right now.
     *
     * Takes the whole filtered list's states, because the control queues across
     * every page.
     *
     * @param  array<int, array<string, mixed>>  $chapters
     * @param  array<int, ChapterState>  $allChapterStates
     */
    public function unreadDownloadableCount(array $chapters, array $allChapterStates): int
    {
        $count = 0;

        foreach ($chapters as $chapter) {
            $chapterId = (int) $chapter['id'];

            if (! (bool) ($chapter['isDownloaded'] ?? false) && ! ($allChapterStates[$chapterId] ?? null)?->is_read) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chapters
     */
    private function loadDownloadStates(array $chapters): void
    {
        if ($this->downloadStatesLoaded) {
            return;
        }

        $ids = array_map(static fn (array $chapter): int => (int) $chapter['id'], $chapters);

        if ($ids === []) {
            $this->downloadStatesLoaded = true;

            return;
        }

        $rows = Download::forUser((int) auth()->id())
            ->whereIn('suwayomi_chapter_id', $ids)
            ->get(['suwayomi_chapter_id', 'state']);

        foreach ($rows as $row) {
            $this->downloadStatesFor[(int) $row->suwayomi_chapter_id] = (string) $row->state;
        }

        $this->downloadStatesLoaded = true;
    }
}
