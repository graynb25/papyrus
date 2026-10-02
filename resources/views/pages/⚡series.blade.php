<?php

use App\Suwayomi\SeriesGateway;
use App\Suwayomi\SuwayomiException;
use App\Downloads\DownloadLedger;
use App\Models\Download;
use App\Support\ChapterNotes;
use App\Support\ImageUrl;
use App\Support\ReadingState;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Series')] class extends Component {
    /*
     * Upload progress on the cover file input, which is the reason the upload comes
     * through Livewire rather than a plain form post to its own route: the browser gets a
     * real progress bar instead of a button that goes quiet for two seconds.
     */
    use WithFileUploads;

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
    }

    /**
     * Ascending, which is Mihon's default and the order a reader wants when
     * catching up.
     */
    public string $order = 'asc';

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

    public const PER_PAGE = 50;

    /**
     * Notified when the server has no chapters for this series.
     */
    public ?string $loadError = null;

    /**
     * Ask the server to re-read the series and its chapter list.
     *
     * Expensive, so it is a button rather than something that happens on load. A
     * series nobody has opened has no chapter list at all, and silently showing an
     * empty page would look like a broken app rather than an unpopulated cache.
     */
    public function refresh(): void
    {
        try {
            $result = app(SeriesGateway::class)->refresh($this->manga);
        } catch (SuwayomiException $e) {
            Flux::error($e->getMessage());

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

        app(ReadingState::class)->setInLibrary(auth()->user(), $this->manga, $inLibrary);

        Flux::toast(
            text: $inLibrary
                ? __('Added to your library.')
                : __('Removed from your library.'),
            heading: $inLibrary ? __('In library') : __('Removed'),
            variant: $inLibrary ? 'success' : 'info',
        );
    }

    public function toggleFavourite(): void
    {
        $favourite = ! $this->state->favourited_at;

        app(ReadingState::class)->setFavourited(auth()->user(), $this->manga, $favourite);
    }

    public function toggleReadLater(): void
    {
        $queued = ! $this->state->read_later_at;

        app(ReadingState::class)->setReadLater(auth()->user(), $this->manga, $queued);
    }

    public function setOrder(string $order): void
    {
        if (in_array($order, ['asc', 'desc'], true)) {
            $this->order = $order;
            $this->page = 1;
        }
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
        app(\App\Support\ReaderSettings::class)->setSeriesOverride(
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
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function series(): ?array
    {
        return app(SeriesGateway::class)->find($this->manga);
    }

    /**
     * The user's row for this series.
     */
    #[Computed]
    public function state(): \App\Models\SeriesState
    {
        return app(ReadingState::class)->findSeries(auth()->user(), $this->manga)
            ?? app(ReadingState::class)->series(auth()->user(), $this->manga);
    }

    /**
     * Every chapter the server has, before scanlator filtering and ordering.
     *
     * `manga(id:).chapters` takes no arguments at all, so the whole list arrives
     * in one response. Filtering and paging happen here.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function allChapters(): array
    {
        return $this->series['chapters']['nodes'] ?? [];
    }

    #[Computed]
    public function chapterCount(): int
    {
        return count($this->allChapters);
    }

    /**
     * Scanlators present in this series, for the exclusion control.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function scanlators(): array
    {
        return collect($this->allChapters)
            ->map(fn (array $c): ?string => $c['scanlator'] ?? null)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Chapters after scanlator filtering and ordering.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function chapters(): array
    {
        $excluded = array_map('mb_strtolower', $this->excludedScanlators);

        $filtered = array_values(array_filter($this->allChapters, function (array $chapter) use ($excluded): bool {
            $scanlator = $chapter['scanlator'] ?? null;

            // A chapter with no scanlator is never excluded, since there is
            // nothing to match against and dropping those would lose chapters.
            if (! is_string($scanlator) || $scanlator === '') {
                return true;
            }

            return ! in_array(mb_strtolower($scanlator), $excluded, true);
        }));

        usort($filtered, function (array $a, array $b): int {
            // sourceOrder is the source's own ordering, which respects volumes
            // that a bare chapterNumber sort would flatten. chapterNumber breaks
            // ties so a source with equal sourceOrder still sorts deterministically.
            $byOrder = ($a['sourceOrder'] ?? 0) <=> ($b['sourceOrder'] ?? 0);

            return $byOrder !== 0
                ? $byOrder
                : (($a['chapterNumber'] ?? 0) <=> ($b['chapterNumber'] ?? 0));
        });

        if ($this->order === 'desc') {
            $filtered = array_reverse($filtered);
        }

        return $filtered;
    }

    #[Computed]
    public function visibleCount(): int
    {
        return count($this->chapters);
    }

    /**
     * @return array<int, \App\Models\ChapterState>
     */
    #[Computed]
    public function chapterStates(): array
    {
        $ids = array_map(
            static fn (array $c): int => (int) ($c['id'] ?? 0),
            array_slice($this->chapters, ($this->page - 1) * self::PER_PAGE, self::PER_PAGE),
        );

        return app(ReadingState::class)->chapterStates(auth()->user(), $ids);
    }

    /**
     * This reader's notes on the chapters on this page.
     *
     * Scoped to the visible page rather than the whole series for the same reason
     * `chapterStates()` is: 107 chapters would otherwise be one note lookup each, and
     * the reader cannot see 107 chapters at once either.
     *
     * @return array<int, \App\Models\Note>
     */
    #[Computed]
    public function chapterNotes(): array
    {
        $ids = array_map(
            static fn (array $c): int => (int) ($c['id'] ?? 0),
            array_slice($this->chapters, ($this->page - 1) * self::PER_PAGE, self::PER_PAGE),
        );

        return app(ChapterNotes::class)->forChapters(auth()->user(), $ids);
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
        return app(\App\Covers\CoverStore::class)->find(auth()->user(), $this->manga);
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
        $this->validate([
            'coverUpload' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',

                /*
                 * The byte ceiling is this server's own upload_max_filesize, which is 2M
                 * in php.ini, so the number matches reality rather than a wish. A reader
                 * who hits it is being stopped by the server, not by a rule they cannot
                 * see, and the message says which.
                 */
                'max:'.(int) config('papyrus.covers.max_kilobytes'),
            ],
        ], [
            'coverUpload.max' => 'A cover has to be under :max KB, which is this server\'s upload limit. Resize it and try again.',
            'coverUpload.mimes' => 'A cover has to be a JPEG, PNG or WebP.',
        ]);

        try {
            app(\App\Covers\CoverStore::class)->store(auth()->user(), $this->manga, $this->coverUpload);
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
        $removed = app(\App\Covers\CoverStore::class)->remove(auth()->user(), $this->manga);

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
        $result = app(ChapterNotes::class)->save(
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

    #[Computed]
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->visibleCount / self::PER_PAGE));
    }

    #[Computed]
    public function readCount(): int
    {
        $ids = array_map(static fn (array $c): int => (int) ($c['id'] ?? 0), $this->chapters);

        return app(ReadingState::class)->readCount($this->chapters, app(ReadingState::class)->chapterStates(auth()->user(), $ids));
    }

    /**
     * The cover to render: the reader's own if they set one, otherwise the source's.
     *
     * One accessor for both, so the template cannot ask for the wrong one. Measured,
     * source covers are already served through a Laravel route, so a custom cover on its
     * own route is no new cost class.
     */
    #[Computed]
    public function coverUrl(): ?string
    {
        if ($this->customCover() !== null) {
            return route('covers.show', ['manga' => $this->manga]);
        }

        return ImageUrl::thumbnail($this->manga, ImageUrl::defaultFormat());
    }

    /**
     * This series' resolved reader settings.
     *
     * Resolved the same way the reader resolves them, so the mode shown here is
     * the mode the reader will open in.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function readingSettings(): array
    {
        return app(\App\Support\ReaderSettings::class)->resolve(auth()->user(), $this->manga);
    }

    /**
     * Continue reading, if there is somewhere to continue from.
     *
     * Prefers the recorded pointer, then the newest chapter, because a series
     * opened for the first time has no pointer and "start reading" should still
     * work.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function resumeChapter(): ?array
    {
        $pointer = $this->state->last_read_chapter_id;

        if ($pointer !== null) {
            $match = collect($this->chapters)->first(fn (array $c): bool => (int) $c['id'] === $pointer);

            if ($match !== null) {
                return $match;
            }
        }

        $ordered = $this->order === 'asc' ? $this->chapters : array_reverse($this->chapters);

        return $ordered[0] ?? null;
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
     * From the chapter list we already fetched, not a query per chapter. The
     * series page renders fifty rows and asking Suwayomi fifty times would be the
     * same mistake the library page made once already.
     */
    public function isDownloaded(int $chapterId): bool
    {
        foreach ($this->chapters as $chapter) {
            if ((int) $chapter['id'] === $chapterId) {
                return (bool) ($chapter['isDownloaded'] ?? false);
            }
        }

        return false;
    }

    /**
     * This reader's download state for a chapter, or null when there is none.
     *
     * One query for the visible page's chapters rather than one per row, and read
     * from the map the render already built.
     */
    public function downloadState(int $chapterId): ?string
    {
        $this->loadDownloadStates();

        return $this->downloadStatesFor[$chapterId] ?? null;
    }

    /**
     * Queue one chapter.
     */
    public function downloadChapter(int $chapterId): void
    {
        $result = app(DownloadLedger::class)->enqueue(auth()->user(), [$chapterId], $this->manga);

        Flux::toast(
            text: $result['queued'] > 0
                ? __('It will read without the source once it finishes.')
                : __('Suwayomi would not accept the request.'),
            heading: $result['queued'] > 0 ? __('Queued') : __('Not queued'),
            variant: $result['queued'] > 0 ? 'success' : 'warning',
        );

        $this->downloadStatesFor = [];
        $this->downloadStatesLoaded = false;
    }

    /**
     * Queue every chapter this reader has not read.
     *
     * Uses our own read state, not the server's. `ChapterType.isRead` belongs to
     * Admin(1) and has never meant anything to a reader of this app.
     */
    public function downloadUnread(): void
    {
        $ids = [];

        foreach ($this->chapters as $chapter) {
            $chapterId = (int) $chapter['id'];

            if ((bool) ($chapter['isDownloaded'] ?? false)) {
                continue;
            }

            $state = $this->chapterStates[$chapterId] ?? null;

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

        $result = app(DownloadLedger::class)->enqueue(auth()->user(), $ids, $this->manga);

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

        $this->downloadStatesFor = [];
        $this->downloadStatesLoaded = false;
    }

    /** How many chapters would "download unread" queue right now. */
    public function unreadDownloadableCount(): int
    {
        $count = 0;

        foreach ($this->chapters as $chapter) {
            $chapterId = (int) $chapter['id'];

            if (! (bool) ($chapter['isDownloaded'] ?? false) && ! ($this->chapterStates[$chapterId] ?? null)?->is_read) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Load the download states for every chapter on this series, once.
     *
     * @var array<int, string>
     */
    private array $downloadStatesFor = [];

    private bool $downloadStatesLoaded = false;

    private function loadDownloadStates(): void
    {
        if ($this->downloadStatesLoaded) {
            return;
        }

        $ids = array_map(static fn (array $chapter): int => (int) $chapter['id'], $this->chapters);

        if ($ids === []) {
            $this->downloadStatesLoaded = true;

            return;
        }

        $rows = Download::forUser(auth()->id())
            ->whereIn('suwayomi_chapter_id', $ids)
            ->get(['suwayomi_chapter_id', 'state']);

        foreach ($rows as $row) {
            $this->downloadStatesFor[(int) $row->suwayomi_chapter_id] = (string) $row->state;
        }

        $this->downloadStatesLoaded = true;
    }

    public function chapterUrl(int $chapterId): string
    {
        return route('reader.show', ['manga' => $this->manga, 'chapter' => $chapterId]);
    }

    /**
     * The page range currently shown, for the footer.
     *
     * @return array{0: int, 1: int}
     */
    public function visibleRange(): array
    {
        $from = $this->visibleCount === 0 ? 0 : ($this->page - 1) * self::PER_PAGE + 1;

        return [$from, min($from + self::PER_PAGE - 1, $this->visibleCount)];
    }
}; ?>

<div class="w-full space-y-7">
    @php($series = $this->series)

    @if ($series === null)
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
        <section class="relative overflow-hidden rounded-3xl border border-zinc-200/80 dark:border-white/[0.06]">
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
                    <div class="cover-frame w-40 sm:w-48 md:w-52" data-test="series-cover">
                        @if ($cover = $this->coverUrl)
                            <img
                                src="{{ $cover }}"
                                alt=""
                                loading="lazy"
                            />
                        @else
                            <div class="cover-placeholder flex items-center justify-center text-zinc-300 dark:text-zinc-600">
                                <flux:icon name="photo" class="size-8" />
                            </div>
                        @endif
                    </div>

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
                    <p class="serif-italic text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $series['source']['name'] ?? __('Unknown source') }}

                        @if (($series['source']['lang'] ?? null) !== null)
                            <span class="uppercase">· {{ $series['source']['lang'] }}</span>
                        @endif
                    </p>

                    <flux:heading level="1" class="mt-1 font-display text-3xl font-semibold tracking-tight! sm:text-4xl">
                        {{ $series['title'] }}
                    </flux:heading>

                    <div class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
                        @if (($series['status'] ?? null) !== null)
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

                    {{-- Actions. The primary one is reading, everything else is state. --}}
                    <div class="mt-5 flex flex-wrap items-center gap-2">
                        @if ($resume = $this->resumeChapter)
                            <flux:button
                                variant="primary"
                                icon="book-open"
                                :href="$this->chapterUrl((int) $resume['id'])"
                                wire:navigate
                            >
                                @if ($this->state->last_read_chapter_id !== null)
                                    {{ __('Continue reading') }}
                                @else
                                    {{ __('Start reading') }}
                                @endif
                            </flux:button>
                        @endif

                        <flux:button
                            :variant="$this->state->in_library ? 'filled' : 'outline'"
                            icon="bookmark"
                            wire:click="toggleLibrary"
                            wire:loading.attr="disabled"
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
        <section class="space-y-4">
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

            @if ($this->loadError !== null)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:heading level="3" class="mb-1">{{ __('The source returned no chapters') }}</flux:heading>
                    <p class="text-sm">
                        {{ __('This is an upstream condition: the source answered, and its chapter listing was empty. The series may have moved, or the source may have changed its layout.') }}
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

            @if ($this->chapterCount === 0)
                <flux:callout variant="secondary" icon="book-open">
                    <flux:heading level="3" class="mb-1">{{ __('No chapters yet') }}</flux:heading>
                    <p class="text-sm">
                        {{ __('Suwayomi has not fetched this series. Use Refresh chapters, which asks the source for its chapter list.') }}
                    </p>
                </flux:callout>
            @else
                <ul class="overflow-hidden rounded-2xl border border-zinc-200/90 dark:border-white/[0.07]">
                    @php([$from, $to] = $this->visibleRange())

                    @foreach (array_slice($this->chapters, ($page - 1) * self::PER_PAGE, self::PER_PAGE) as $chapter)
                        @php($state = $this->chapterStates[(int) $chapter['id']] ?? null)

                        <li class="group flex items-center gap-3 border-b border-zinc-200/70 px-3.5 py-2.5 transition-colors last:border-b-0 hover:bg-zinc-100/60 sm:px-4 dark:border-white/[0.05] dark:hover:bg-white/[0.03]">
                            {{-- read state dot, so a long list is scannable --}}
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full {{
                                    $state?->is_read
                                        ? 'bg-moss-500/15 text-moss-600 dark:text-moss-400'
                                        : 'bg-zinc-200/70 text-zinc-400 dark:bg-white/[0.06] dark:text-zinc-500'
                                }}"
                            >
                                <flux:icon :name="$state?->is_read ? 'check' : 'minus'" class="size-3.5" />
                            </span>

                            <a
                                href="{{ $this->chapterUrl((int) $chapter['id']) }}"
                                wire:navigate
                                class="min-w-0 flex-1 truncate text-sm transition-colors {{ $state?->is_read ? 'text-zinc-400 dark:text-zinc-500' : 'font-medium text-zinc-800 hover:text-vermilion-600 dark:text-zinc-100 dark:hover:text-vermilion-400' }}"
                            >
                                <span @class(['line-through decoration-zinc-400/60 opacity-70' => $state?->is_read])>
                                    {{ filled($chapter['name']) ? $chapter['name'] : __('Chapter :number', ['number' => $chapter['chapterNumber'] ?? '?']) }}
                                </span>

                                @if (filled($chapter['scanlator'] ?? null))
                                    <span class="ml-2 text-xs font-normal text-zinc-400 dark:text-zinc-500">{{ $chapter['scanlator'] }}</span>
                                @endif
                            </a>

                            {{--
                                Offline state. `isDownloaded` is the server's own
                                flag, which is the only one that means anything:
                                it is about pages on disk, not about our read
                                state. The "queued" marker is ours.
                            --}}
                            {{--
                                The note, as a marker and a way in.

                                Placed after the download control and before the date,
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
                                @class(['text-vermilion-500 dark:text-vermilion-400' => $note !== null])
                            ></flux:button>

                            @if ($this->isDownloaded((int) $chapter['id']))
                                <flux:tooltip content="{{ __('Ready without the source') }}">
                                    <flux:icon name="cloud-arrow-down" class="size-4 shrink-0 text-moss-600 dark:text-moss-400" />
                                </flux:tooltip>
                            @elseif ($this->downloadState((int) $chapter['id']) !== null)
                                <flux:tooltip content="{{ __('Queued for download') }}">
                                    <flux:icon name="clock" class="size-4 shrink-0 animate-breathe text-vermilion-500" />
                                </flux:tooltip>
                            @else
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="cloud-arrow-down"
                                    wire:click="downloadChapter({{ (int) $chapter['id'] }})"
                                    aria-label="{{ __('Download this chapter') }}"
                                    title="{{ __('Download') }}"
                                    class="opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                                ></flux:button>
                            @endif

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