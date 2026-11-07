<?php

use App\AniList\Tracking;
use App\Covers\CoverStore;
use App\Downloads\DownloadLedger;
use App\Jobs\FetchSeriesChapters;
use App\Live\Series\SeriesCoverRules;
use App\Live\Series\SeriesFormatting;
use App\Live\Series\SeriesPageData;
use App\Models\Source;
use App\Support\ChapterNotes;
use App\Support\ReaderSettings;
use App\Support\ReadingState;
use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    /*
     * Upload progress on the cover file input, which is the reason the upload comes
     * through Livewire rather than a plain form post to its own route: the browser gets a
     * real progress bar instead of a button that goes quiet for two seconds.
     */
    use WithFileUploads;

    /** The page's reads, kept out of this component so their shapes can be tested directly. */
    private SeriesPageData $data;

    /** The page's pure formatting and range rules, shared with its direct tests. */
    private SeriesFormatting $formatting;

    /** The series refresh the retry button calls. */
    private SeriesGateway $seriesGateway;

    /** Read state written by the page's toggles, rating, tags and progress controls. */
    private ReadingState $readingState;

    /** AniList pushes booked after a read-state change. */
    private Tracking $tracking;

    /** The reading settings written by the order and mode controls. */
    private ReaderSettings $readerSettings;

    /** The reader's custom cover writes. */
    private CoverStore $coverStore;

    /** Chapter note writes. */
    private ChapterNotes $notes;

    /** Download queue writes. */
    private DownloadLedger $downloads;

    public function boot(
        SeriesPageData $data,
        SeriesFormatting $formatting,
        SeriesGateway $seriesGateway,
        ReadingState $readingState,
        Tracking $tracking,
        ReaderSettings $readerSettings,
        CoverStore $coverStore,
        ChapterNotes $notes,
        DownloadLedger $downloads,
    ): void {
        $this->data = $data;
        $this->formatting = $formatting;
        $this->seriesGateway = $seriesGateway;
        $this->readingState = $readingState;
        $this->tracking = $tracking;
        $this->readerSettings = $readerSettings;
        $this->coverStore = $coverStore;
        $this->notes = $notes;
        $this->downloads = $downloads;
    }

    /**
     * Suwayomi's manga id, and nothing else.
     *
     * Measured: `manga(id:)` resolves with no source named, and ids are global
     * across sources. Manga 174 is One Piece on MangaDex and 76 is a Berserker
     * title on Asura Scans. So the route carries one number and not a
     * source/manga pair.
     */
    public int $manga;

    /**
     * The manga id, cast from the route parameter.
     *
     * Named `manga` because that is the route segment: Livewire passes mount()
     * arguments by route parameter name, and a mismatch here makes the container
     * try to resolve an int with no default, which 500s the page.
     */
    public function mount(int $manga): void
    {
        $this->manga = $manga;

        $this->hydrateOrder();

        $this->requestFetchIfIncomplete();
    }

    /**
     * The browser tab names the series, or its id when the server cannot.
     *
     * The static `#[Title('Series')]` made every series the same tab. The name is read
     * from the same gateway call the page already makes, and an unknown or unreachable
     * series falls back to the id so the tab still says which page it is.
     */
    public function render(): \Illuminate\View\View
    {
        $title = $this->series['title'] ?? null;

        return $this->view()->title(
            filled($title) ? (string) $title : __('Series :id', ['id' => $this->manga]),
        );
    }

    /**
     * Start from the reader's saved chapter order, not from oldest every time.
     *
     * ## Why this cannot be a property default
     *
     * `public string $order = 'asc';` is the right declaration for a value that only has to survive
     * a re-render, and the wrong one for a value a reader has chosen. This was the second: a reader
     * sorted a series newest-first, opened another series, came back, and got oldest-first with
     * nothing on screen saying a choice had been made.
     *
     * The mode buttons beside it did not have that problem, because `setReadingMode` writes through
     * `ReaderSettings` and the buttons read from it. Two buttons, side by side, in one group, with
     * one remembering and one not.
     *
     * So the order is read from the same place the mode is read from, and `setOrder` writes to the
     * same place `setReadingMode` writes to. One group of buttons, one lifetime.
     */
    public function hydrateOrder(): void
    {
        $this->order = $this->readingSettings()['chapter_order'];
    }

    /**
     * Whether the server is still withholding something from us.
     *
     * Two ways to be incomplete, and the second is easy to miss.
     *
     * No chapter list at all is the obvious case: the series was never fetched. But a series can
     * hold a **full chapter list and still report status UNKNOWN**. That is what the reader
     * reported, and what One Piece on MangaFire does: measured, 2,440 chapters cached,
     * `initialized` true, status UNKNOWN, and ONGOING the moment `fetchMangaAndChapters` runs.
     *
     * A gate has to name every state it treats as finished, and an unknown status is not one.
     */
    #[Computed]
    public function needsFetch(): bool
    {
        if ($this->series() === null) {
            return false;
        }

        $status = strtoupper((string) ($this->series()['status'] ?? ''));

        return $this->chapterCount === 0 || $status === '' || $status === 'UNKNOWN';
    }

    /**
     * Ask the source for this series, in the background, if it is not fully fetched yet.
     *
     * ## Which gap, decides which call
     *
     * No chapter list needs `fetchMangaAndChapters`, which measured 1.6s to 7.1s and scales
     * with the chapter count. No chapter list is also the case with no choice: there is nothing
     * to read otherwise.
     *
     * A full chapter list with an unknown status needs `fetchManga` alone, which measured a flat
     * 186ms to 555ms whatever the chapter count. Booking the thorough fetch for that would
     * re-read 2,440 chapters to fix one word, on a source the reader did not ask us to scrape
     * again. This case is not rare either: **browsing a source resets the status of every manga
     * it lists**, measured, because the listing call writes the records it returns and a listing
     * carries no status. So a reader who browses and then opens a series hits it every time.
     *
     * ## Why it is queued rather than inline
     *
     * Because a cold fetch is slow enough to be worth measuring. MangaFire's was **7.7s for 2,440
     * chapters**, and blocking the page on that is a white screen for eight seconds on some
     * titles. The page renders with what it has and the answer arrives behind it.
     *
     * ## Why nothing else is prefetched
     *
     * Because there is one queue worker. Fetching the grid behind the reader would put twenty
     * requests ahead of the one series they clicked, which is the opposite of what prefetching
     * is for. The click is the intent signal, so the fetch is booked here and nowhere earlier.
     * {@see FetchSeriesChapters} explains the rest.
     *
     * The reader's row is the job's business, not this page's. `ReadingState::series()` returns
     * an unsaved model and `loadOrCreate()` does not save either, so a page that called one and
     * assumed the row existed booked a job that threw on arrival.
     */
    protected function requestFetchIfIncomplete(): void
    {
        if (! $this->needsFetch()) {
            return;
        }

        if (! FetchSeriesChapters::claim((int) auth()->id())) {
            // A fetch is already in flight for this reader, so this page joins that one rather
            // than queueing behind it.
            return;
        }

        FetchSeriesChapters::dispatch(
            (int) auth()->id(),
            $this->manga,
            $this->needsChapters(),
        );
    }

    /**
     * Whether the chapter list is what is missing, as opposed to only the status.
     */
    #[Computed]
    public function needsChapters(): bool
    {
        return $this->chapterCount === 0;
    }

    /**
     * This series' source in our mirror, or null when we do not hold it.
     *
     * The lookup joins on Suwayomi's own id rather than the name, because names
     * repeat across language variants. Null is a real answer: a source can be
     * uninstalled while the series it produced is still in the cache.
     */
    #[Computed]
    public function sourceMirror(): ?Source
    {
        return $this->data->sourceMirror($this->series);
    }

    /**
     * Whether a fetch for this series is in flight.
     *
     * Read from the job's claim rather than from a property, because the work happens in another
     * process and the page has no other way to learn about it.
     */
    #[Computed]
    public function fetchingSeries(): bool
    {
        return $this->needsFetch() && FetchSeriesChapters::isClaimed((int) auth()->id());
    }

    /**
     * Whether the chapter list is what is missing, as opposed to only the status.
     *
     * The distinction decides what the reader watches. A series with no chapters gets the
     * loading panel, because there is nothing else to show. A series with a full chapter list
     * and an unknown status gets no panel at all: the reader reads while the status is filled in
     * behind them, and the badge appears when it lands. Two flags rather than one, because one
     * flag would either blank a readable series or leave a status unfilled for ever.
     */
    #[Computed]
    public function fetchingChapters(): bool
    {
        return $this->fetchingSeries() && $this->needsChapters();
    }

    /**
     * Ascending, which is Mihon's default and the order a reader wants when catching up.
     *
     * A starting value only. The reader's actual choice is loaded by `hydrateOrder()` and written by
     * `setOrder()`, both of which go through `ReaderSettings`, so the declaration is what a series
     * with no saved choice looks like and not what it always reverts to.
     */
    public string $order = ReaderSettings::ORDER_OLDEST;

    /**
     * Hide chapters from scanlators the reader has excluded.
     *
     * Duplicates are the reason this exists. A popular series carries the same
     * chapter from five groups, and Mihon lets a reader keep one and drop the
     * rest. Null means no exclusion, which is the default for everyone.
     *
     * @var array<int, string>
     */
    public array $excludedScanlators = [];

    public int $page = 1;

    public const PER_PAGE = SeriesPageData::PER_PAGE;

    /**
     * Notified when the server has no chapters for this series.
     */
    public ?string $loadError = null;

    /**
     * Ask the server to re-read the series and its chapter list.
     *
     * Now the retry rather than the only way in. The page fetches on load when there is no
     * chapter list, so a reader should reach chapters without touching this; what is left for
     * the button is the two cases that genuinely need a person: a source that failed, and a
     * reader who wants a genuinely stale list re-read because a new chapter has appeared
     * upstream since the last fetch.
     */
    public function refresh(): void
    {
        // A manual retry supersedes any claim the automatic fetch is holding, or the reader
        // would press the button and watch it do nothing.
        FetchSeriesChapters::release((int) auth()->id());
        unset($this->fetchingChapters);

        try {
            $result = $this->seriesGateway->refresh($this->manga);
        } catch (SuwayomiException $e) {
            /*
             * A `danger` toast rather than `Flux::error()`, which does not exist in Flux 2:
             * `FluxManager` has no such method and the call raises "Call to undefined method
             * Flux\FluxManager::error()", which 500s the Livewire request and leaves the reader
             * with a spinner and no explanation. Measured 2026-10-04, on the press this button
             * exists for: the reader whose source answers "Cloudflare bypass currently disabled"
             * got a 500 and a console error where a sentence would do. Trap 79.
             */
            $this->loadError = $e->getMessage();

            Flux::toast(
                text: $e->getMessage(),
                heading: __('The chapter list did not load'),
                variant: 'danger',
            );

            return;
        }

        $this->loadError = $result['error'];
        $this->page = 1;

        Flux::toast(
            text: $this->loadError ?? trans_choice(':count chapter|:count chapters', $this->chapterCount, ['count' => $this->chapterCount]),
            heading: $this->loadError === null ? __('Chapters loaded') : __('The source had nothing to return'),
            variant: $this->loadError === null ? 'success' : 'warning',
        );
    }

    public function toggleLibrary(): void
    {
        $inLibrary = ! $this->state->in_library;

        $this->readingState->setInLibrary(auth()->user(), $this->manga, $inLibrary);

        // The computed was read a moment ago and holds the row as it was, so the
        // button this render produces would say the opposite of the database
        // until the next request. Drop it and let the render read fresh.
        unset($this->state);

        Flux::toast(
            text: $inLibrary
                ? __('Added to your library.')
                : __('Removed from your library.'),
            heading: $inLibrary ? __('In library') : __('Removed'),
            variant: $inLibrary ? 'success' : 'info',
        );

        if ($inLibrary) {
            $this->dispatch('tour-library-added');
        }
    }

    public function toggleFavourite(): void
    {
        $favourite = ! $this->state->favourited_at;

        $this->readingState->setFavourited(auth()->user(), $this->manga, $favourite);

        unset($this->state);
    }

    public function toggleReadLater(): void
    {
        $queued = ! $this->state->read_later_at;

        $this->readingState->setReadLater(auth()->user(), $this->manga, $queued);

        unset($this->state);
    }

    /**
     * Rate this series, or unrate it by rating it again.
     *
     * Pressing the star that is already lit clears the rating rather than being a no-op. A
     * control with no way back to unrated is a control a reader can get stuck on, and the only
     * alternative would be a separate clear button sitting next to five stars to say the
     * obvious thing.
     *
     * There is no separate saved rating on the page to keep in step, which is the reason this
     * is safe: the star row reads `$this->state->rating` and the write updates that same row,
     * so there is no second copy of the value that could disagree with the first.
     */
    public function rate(int $stars): void
    {
        $current = $this->state->rating === null ? null : (int) $this->state->rating;

        $this->readingState->setRating(
            auth()->user(),
            $this->manga,
            $current === $stars ? null : $stars,
        );

        unset($this->state);
    }

    /**
     * Unrate this series.
     *
     * A separate method rather than `rate(0)`, because `setRating()` refuses anything outside
     * one to five and that refusal is the right behaviour: a zero is not a rating this app
     * offers, it is the absence of one, and it is stored as `null`. Overloading `rate()` with
     * a magic zero would mean the method that validates also has to special-case, which is how
     * a range check stops being a range check.
     */
    public function clearRating(): void
    {
        $this->readingState->setRating(auth()->user(), $this->manga, null);

        unset($this->state);
    }

    /**
     * The tag being typed, held between keystrokes and read on submit.
     *
     * A public property because that is what `wire:model` binds to. It is deliberately not
     * part of the computed `$state`: a draft is what the reader has typed, not what is stored,
     * and folding the two together would mean a half-typed tag appearing as a real one.
     */
    public string $tagDraft = '';

    /**
     * File this series under a label the reader invents.
     *
     * The draft is emptied whatever happens, because a tag that was refused should not sit in
     * the box waiting to be pressed again. The rejection itself is reported, so nothing is
     * silently dropped: normalisation truncates at 40 characters and the set caps at 20, and a
     * reader who has lost a tag to either deserves to be told rather than left to wonder
     * whether it saved.
     */
    public function addTag(): void
    {
        $draft = trim($this->tagDraft);

        $this->tagDraft = '';

        if ($draft === '') {
            return;
        }

        $existing = $this->state->tags ?? [];

        if (mb_strlen($draft) > ReadingState::TAG_MAX_LENGTH) {
            Flux::toast(
                text: trans_choice(
                    'That tag is :max characters and was cut to fit. One series may carry :count.|That tag is :max characters and was cut to fit. One series may carry :count.',
                    ReadingState::TAG_MAX_COUNT,
                    ['max' => ReadingState::TAG_MAX_LENGTH, 'count' => ReadingState::TAG_MAX_COUNT],
                ),
                heading: __('Tag shortened'),
                variant: 'warning',
            );
        }

        if (count($existing) >= ReadingState::TAG_MAX_COUNT) {
            Flux::toast(
                text: trans_choice(
                    'This series already has :count tag. Remove one to add another.|This series already has :count tags. Remove one to add another.',
                    ReadingState::TAG_MAX_COUNT,
                    ['count' => ReadingState::TAG_MAX_COUNT],
                ),
                heading: __('No room for another tag'),
                variant: 'warning',
            );

            return;
        }

        // A tag the reader already has is a no-op rather than an error, and worth saying so:
        // pressing the same idea twice should feel like the app noticed, not like it broke.
        if (in_array(mb_strtolower($draft), array_map(mb_strtolower(...), $existing), true)) {
            Flux::toast(
                text: __('That tag is already on this series.'),
                heading: __('Already tagged'),
                variant: 'info',
            );

            return;
        }

        $this->readingState->addTag(auth()->user(), $this->manga, $draft);

        unset($this->state);

        /*
         * No toast on success. The chip appearing in the list is the confirmation, and a toast
         * saying "Tagged" on top of it makes the reader look for something that changed when
         * the thing they were looking at already did. The two warnings above exist because
         * those are the cases where the reader asked for one thing and got another.
         */
    }

    /**
     * Take a label off this series.
     *
     * No confirmation. Removing a tag is the operation the reader asked for by pressing the
     * cross on that tag, it is reversible by typing the word again, and a dialog asking
     * "are you sure?" about undoing a two-character edit is the kind of thing that teaches
     * people to dismiss dialogs without reading them.
     */
    public function removeTag(string $tag): void
    {
        $this->readingState->removeTag(auth()->user(), $this->manga, $tag);

        unset($this->state);
    }

    /**
     * This series' tags, for the editor.
     *
     * The empty case is an empty array rather than null reaching a `@foreach`.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function tags(): array
    {
        return $this->data->tags($this->state);
    }

    /**
     * The rating as the star row should draw it.
     *
     * Null rather than zero for unrated, because zero is not a rating this app offers.
     */
    #[Computed]
    public function rating(): ?int
    {
        return $this->data->rating($this->state);
    }

    /*
     * Read state, set by hand.
     *
     * A reader coming from another app has read chapters this one has never seen
     * opened, and the only way to say so used to be to open every chapter and let
     * the progress endpoint infer it. The dot on each row is now the control.
     */

    /**
     * Flip one chapter's read flag.
     */
    public function toggleRead(int $chapterId): void
    {
        $read = ! (bool) $this->readingState->findChapter(auth()->user(), $chapterId)?->is_read;

        $this->setChapterRead($chapterId, $read);
    }

    /**
     * Mark every chapter of this series read.
     *
     * The pointer follows the furthest chapter, because continue-reading should
     * offer what comes after the list, not chapter one of a series this reader
     * has just declared finished.
     */
    public function markAllRead(): void
    {
        $ids = array_map(static fn (array $chapter): int => (int) $chapter['id'], $this->chapters);

        $this->readingState->markManyRead(auth()->user(), $this->manga, $ids, true);

        // The pointer lands on the furthest chapter in source order, whatever
        // direction this reader is displaying the list in: "all read" is a
        // statement about the series, not about the sort.
        if ($this->allChapters !== []) {
            $furthest = collect($this->allChapters)
                ->sortBy(static fn (array $chapter): int => (int) ($chapter['sourceOrder'] ?? 0))
                ->last();

            $this->readingState->setPointer(auth()->user(), $this->manga, (int) $furthest['id']);
        }

        unset($this->state, $this->chapterStates, $this->allChapterStates, $this->readCount);

        $this->tracking->scheduleSync(auth()->user(), $this->manga);

        Flux::toast(
            text: trans_choice(':count chapter marked read.|:count chapters marked read.', count($ids), ['count' => count($ids)]),
            heading: __('Marked read'),
            variant: 'success',
        );
    }

    /**
     * Mark every chapter of this series unread.
     *
     * The pointer clears with the flags: a pointer onto an unread chapter would
     * have continue-reading offer a chapter in the middle of a series this reader
     * says they have not started.
     */
    public function markAllUnread(): void
    {
        $ids = array_map(static fn (array $chapter): int => (int) $chapter['id'], $this->chapters);

        $this->readingState->markManyRead(auth()->user(), $this->manga, $ids, false);
        $this->readingState->setPointer(auth()->user(), $this->manga, null);

        unset($this->state, $this->chapterStates, $this->allChapterStates, $this->readCount);

        Flux::toast(
            text: trans_choice(':count chapter marked unread.|:count chapters marked unread.', count($ids), ['count' => count($ids)]),
            heading: __('Marked unread'),
            variant: 'success',
        );
    }

    /**
     * Write one chapter's read flag and everything that follows from it.
     *
     * Marking read moves the continue-reading pointer forward when this chapter is
     * past it, so the next "continue" starts after what was just declared read.
     * Marking unread leaves the pointer alone: clearing one chapter's flag says
     * nothing about where the reader is.
     */
    private function setChapterRead(int $chapterId, bool $read): void
    {
        $this->readingState->markRead(auth()->user(), $this->manga, $chapterId, $read);

        if ($read) {
            $orderOf = function (int $id): int {
                foreach ($this->allChapters as $chapter) {
                    if ((int) $chapter['id'] === $id) {
                        return (int) ($chapter['sourceOrder'] ?? 0);
                    }
                }

                return -1;
            };

            $pointer = (int) ($this->state->last_read_chapter_id ?? 0);

            if ($pointer === 0 || $orderOf($chapterId) > $orderOf($pointer)) {
                $this->readingState->setPointer(auth()->user(), $this->manga, $chapterId);
            }

            $this->tracking->scheduleSync(auth()->user(), $this->manga);
        }

        unset($this->state, $this->chapterStates, $this->allChapterStates, $this->readCount);
    }

    /**
     * Sort this series' chapter list.
     *
     * Written through ReaderSettings for the same reason `setReadingMode` is: a plain Livewire
     * property survives a re-render but not a navigation, and a reader who chose an order expects it
     * to still be there when they come back to the series. Being the second of two toggles in one
     * button group to behave differently from the first is the kind of thing nobody reports as a
     * bug and everybody works around.
     */
    public function setOrder(string $order): void
    {
        if (! in_array($order, [ReaderSettings::ORDER_OLDEST, ReaderSettings::ORDER_NEWEST], true)) {
            return;
        }

        $this->order = $order;
        $this->page = 1;

        $this->readerSettings->setSeriesOverride(
            auth()->user(),
            $this->manga,
            ['chapter_order' => $order],
        );
    }

    /**
     * Switch this series between continuous scroll and paged reading.
     *
     * Written through ReaderSettings so the value lands in the same column the
     * reader reads it from. Changing it here and having the reader disagree is
     * the failure this avoids.
     */
    public function setReadingMode(string $mode): void
    {
        $this->readerSettings->setSeriesOverride(
            auth()->user(),
            $this->manga,
            ['mode' => $mode],
        );

        Flux::toast(
            text: $mode === \App\Support\ReaderSettings::MODE_PAGED
                ? __('Chapters in this series will open one page at a time.')
                : __('Chapters in this series will scroll continuously.'),
            heading: __('Reading mode updated'),
            variant: 'success',
        );
    }

    /**
     * Why the server could not answer for this series, or null when the last read
     * succeeded.
     *
     * Set by {@see series()}. The view tells its two verdicts apart, because "the
     * server never answered" is no knowledge at all about the manga id, while the
     * other callout is a claim about the id itself.
     */
    public ?string $seriesError = null;

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function series(): ?array
    {
        $series = $this->data->series($this->manga);

        // The view renders the read's verdict through this property, so the
        // delegate carries the error back out in the same request.
        $this->seriesError = $this->data->seriesError();

        return $series;
    }

    /**
     * The user's row for this series.
     */
    #[Computed]
    public function state(): \App\Models\SeriesState
    {
        return $this->data->state($this->manga);
    }

    /**
     * Every chapter the server has, before scanlator filtering and ordering.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function allChapters(): array
    {
        return $this->data->allChapters($this->series);
    }

    #[Computed]
    public function chapterCount(): int
    {
        return $this->data->chapterCount($this->allChapters);
    }

    /**
     * Scanlators present in this series, for the exclusion control.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function scanlators(): array
    {
        return $this->formatting->scanlators($this->allChapters);
    }

    /**
     * Chapters after scanlator filtering and ordering.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function chapters(): array
    {
        return $this->data->chapters($this->allChapters, $this->order, $this->excludedScanlators);
    }

    #[Computed]
    public function visibleCount(): int
    {
        return $this->data->visibleCount($this->chapters);
    }

    /**
     * @return array<int, \App\Models\ChapterState>
     */
    #[Computed]
    public function chapterStates(): array
    {
        return $this->data->chapterStates($this->chapters, $this->page);
    }

    /**
     * Read state for every filtered chapter, not only the open page.
     *
     * The two bulk download controls act on the whole filtered list. Loading
     * the page's map there made every read chapter on another page look
     * unread: measured against a 641 chapter series, the button offered 591
     * when only 8 were unread.
     *
     * @return array<int, \App\Models\ChapterState>
     */
    #[Computed]
    public function allChapterStates(): array
    {
        return $this->data->allChapterStates($this->chapters);
    }

    /**
     * This reader's notes on the chapters on this page.
     *
     * @return array<int, \App\Models\Note>
     */
    #[Computed]
    public function chapterNotes(): array
    {
        return $this->data->chapterNotes($this->chapters, $this->page);
    }

    /**
     * The chapter the note modal is currently editing, carried by the event.
     *
     * A property rather than a lookup from the clicked button, because the marker
     * buttons are 107 near-identical elements and the id has to travel with the click.
     */
    #[Locked]
    public int $noteChapter = 0;

    /** The text in the note editor's textarea. */
    public string $noteBody = '';

    /**
     * Open the note editor on one chapter, seeded from what is already here.
     *
     * Seeded from the page's own data rather than fetched, so opening the editor for a
     * chapter that has a note costs no request. A chapter with no note opens empty,
     * which is right: an empty box and a note the reader deleted look the same.
     *
     * Toggling rather than only opening, so a second click on the same marker closes it.
     * A reader who clicks the pencil, changes their mind, and clicks again means close.
     */
    public function openNote(int $chapter): void
    {
        if ($this->noteEditing === $chapter) {
            $this->noteEditing = 0;

            return;
        }

        $this->noteEditing = $chapter;
        $this->noteBody = $this->chapterNotes()[$chapter]->body ?? '';
    }

    /** Close the editor without writing. */
    public function closeNote(): void
    {
        $this->noteEditing = 0;
    }

    /**
     * Save or clear from the editor, then close it.
     *
     * Separate from `saveNote()` because the editor is the one caller that has to close
     * itself afterwards, and putting the close inside `saveNote()` would shut the editor
     * for any future caller that only wanted to write.
     */
    public function saveNoteFromEditor(): void
    {
        if ($this->noteEditing === 0) {
            return;
        }

        $this->saveNote($this->noteEditing, $this->noteBody);

        $this->noteEditing = 0;
    }

    /**
     * Apply the cover as soon as the reader picks a file.
     *
     * One gesture rather than two. Choosing a cover and then confirming it is a Save
     * button that a reader forgets is there, and Livewire has already shown them a real
     * progress bar for the upload, so the wait is accounted for. A mis-click is undone
     * with the same control that removes any other cover.
     */
    public function updatedCoverUpload(): void
    {
        if ($this->coverUpload !== null) {
            $this->saveCover();
        }
    }

    /**
     * The reader's own cover for this series, or null when they have not set one.
     */
    #[Computed]
    public function customCover(): ?\App\Models\SeriesCover
    {
        return $this->data->customCover($this->manga);
    }

    /** The file the reader has chosen but not yet saved. */
    #[WithFileUploads]
    public ?\Illuminate\Http\UploadedFile $coverUpload = null;

    /**
     * Replace this reader's cover.
     *
     * Catches CoverRejected because its message is written for a reader and a 500 is the
     * wrong place to put one. Everything else is left to fail loudly.
     */
    public function saveCover(): void
    {
        $this->validate(
            SeriesCoverRules::rules(),
            SeriesCoverRules::messages(),
        );

        try {
            $this->coverStore->store(auth()->user(), $this->manga, $this->coverUpload);
        } catch (\App\Covers\CoverRejected $e) {
            Flux::toast(
                text: $e->getMessage(),
                heading: __('That cover was not accepted'),
                variant: 'danger',
            );

            $this->coverUpload = null;

            return;
        }

        $this->coverUpload = null;

        unset($this->customCover, $this->coverUrl);

        Flux::toast(
            text: __('Your cover is in place.'),
            heading: __('Cover changed'),
            variant: 'success',
        );
    }

    /**
     * Put the source's own cover back.
     *
     * The source's cover returns on its own: the app only overrides it while a row
     * exists, so removing the row is the whole of the restore.
     */
    public function removeCover(): void
    {
        $removed = $this->coverStore->remove(auth()->user(), $this->manga);

        unset($this->customCover, $this->coverUrl);

        Flux::toast(
            text: $removed
                ? __('Back to the cover the source has.')
                : __('You had not changed this cover.'),
            heading: $removed ? __('Cover removed') : __('Nothing to remove'),
            variant: $removed ? 'success' : 'info',
        );
    }

    /**
     * Which chapter's editor is open, 0 for none.
     *
     * Locked, because it decides which row a note is written to and a request must not be
     * able to point it at a chapter the reader is not looking at.
     */
    #[Locked]
    public int $noteEditing = 0;

    /**
     * Save or clear a note on one chapter of this series.
     *
     * The same write path the reader uses, so the two cannot disagree about what is on
     * a chapter. A series page can write a note on a chapter nobody has opened, which
     * measured costs nothing: the `chapter_states` row it needs is invisible to the
     * read count and to unread, both of which filter on `is_read`.
     */
    public function saveNote(int $chapterId, string $body): void
    {
        $result = $this->notes->save(
            auth()->user(),
            $this->manga,
            $chapterId,
            $body,
        );

        unset($this->chapterNotes);

        Flux::toast(
            text: $result['saved']
                ? __('Note saved on this chapter.')
                : __('Note cleared.'),
            heading: $result['saved'] ? __('Saved') : __('Cleared'),
            variant: $result['saved'] ? 'success' : 'info',
        );
    }

    /**
     * Why there is nothing to read, when there is nothing to read.
     *
     * Reads the fetch ledger so a source that could not answer is not reported
     * as a source that answered and had nothing. Null while a fetch is still in
     * flight or has never run.
     *
     * @return array{0: string, 1: string}|null
     */
    #[Computed]
    public function emptyReason(): ?array
    {
        return $this->data->emptyReason($this->manga, $this->loadError);
    }

    #[Computed]
    public function lastPage(): int
    {
        return $this->data->lastPage($this->visibleCount);
    }

    #[Computed]
    public function readCount(): int
    {
        return $this->data->readCount($this->chapters);
    }

    /**
     * The cover to render: the reader's own if they set one, otherwise the source's.
     */
    #[Computed]
    public function coverUrl(): ?string
    {
        return $this->data->coverUrl($this->manga, $this->customCover);
    }

    /**
     * This series' resolved reader settings.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function readingSettings(): array
    {
        return $this->data->readingSettings($this->manga);
    }

    /**
     * Continue reading, if there is somewhere to continue from.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function resumeChapter(): ?array
    {
        return $this->data->resumeChapter($this->state, $this->chapters, $this->order);
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    /**
     * Whether a chapter can be opened. pageCount is -1 until the reader fetches
     * it, so this is never a reason to hide the control.
     */
    // Downloads ------------------------------------------------------------

    /**
     * Whether the server has this chapter's pages on disk.
     *
     * From the chapter list we already fetched, not a query per chapter.
     */
    public function isDownloaded(int $chapterId): bool
    {
        return $this->data->isDownloaded($this->chapters, $chapterId);
    }

    /**
     * This reader's download state for a chapter, or null when there is none.
     *
     * One query for the visible page's chapters rather than one per row.
     */
    public function downloadState(int $chapterId): ?string
    {
        return $this->data->downloadState($this->chapters, $chapterId);
    }

    /**
     * Queue one chapter.
     */
    public function downloadChapter(int $chapterId): void
    {
        $result = $this->downloads->enqueue(auth()->user(), [$chapterId], $this->manga);

        Flux::toast(
            text: $result['queued'] > 0
                ? __('It will read without the source once it finishes.')
                : __('Suwayomi would not accept the request.'),
            heading: $result['queued'] > 0 ? __('Queued') : __('Not queued'),
            variant: $result['queued'] > 0 ? 'success' : 'warning',
        );

        $this->data->forgetDownloadStates();
    }

    /**
     * Queue every chapter this reader has not read.
     *
     * Uses our own read state, not the server's. `ChapterType.isRead` belongs to
     * Admin(1) and has never meant anything to a reader of this app. Read state is
     * loaded for the whole filtered list, not the open page, because this control
     * queues across every page.
     */
    public function downloadUnread(): void
    {
        $ids = [];
        $states = $this->allChapterStates;

        foreach ($this->chapters as $chapter) {
            $chapterId = (int) $chapter['id'];

            if ((bool) ($chapter['isDownloaded'] ?? false)) {
                continue;
            }

            $state = $states[$chapterId] ?? null;

            if ($state?->is_read) {
                continue;
            }

            $ids[] = $chapterId;
        }

        if ($ids === []) {
            Flux::toast(
                text: __('Nothing unread and not already on disk.'),
                heading: __('Nothing to queue'),
                variant: 'info',
            );

            return;
        }

        $result = $this->downloads->enqueue(auth()->user(), $ids, $this->manga);

        Flux::toast(
            text: $result['queued'] > 0
                ? trans_choice(
                    ':count chapter queued|:count chapters queued',
                    $result['queued'],
                    ['count' => $result['queued']]
                )
                : __('Suwayomi would not accept them.'),
            heading: $result['queued'] > 0 ? __('Queued') : __('Not queued'),
            variant: $result['queued'] > 0 ? 'success' : 'warning',
        );

        $this->data->forgetDownloadStates();
    }

    /** How many chapters would "download unread" queue right now. */
    public function unreadDownloadableCount(): int
    {
        return $this->data->unreadDownloadableCount($this->chapters, $this->allChapterStates);
    }
}; ?>

<div class="w-full space-y-7">
    @php($series = $this->series)

    @if ($this->seriesError !== null)
        {{--
            A verdict the branch below must not swallow: the server said nothing at
            all, so nothing here is knowledge about the manga id, and calling it "not
            in the catalogue" would send the reader looking for a series that may be
            sitting on their shelf.
        --}}
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:heading level="2" class="mb-1">{{ __('This series could not be read') }}</flux:heading>
            <p class="text-sm">{{ $this->seriesError }}</p>
        </flux:callout>
    @elseif ($series === null)
        <flux:callout variant="secondary" icon="question-mark-circle">
            <flux:heading level="2" class="mb-1">{{ __('This series is not in the catalogue') }}</flux:heading>
            <p class="text-sm">
                {{ __('Suwayomi does not know this manga id. It may have been removed, or the series may never have been opened.') }}
            </p>
        </flux:callout>
    @else
        {{--
            The hero.

            A panel rather than a full bleed, because the reader scrolls into a
            chapter list below and a hard cut from banner to content reads better
            than a floating banner. The backdrop is the cover itself, blown up and
            blurred, with scrims so the title survives any artwork.
        --}}
        <section class="relative overflow-hidden rounded-3xl border border-zinc-200/80 dark:border-white/[0.06]" data-test="series-hero">
            <div class="absolute inset-0" aria-hidden="true">
                @if ($cover = $this->coverUrl)
                    <img
                        src="{{ $cover }}"
                        alt=""
                        loading="lazy"
                        class="h-full w-full scale-110 object-cover opacity-40 blur-2xl saturate-150 dark:opacity-30"
                    />
                @endif

                <div class="absolute inset-0 bg-linear-to-b from-zinc-50/70 via-zinc-50/85 to-zinc-100 dark:from-zinc-950/60 dark:via-zinc-950/85 dark:to-zinc-950"></div>
                <div class="absolute inset-0 bg-linear-to-tr from-transparent via-transparent to-vermilion-500/[0.06]"></div>
            </div>

            <div class="relative flex flex-col gap-7 p-5 sm:flex-row sm:p-7">
                <div class="flex shrink-0 flex-col gap-2.5">
                    {{--
                        The morph destination. A cover pressed in any grid carries
                        `view-transition-name` here, so the artwork the reader was
                        just looking at slides into its frame instead of the page
                        merely fading in.
                    --}}
                    <x-cover.art
                        :src="$this->coverUrl"
                        :glow="true"
                        :vt="false"
                        class="vt-cover-hero w-40 sm:w-48 md:w-52"
                        data-test="series-cover"
                    ></x-cover.art>

                    {{--
                        The reader's own cover.

                        Under the cover rather than in the settings row, because the reader is
                        looking at the cover while deciding whether to replace it, and a preview
                        they have to open a dialog to see is not a preview.

                        The file input is a label, so the whole box is the target and the control
                        is keyboard reachable without a hidden input to focus. The remove button
                        only exists when there is something to remove: a control that appears and
                        disappears is a control a reader cannot learn.
                    --}}
                    <div class="w-full sm:w-48 md:w-52">
                        <input
                            type="file"
                            id="cover-upload"
                            wire:model="coverUpload"
                            accept="image/jpeg,image/png,image/webp"
                            aria-label="{{ $this->customCover ? __('Change cover') : __('Use your own cover') }}"
                            class="sr-only"
                        />

                        <label
                            for="cover-upload"
                            class="flex cursor-pointer items-center justify-center gap-2 rounded-full border border-zinc-300/90 px-3 py-2 text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-100 focus-within:ring-2 focus-within:ring-vermilion-500 dark:border-white/10 dark:text-zinc-200 dark:hover:bg-white/5"
                        >
                            <flux:icon name="photo" class="size-4 shrink-0" />

                            @if ($this->customCover)
                                <span>{{ __('Change cover') }}</span>
                            @else
                                <span>{{ __('Use your own cover') }}</span>
                            @endif
                        </label>

                        {{--
                            Upload progress, which is why this goes through Livewire rather than
                            a plain form post: a reader uploading a photo gets a real bar instead
                            of a button that goes quiet for two seconds.
                        --}}
                        @if ($coverUpload)
                            <div wire:loading wire:target="coverUpload,saveCover" class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                <div class="h-1 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                                    <div class="h-full w-full origin-left animate-pulse bg-vermilion-500"></div>
                                </div>

                                <p class="mt-1">{{ __('Uploading...') }}</p>
                            </div>
                        @endif

                        @error('coverUpload')
                            <p class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror

                        @if ($this->customCover)
                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click="removeCover"
                                wire:loading.attr="disabled"
                                class="mt-1.5 w-full rounded-full!"
                            >
                                {{ __('Use the source cover') }}
                            </flux:button>
                        @endif
                    </div>
                </div>

                <div class="min-w-0 flex-1">
                    {{--
                        The source name, linked to that source's own catalogue.

                        A reader who came here from a source, or who wants more from the same one,
                        has nowhere to go: the name was plain text. Search covers 40 of the 82
                        sources and this one may not be among them, so the source page is the only
                        reliable way back to everything that site lists.

                        Resolved through the mirror rather than taken from the payload, because the
                        browse route is keyed on the mirror's own id and the payload carries
                        Suwayomi's. A series whose source is not in the mirror renders plain text,
                        which is the honest fallback: the link would 404 otherwise.
                    --}}
                    @php($sourceMirror = $this->sourceMirror())

                    <p class="serif-italic text-sm text-zinc-500 dark:text-zinc-400">
                        @if ($sourceMirror !== null)
                            <a
                                href="{{ route('browse', ['source' => $sourceMirror->id]) }}"
                                wire:navigate
                                data-test="source-link"
                                class="transition-colors hover:text-vermilion-600 dark:hover:text-vermilion-400"
                            >
                                {{ $series['source']['name'] ?? $sourceMirror->display_name }}
                            </a>
                        @else
                            {{ $series['source']['name'] ?? __('Unknown source') }}
                        @endif

                        @if (($series['source']['lang'] ?? null) !== null)
                            <span class="uppercase">· {{ $series['source']['lang'] }}</span>
                        @endif
                    </p>

                    <h1 class="display-page mt-1 text-zinc-900 dark:text-zinc-50">
                        {{ $series['title'] }}
                    </h1>

                    <div class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
                        {{--
                            The status badge, hidden while the server does not know.

                            `status` is only real once the manga record has been fetched. A series
                            that has never been fetched answers UNKNOWN, and so does every row of
                            every listing until something fetches it: measured across 122 rows
                            from five sources, all UNKNOWN, and all of them real the moment
                            `fetchMangaAndChapters` ran.

                            So the badge used to sit there saying UNKNOWN, which is the word for
                            "I have not asked yet". Now that the page fetches on load, the
                            badge appears on its own once the answer arrives, and this page shows
                            it during the fetch that the reader can already see happening.
                        --}}
                        @php($status = strtoupper((string) ($series['status'] ?? '')))

                        @if ($status !== '' && $status !== 'UNKNOWN')
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-moss-500/40 bg-moss-500/10 px-2.5 py-0.5 text-xs font-medium text-moss-600 dark:border-moss-400/30 dark:bg-moss-400/10 dark:text-moss-400">
                                <span class="size-1.5 rounded-full bg-moss-500" aria-hidden="true"></span>
                                {{ str_replace('_', ' ', (string) $series['status']) }}
                            </span>
                        @endif

                        @if (filled($series['author'] ?? null) || filled($series['artist'] ?? null))
                            <span class="text-zinc-600 dark:text-zinc-300">
                                @if (filled($series['author'] ?? null))
                                    {{ $series['author'] }}
                                @endif

                                @if (filled($series['artist'] ?? null) && $series['artist'] !== ($series['author'] ?? null))
                                    <span class="text-zinc-400">({{ $series['artist'] }})</span>
                                @endif
                            </span>
                        @endif
                    </div>

                    @if (filled($series['genre'] ?? null))
                        <div class="mt-3.5 flex flex-wrap gap-1.5">
                            @foreach (array_slice($series['genre'], 0, 10) as $genre)
                                <span class="rounded-full border border-zinc-300/80 px-2.5 py-0.5 text-xs font-medium text-zinc-600 dark:border-white/10 dark:bg-white/[0.03] dark:text-zinc-300">
                                    {{ $genre }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    {{-- Actions. One primary Resume, then grouped Library and Offline. --}}
                    <div class="mt-5 space-y-3" data-test="series-actions">
                        @if ($resume = $this->resumeChapter)
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:button
                                    variant="primary"
                                    icon="book-open"
                                    :href="$this->formatting->chapterUrl($this->manga, (int) $resume['id'])"
                                    wire:navigate
                                    data-test="resume-action"
                                >
                                    @if ($this->state->last_read_chapter_id !== null)
                                        {{ __('Continue reading') }}
                                    @else
                                        {{ __('Start reading') }}
                                    @endif
                                </flux:button>

                                <span class="min-w-0 truncate text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ filled($resume['name'] ?? null) ? $resume['name'] : __('Chapter :number', ['number' => $resume['chapterNumber'] ?? '?']) }}
                                </span>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-start gap-x-6 gap-y-3">
                            <div class="flex flex-col gap-1.5" role="group" aria-label="{{ __('Library') }}" data-test="library-group">
                                <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Library') }}</p>

                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:button
                                        :variant="$this->state->in_library ? 'filled' : 'outline'"
                                        icon="bookmark"
                                        wire:click="toggleLibrary"
                                        wire:loading.attr="disabled"
                                        data-tour="add-to-library"
                                    >
                                        {{ $this->state->in_library ? __('In library') : __('Add to library') }}
                                    </flux:button>

                                    <flux:button
                                        :variant="$this->state->favourited_at ? 'filled' : 'outline'"
                                        icon="heart"
                                        wire:click="toggleFavourite"
                                        aria-label="{{ __('Favourite') }}"
                                        aria-pressed="{{ $this->state->favourited_at ? 'true' : 'false' }}"
                                    />

                                    <flux:button
                                        :variant="$this->state->read_later_at ? 'filled' : 'outline'"
                                        icon="clock"
                                        wire:click="toggleReadLater"
                                        aria-label="{{ __('Read later') }}"
                                        aria-pressed="{{ $this->state->read_later_at ? 'true' : 'false' }}"
                                    />

                                    {{-- The rating, in the same row as the two binary preferences beside it
                                         and for the same reason: they are the three things a reader
                                         decides about a series before deciding to keep it.

                                         It is a star row rather than a dropdown because a rating is an
                                         absolute judgement and a dropdown hides the scale until it is
                                         open. There are five possible answers and all five are worth
                                         seeing at once. --}}
                                    <div class="flex items-center">
                                        <x-rating.stars :value="$this->rating" />

                                        @if ($this->rating !== null)
                                            {{-- Clear, as its own control.

                                                 Tapping the lit star again does clear it, and that is the
                                                 fastest way to do it. But "press the same star twice" is
                                                 not something a reader discovers from the shape of five
                                                 stars, and a rating they cannot see how to remove is a
                                                 rating they may not want to have set at all. The button
                                                 costs one icon. --}}
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                wire:click="clearRating"
                                                aria-label="{{ __('Clear your rating') }}"
                                                class="-ml-1"
                                            >
                                                <flux:icon name="x-mark" class="size-3.5" />
                                            </flux:button>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-1.5" role="group" aria-label="{{ __('Offline') }}" data-test="offline-group">
                                <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Offline') }}</p>

                                <div class="flex flex-wrap items-center gap-2">
                                    {{--
                                        Download unread.

                                        Uses our read state, not Suwayomi's: `ChapterType.isRead`
                                        belongs to Admin(1) and has never meant anything to a reader
                                        of this app. Skips anything already on disk, so pressing it
                                        twice does not requeue thirty chapters.
                                    --}}
                                    <flux:button
                                        size="sm"
                                        variant="outline"
                                        icon="cloud-arrow-down"
                                        wire:click="downloadUnread"
                                        wire:loading.attr="disabled"
                                        :disabled="$this->unreadDownloadableCount() === 0"
                                    >
                                        {{ __('Download unread') }}
                                        @if ($this->unreadDownloadableCount() > 0)
                                            <span class="ms-1 text-xs opacity-70 tabular-nums">{{ $this->unreadDownloadableCount() }}</span>
                                        @endif
                                    </flux:button>

                                    <flux:button size="sm" variant="outline" wire:click="refresh" wire:loading.attr="disabled" icon="arrow-path">
                                        {{ __('Refresh chapters') }}
                                    </flux:button>

                                    {{--
                                        Move to another source.

                                        A link rather than a button, because it leaves the page: the
                                        wizard searches 82 sources, which measured 21.9 seconds, and
                                        that is a different screen with its own progress to report.
                                    --}}
                                    <flux:button
                                        size="sm"
                                        variant="outline"
                                        icon="arrow-right-circle"
                                        href="{{ route('migrate', ['manga' => $this->manga]) }}"
                                        wire:navigate
                                    >
                                        {{ __('Move source') }}
                                    </flux:button>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if (filled($series['description'] ?? null))
                        <details class="group mt-4 max-w-3xl text-sm">
                            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 text-zinc-500 transition-colors hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                                {{ __('Description') }}
                                <flux:icon name="chevron-down" class="size-3.5 transition-transform group-open:rotate-180" />
                            </summary>
                            <p class="mt-2 leading-relaxed whitespace-pre-line text-zinc-700 dark:text-zinc-200">
                                {{ $series['description'] }}
                            </p>
                        </details>
                    @endif

                    {{-- Tags, folded away.

                         Under a disclosure rather than in the action row, because a tag is a
                         label rather than one of the three decisions and a row of chips plus
                         an input would outrank the favourite button on a page whose hero is
                         cover, title and actions.

                         Open when there is already something in it, and closed when there is
                         not: an empty editor nobody asked for is noise, and a reader who has
                         already tagged something can see the count in the summary without
                         opening anything to learn it.

                         The condition is a plain ternary and NOT `@open(...)`, which looks
                         like it should work. Measured on Laravel 13.34.0: `@open` is not a
                         registered Blade directive, so the compiler leaves it untouched, it
                         reaches the browser as a junk attribute, and the details element stays
                         closed. No error, no warning, and the reader simply finds the panel
                         shut. Trap 153. --}}
                    <details class="group mt-3 max-w-3xl text-sm" {{ $this->tags !== [] ? 'open' : '' }}>
                        <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 text-zinc-500 transition-colors hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
                            @if ($this->tags === [])
                                {{ __('Tags') }}
                            @else
                                {{ trans_choice(':count tag|[2,*] :count tags', count($this->tags), ['count' => count($this->tags)]) }}
                            @endif
                            <flux:icon name="chevron-down" class="size-3.5 transition-transform group-open:rotate-180" />
                        </summary>

                        <div class="mt-2">
                            <x-tags.editor :tags="$this->tags" />
                        </div>
                    </details>

                    @if (filled($series['realUrl'] ?? null))
                        <a
                            href="{{ $series['realUrl'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-3 inline-flex items-center gap-1 text-sm text-zinc-500 underline-offset-2 hover:underline dark:text-zinc-400"
                        >
                            {{ __('View on source') }}
                            <flux:icon name="arrow-top-right-on-square" class="size-3.5" />
                        </a>
                    @endif
                </div>
            </div>
        </section>

        {{-- Chapters ------------------------------------------------------- --}}
        <section data-tour="read-chapter" class="space-y-4">
            <div class="sticky top-0 z-20 -mx-1 border-b border-zinc-200/70 bg-zinc-50/90 px-1 py-2 backdrop-blur dark:border-white/[0.06] dark:bg-zinc-950/85" data-test="chapter-filter">
                <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                    <div class="flex items-baseline gap-3">
                        <flux:heading level="2" class="font-display font-semibold tracking-tight">{{ __('Chapters') }}</flux:heading>

                        <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-600 tabular-nums dark:bg-white/[0.06] dark:text-zinc-300">
                            {{ Number::format($this->readCount) }} / {{ Number::format($this->visibleCount) }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        {{--
                            Reading mode is per series rather than global, because the
                            case for it is always an exception: night mode everywhere
                            except the one series with white pages, paged everywhere
                            except the long webtoons.
                        --}}
                        <flux:button.group>
                            <flux:button
                                :variant="$this->readingSettings['mode'] === 'vertical' ? 'primary' : 'ghost'"
                                wire:click="setReadingMode('vertical')"
                                size="sm"
                                icon="bars-3-bottom-left"
                            >
                                {{ __('Scroll') }}
                            </flux:button>
                            <flux:button
                                :variant="$this->readingSettings['mode'] === 'paged' ? 'primary' : 'ghost'"
                                wire:click="setReadingMode('paged')"
                                size="sm"
                                icon="rectangle-stack"
                            >
                                {{ __('Paged') }}
                            </flux:button>
                        </flux:button.group>

                        <flux:button.group>
                            <flux:button
                                :variant="$order === 'asc' ? 'primary' : 'ghost'"
                                wire:click="setOrder('asc')"
                                size="sm"
                            >
                                {{ __('Oldest') }}
                            </flux:button>
                            <flux:button
                                :variant="$order === 'desc' ? 'primary' : 'ghost'"
                                wire:click="setOrder('desc')"
                                size="sm"
                            >
                                {{ __('Newest') }}
                            </flux:button>
                        </flux:button.group>

                        {{--
                            Read state, set by hand.

                            A reader arriving from another app has read chapters this one
                            has never seen opened. The per-chapter toggle is the check on
                            each row; these two are the whole-list version of the same
                            gesture, and the unread one confirms because it clears every
                            flag on the series in one press.
                        --}}
                        <flux:button
                            size="sm"
                            variant="outline"
                            icon="check"
                            wire:click="markAllRead"
                            wire:loading.attr="disabled"
                        >
                            {{ __('Mark all read') }}
                        </flux:button>

                        <flux:button
                            size="sm"
                            variant="outline"
                            icon="arrow-uturn-left"
                            wire:click="markAllUnread"
                            wire:loading.attr="disabled"
                            wire:confirm="{{ __('Mark every chapter unread? The read flags on this series clear.') }}"
                        >
                            {{ __('Mark all unread') }}
                        </flux:button>
                    </div>
                </div>
            </div>

            {{--
                This and the empty-chapter callout below are two branches of one condition, and
                they used to disagree. This one printed a fixed sentence claiming the source
                answered and its listing was empty, which is true for `No chapters found` and
                false for every other failure: measured, a source answering
                `Cloudflare bypass currently disabled` was told here that it had answered.
                So both read `emptyReason()` and the text is chosen by what the fetch recorded.

                That agreement had its own defect, measured twice on 2026-10-16: with an error
                recorded and no chapters to show, both blocks fired and the same card rendered
                twice. This one renders only while the list it talks over is still on the page;
                the empty case keeps one card, and its retry.
            --}}
            @if ($loadError !== null && $this->chapterCount > 0)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:heading level="3" class="mb-1">{{ $this->emptyReason[0] }}</flux:heading>
                    <p class="text-sm">
                        {{ $this->emptyReason[1] }}
                    </p>
                </flux:callout>
            @endif

            {{-- Duplicate chapters from multiple groups are the reason this control exists. --}}
            @if ($this->scanlators !== [] && count($this->scanlators) > 1)
                <details class="rounded-lg border border-zinc-200 dark:border-white/10">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                        {{ __('Scanlators') }}
                        <span class="text-zinc-500">
                            {{ trans_choice(':count group|:count groups', count($this->scanlators), ['count' => count($this->scanlators)]) }}
                        </span>
                    </summary>

                    <div class="border-t border-zinc-200 px-4 py-3 dark:border-white/10">
                        <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('Hide duplicates from these groups. Chapters with no scanlator are always kept.') }}
                        </p>

                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->scanlators as $scanlator)
                                <flux:button
                                    size="sm"
                                    :variant="in_array($scanlator, $this->excludedScanlators, true) ? 'danger' : 'outline'"
                                    wire:click="$toggle('excludedScanlators')"
                                    wire:target="excludedScanlators"
                                >
                                    {{ $scanlator }}
                                </flux:button>
                            @endforeach
                        </div>
                    </div>
                </details>
            @endif

            @if ($this->fetchingChapters)
                {{--
                    The loading state, which is where a reader now spends the seconds the fetch
                    takes. Measured cold: Asura Scans 1.6s, Manga Demon 1.9s, Weeb Central 4.6s,
                    MangaFire 7.7s for 2,440 chapters. The page polls itself rather than the
                    server, and stops as soon as the chapter count is no longer zero.
                --}}
                <div
                    class="rounded-2xl border border-zinc-200/90 dark:border-white/[0.07]"
                    wire:poll.1500ms
                >
                    <div class="flex items-center gap-3 px-4 py-6">
                        <flux:icon name="arrow-path" class="size-5 shrink-0 animate-spin text-zinc-400" />

                        <div class="min-w-0">
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                                {{ __('Fetching chapters') }}
                            </p>

                            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('Asking the source for its chapter list. This can take a few seconds.') }}
                            </p>
                        </div>
                    </div>
                </div>
            @elseif ($this->chapterCount === 0)
                {{--
                    Only reached when a fetch was tried and produced nothing, or failed. The
                    earlier version of this told the reader to press Refresh because there was
                    nothing else to press; now the page has already tried on its own, so the
                    button is a retry rather than the first step.

                    The wording comes from `emptyReason()`, which reads what the fetch recorded.
                    One sentence for every case sent a reader to a source that could not answer
                    at all: measured, 4 of 8 zero-chapter series on the dev library had
                    "Cloudflare bypass currently disabled" recorded, and every one of the 8
                    was reported as having no chapters, which is a different and far more
                    hopeful claim.
                --}}
                <flux:callout variant="secondary" icon="book-open">
                    <flux:heading level="3" class="mb-1">
                        {{ $this->emptyReason[0] ?? __('No chapters yet') }}
                    </flux:heading>

                    <p class="text-sm">
                        {{ $this->emptyReason[1] ?? __('Asking the source for its chapter list. This can take a few seconds.') }}
                    </p>

                    <flux:button class="mt-3" size="sm" wire:click="refresh">
                        {{ __('Try again') }}
                    </flux:button>
                </flux:callout>
            @else
                {{--
                    Poll while a fetch is in flight and there is something already worth showing.

                    The loading panel below covers the case where the chapter list itself is
                    missing. This is the other case: a full chapter list with a status the server
                    has not supplied yet, where the reader can read the whole time. Without a poll
                    the badge would not appear until they reloaded, which is the half of the
                    report this is fixing.
                --}}
                <ul
                    class="overflow-hidden rounded-2xl border border-zinc-200/90 dark:border-white/[0.07]"
                    @if ($this->fetchingSeries && $this->chapterCount > 0)
                        wire:poll.2000ms
                    @endif
                >
                    @php([$from, $to] = $this->formatting->visibleRange($this->visibleCount, $this->page, self::PER_PAGE))

                    @foreach ($this->formatting->pageSlice($this->chapters, $this->page, self::PER_PAGE) as $chapter)
                        @php($state = $this->chapterStates[(int) $chapter['id']] ?? null)

                        <li
                            @if ($loop->first) data-tour="first-chapter" @endif
                            class="group flex items-center gap-3 border-b border-zinc-200/70 px-3.5 py-2.5 transition-colors last:border-b-0 hover:bg-zinc-100/60 sm:px-4 dark:border-white/[0.05] dark:hover:bg-white/[0.03]"
                        >
                            {{--
                                The read flag, as a control.

                                This was a passive dot for as long as read state could
                                only come from the progress endpoint. A reader carrying
                                a history from another app needs to state the opposite
                                of what the app believes, and the dot is where they
                                look for it.
                            --}}
                        @php($chapterName = filled($chapter['name'] ?? null)
                            ? $chapter['name']
                            : __('Chapter :number', ['number' => $chapter['chapterNumber'] ?? '?']))

                        <button
                            type="button"
                            wire:click="toggleRead({{ (int) $chapter['id'] }})"
                            wire:loading.attr="disabled"
                            data-test="read-toggle"
                            aria-pressed="{{ $state?->is_read ? 'true' : 'false' }}"
                            aria-label="{{ $state?->is_read
                                ? __('Mark :name unread', ['name' => $chapterName])
                                : __('Mark :name read', ['name' => $chapterName]) }}"
                            title="{{ $state?->is_read ? __('Mark unread') : __('Mark read') }}"
                                class="flex size-5 shrink-0 items-center justify-center rounded-full {{
                                    $state?->is_read
                                        ? 'bg-moss-500/15 text-moss-600 dark:text-moss-400'
                                        : 'bg-zinc-200/70 text-zinc-400 dark:bg-white/[0.06] dark:text-zinc-500'
                                }} transition-colors hover:bg-moss-500/25 dark:hover:bg-moss-500/20"
                            >
                                <flux:icon :name="$state?->is_read ? 'check' : 'minus'" class="size-3.5" />
                            </button>

                            <a
                                href="{{ $this->formatting->chapterUrl($this->manga, (int) $chapter['id']) }}"
                                wire:navigate
                                class="min-w-0 flex-1 truncate text-sm transition-colors {{ $state?->is_read ? 'text-zinc-400 dark:text-zinc-500' : 'font-medium text-zinc-800 hover:text-vermilion-600 dark:text-zinc-100 dark:hover:text-vermilion-400' }}"
                            >
                                <span @class(['line-through decoration-zinc-400/60 opacity-70' => $state?->is_read])>
                                    {{ filled($chapter['name']) ? $chapter['name'] : __('Chapter :number', ['number' => $chapter['chapterNumber'] ?? '?']) }}
                                </span>

                                @if (($scanlator = $this->formatting->scanlatorOf($chapter)) !== null)
                                    <span class="ml-2 text-xs font-normal text-zinc-400 dark:text-zinc-500">{{ $scanlator }}</span>
                                @endif
                            </a>

                            {{--
                                Offline state in plain words. `isDownloaded` is the server's own
                                flag, which is the only one that means anything:
                                it is about pages on disk, not about our read
                                state. The queued marker is ours.
                            --}}
                            @if ($this->isDownloaded((int) $chapter['id']))
                                <span
                                    data-test="download-pill"
                                    data-state="saved"
                                    title="{{ __('Ready without the source') }}"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-full border border-moss-500/40 bg-moss-500/10 px-2 py-0.5 text-xs font-medium text-moss-600 dark:border-moss-400/30 dark:bg-moss-400/10 dark:text-moss-400"
                                >
                                    {{ __('Saved') }}
                                </span>
                            @elseif ($this->downloadState((int) $chapter['id']) !== null)
                                <span
                                    data-test="download-pill"
                                    data-state="queued"
                                    title="{{ __('Queued for download') }}"
                                    class="inline-flex shrink-0 items-center gap-1 rounded-full border border-vermilion-500/40 bg-vermilion-500/10 px-2 py-0.5 text-xs font-medium text-vermilion-600 dark:text-vermilion-400"
                                >
                                    <span class="size-1.5 animate-breathe rounded-full bg-vermilion-500" aria-hidden="true"></span>
                                    {{ __('Queued') }}
                                </span>
                            @else
                                <flux:button
                                    size="xs"
                                    variant="outline"
                                    wire:click="downloadChapter({{ (int) $chapter['id'] }})"
                                    aria-label="{{ __('Download this chapter') }}"
                                    title="{{ __('Download this chapter') }}"
                                    data-test="download-pill"
                                    data-state="get"
                                    class="shrink-0 rounded-full! px-2.5!"
                                >
                                    {{ __('Get') }}
                                </flux:button>
                            @endif

                            {{--
                                The note, as a marker and a way in.

                                Placed after the download pill and before the date,
                                because both of those are about the file and this is
                                about the reader's own words.

                                A native title rather than a Flux tooltip, and that is
                                not a simplification. Wrapped in a tooltip, the button
                                was visually present and unclickable: Playwright reported
                                "intercepts pointer events", which for a reader is a
                                button that does nothing. The tooltips elsewhere in this
                                row wrap a non-interactive icon, and a tooltip around a
                                control has to be click-through to be worth having.
                            --}}
                            @php($note = $this->chapterNotes()[(int) $chapter['id']] ?? null)
                            <flux:button
                                size="xs"
                                variant="ghost"
                                icon="pencil-square"
                                wire:click="openNote({{ (int) $chapter['id'] }})"
                                aria-label="{{ $note !== null ? __('Read your note on this chapter') : __('Add a note on this chapter') }}"
                                title="{{ $note !== null ? __('Read your note') : __('Add a note') }}"
                                data-test="note-button"
                                @class(['text-vermilion-500 dark:text-vermilion-400' => $note !== null])
                            ></flux:button>

                            <span class="hidden shrink-0 text-xs text-zinc-400 tabular-nums dark:text-zinc-500 sm:block">
                                @if ($chapter['uploadDate'] !== null)
                                    {{ $chapter['uploadDate']->format('Y-m-d') }}
                                @endif
                            </span>
                        </li>

                        {{--
                            The note editor, inline under the row it belongs to.

                            One row grows rather than a dialog over the page, so the
                            chapter being written about stays visible while it is written
                            about. That is the whole reason this is not a modal: a reader
                            needs to see which of 49 chapters the sentence belongs to
                            while they type it.

                            Keyed on the chapter id, so only the open chapter shows an
                            editor and moving to another chapter moves the editor rather
                            than leaving two of them open.
                        --}}
                        @if ($this->noteEditing === (int) $chapter['id'])
                            <li class="border-b border-zinc-200/70 bg-zinc-50 px-4 py-3.5 last:border-b-0 dark:border-white/[0.05] dark:bg-white/[0.03]">
                                <div class="flex flex-col gap-2">
                                    <label
                                        for="chapter-note-{{ (int) $chapter['id'] }}"
                                        class="text-xs font-medium text-zinc-600 dark:text-zinc-300"
                                    >
                                        {{ __('Note on this chapter') }}
                                    </label>

                                    <textarea
                                        id="chapter-note-{{ (int) $chapter['id'] }}"
                                        wire:model="noteBody"
                                        rows="4"
                                        maxlength="10000"
                                        placeholder="{{ __('What do you want to remember about this chapter?') }}"
                                        class="w-full rounded-xl border border-zinc-300 bg-white p-3 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-vermilion-500 focus:outline-none focus:ring-2 focus:ring-vermilion-500/30 dark:border-white/10 dark:bg-zinc-950 dark:text-zinc-100"
                                    ></textarea>

                                    <p class="-mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ __('Saved on this chapter only. Empty clears it.') }}
                                    </p>

                                    <div class="flex items-center justify-end gap-2">
                                        <flux:button size="xs" variant="ghost" wire:click="closeNote">
                                            {{ __('Close') }}
                                        </flux:button>

                                        <flux:button
                                            size="xs"
                                            variant="primary"
                                            wire:click="saveNoteFromEditor"
                                            wire:loading.attr="disabled"
                                        >
                                            {{ __('Save note') }}
                                        </flux:button>
                                    </div>
                                </div>
                            </li>
                        @endif
                    @endforeach
                </ul>

                {{--
        The note editor.

        One modal for the whole page, not one per row: 107 chapters would mean 107
        modals and 107 copies of a textarea, and the chapter is carried on the event
        instead. A modal rather than an inline expansion because a chapter row is 40px
        tall and this is a paragraph of prose.

        The textarea is seeded from the page's own data rather than fetched, so opening
        the editor for a chapter that has a note does not need a round trip. That is the
        same chapterNotes() array the markers read, so a marker and an editor cannot
        disagree about whether a note exists.
    --}}
    @if ($this->lastPage > 1)
                    <div class="flex items-center justify-between gap-4">
                        <flux:button size="sm" wire:click="previousPage" :disabled="$page <= 1" icon="chevron-left">
                            {{ __('Previous') }}
                        </flux:button>

                        <span class="text-sm text-zinc-500 tabular-nums dark:text-zinc-400">
                            {{ trans_choice(
                                'Showing :count chapter|Showing :count chapters',
                                $this->visibleCount,
                                ['count' => Number::format($this->visibleCount)],
                            ) }}
                        </span>

                        <flux:button size="sm" wire:click="nextPage" :disabled="$page >= $this->lastPage" icon="chevron-right">
                            {{ __('Next') }}
                        </flux:button>
                    </div>
                @endif
            @endif
        </section>
    @endif
</div>