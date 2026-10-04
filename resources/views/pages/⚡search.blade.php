<?php

use App\AniList\Preference as AniListPreference;
use App\AniList\TitleResolver;
use App\Jobs\ResolveAniListTitles;
use App\Models\AniListMedia;
use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\SearchSourceResult;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\User;
use App\Search\DedupeGroup;
use App\Search\Deduplicator;
use App\Search\FilterVocabulary;
use App\Search\QueryParser;
use App\Search\SearchFanout;
use App\Support\Deployment;
use App\Support\LanguageName;
use App\Support\ReadingState;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Search')] class extends Component
{
    public string $query = '';

    /**
     * Empty means "every language", which is the honest default: a reader
     * searching for a series wants all of them, not the ones we guessed at.
     *
     * @var array<int, string>
     */
    #[Url(as: 'lang', except: '')]
    public array $langs = [];

    /**
     * Content ratings to include. NSFW is opt-in per user rather than a global
     * setting, because in shared deployment one reader's preference must not
     * decide what another sees.
     *
     * @var array<int, string>
     */
    #[Url(as: 'content', except: '')]
    public array $content = [Source::SAFE, Source::MIXED];

    public int $limit = 40;

    public ?int $runId = null;

    /**
     * Whether this reader has opted into AniList matching.
     *
     * A bound property rather than a click handler, so the checkbox and the
     * stored preference cannot disagree about which changed first. Seeded in
     * mount() and persisted by updatedAnilistOn().
     */
    #[Url(as: 'anilist', except: false)]
    public bool $anilistOn = false;

    /**
     * The manga id of the group whose sources are being chosen between, or null.
     *
     * Not in the URL. It is a transient question rather than a place, and putting it there
     * would make a reader's back button close a modal they did not open but were sent.
     */
    public ?int $chosenManga = null;

    public function mount(): void
    {
        $this->anilistOn = app(AniListPreference::class)->enabled(Auth::user());
    }

    /**
     * Persist the switch, and start looking up the run on screen if it was
     * turned on, because a reader who switches it on mid-session wants to see what
     * changes rather than having to search again.
     */
    public function updatedAnilistOn(bool $enabled): void
    {
        app(AniListPreference::class)->set(Auth::user(), $enabled);

        if ($enabled) {
            $this->startAniListResolution();
        }

        Flux::toast(
            text: $enabled
                ? __('Titles from this search are now being matched against AniList.')
                : __('AniList matching is off. Results group by title as before.'),
            heading: $enabled ? __('AniList matching on') : __('AniList matching off'),
            variant: $enabled ? 'success' : 'info',
        );
    }

    /**
     * The terms a reader can type, for the hint under the box.
     */
    #[Computed]
    public function filterHint(): string
    {
        return app(FilterVocabulary::class)->termsHint();
    }

    /**
     * The human name for one of our terms.
     */
    public function filterLabel(string $label): string
    {
        return app(FilterVocabulary::class)->label($label);
    }

    /**
     * The current query split into free text, understood terms, and labels left as text.
     *
     * @return array{text: string, filters: array<string, string>, ignored: array<int, string>, empty: array<int, string>}
     */
    #[Computed]
    public function parsed(): array
    {
        return app(QueryParser::class)->parse(
            (string) $this->query,
            app(FilterVocabulary::class),
        );
    }

    /** @return array<string, string> */
    #[Computed]
    public function parsedFilters(): array
    {
        return $this->parsed()['filters'];
    }

    /** @return array<int, string> */
    #[Computed]
    public function ignoredLabels(): array
    {
        return $this->parsed()['ignored'];
    }

    #[Computed]
    public function parsedText(): string
    {
        return $this->parsed()['text'];
    }

    public function search(): void
    {
        $this->validate([
            'query' => ['required', 'string', 'max:512'],
            'limit' => ['integer', 'min:1', 'max:150'],
        ]);

        $run = app(SearchFanout::class)->start(Auth::user(), $this->query, [
            'langs' => $this->langs === [] ? null : $this->langs,
            'content' => $this->content,
            'limit' => $this->limit,
        ]);

        $this->runId = $run->id;

        $this->startAniListResolution();
    }

    /*
     * AniList matching.
     */

    /**
     * Whether this reader has asked for their titles to be sent to AniList.
     *
     * A computed, read as a property, because the grouping, the toggle and the
     * progress all need it and none of them should query for it separately.
     */
    #[Computed]
    public function anilistEnabled(): bool
    {
        return app(AniListPreference::class)->enabled(Auth::user());
    }

    /**
     * Queue the lookups for this run's titles.
     *
     * A job, and a chain of them, because the rate limit is 30 requests a minute and
     * one fan-out produces 91 distinct titles. Resolving that in the request would
     * take minutes and time the request out; the job re-books itself until the run's
     * titles are answered, and the page polls the cache meanwhile.
     *
     * Dispatched only when there is a run and the reader has opted in, and only once
     * per run: a chain that re-books itself must not also be started again by every
     * poll the page makes.
     */
    private function startAniListResolution(): void
    {
        $run = $this->run;

        if ($run === null || ! $this->anilistEnabled) {
            return;
        }

        $resolver = app(TitleResolver::class);
        $titles = SearchResult::forUser(Auth::id())
            ->where('search_run_id', $run->id)
            ->distinct()
            ->pluck('title')
            ->all();

        if ($resolver->missing($titles) === []) {
            return;
        }

        ResolveAniListTitles::dispatch((int) Auth::id(), (int) $run->id);
    }

    /**
     * How far the AniList lookups for this run have got.
     *
     * @return array{total: int, answered: int} zero total meaning nothing to do
     */
    #[Computed]
    public function anilistProgress(): array
    {
        if (! $this->anilistEnabled) {
            return ['total' => 0, 'answered' => 0];
        }

        $run = $this->run;

        if ($run === null) {
            return ['total' => 0, 'answered' => 0];
        }

        $titles = SearchResult::forUser(Auth::id())
            ->where('search_run_id', $run->id)
            ->distinct()
            ->pluck('title')
            ->all();

        return app(TitleResolver::class)->progress($titles);
    }

    /**
     * The cached AniList media for a result's title, for the content warning.
     *
     * A table read per rendered card, and that is only acceptable because it is a
     * primary key lookup on a small table rather than a per-source round trip. It
     * returns null for a reader who has not opted in and for a title AniList has
     * never heard of, which is most titles, so the common path is a miss.
     */
    public function anilistMediaFor(string $title): ?AniListMedia
    {
        if (! $this->anilistEnabled) {
            return null;
        }

        return app(TitleResolver::class)->mediaFor($title);
    }

    public function clear(): void
    {
        $this->reset(['query', 'runId', 'langs']);
    }

    /*
     * Library membership, from the results.
     *
     * A search is how a reader finds a series they then want to keep, and the
     * only path used to run through each series page in turn. The card carries
     * its own toggle, so keeping a result is one click where the reader is
     * already looking.
     */

    /**
     * Add or remove one result from this reader's library.
     *
     * The current value is read rather than carried, because the map the card
     * rendered from was built before the click and a stale tick must not
     * decide the write, the same reasoning as trust on the sources page.
     */
    public function toggleLibrary(int $manga): void
    {
        $inLibrary = ! (bool) ($this->libraryMap[$manga] ?? false);

        app(ReadingState::class)->setInLibrary(Auth::user(), $manga, $inLibrary);

        unset($this->libraryMap);

        Flux::toast(
            text: $inLibrary
                ? __('Added to your library.')
                : __('Removed from your library.'),
            heading: $inLibrary ? __('In library') : __('Removed'),
            variant: $inLibrary ? 'success' : 'info',
        );
    }

    /**
     * Whether each result is already in this reader's library, keyed by manga id.
     *
     * One query for the whole result set rather than one per card, which is the
     * same lesson the library page paid for.
     *
     * @return array<int, bool>
     */
    #[Computed]
    public function libraryMap(): array
    {
        /*
         * Every result in every group, not only the primaries.
         *
         * It used to map the primaries alone, because the primary was the only one a card
         * could act on. A reader can now choose a source, which means the chooser acts on the
         * alternatives too, and a toggle that shows the wrong state is worse than no toggle:
         * a source the reader already has would offer "add", and adding it again is a no-op
         * that reads as a broken button.
         *
         * Still one query for the page. A search returns up to 15 rows per source across 40
         * sources, so this can be several hundred ids in a single `whereIn`, which is the
         * trade the library page made for the same reason.
         */
        $ids = $this->groups
            ->flatMap(fn (DedupeGroup $group): array => [
                (int) $group->primary->suwayomi_manga_id,
                ...$group->alternatives->map(fn (SearchResult $row): int => (int) $row->suwayomi_manga_id)->all(),
            ])
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return SeriesState::forUser(Auth::id())
            ->whereIn('suwayomi_manga_id', $ids)->pluck('in_library', 'suwayomi_manga_id')
            ->map(fn (mixed $in): bool => (bool) $in)
            ->all();
    }

    /**
     * Open the source chooser for one group.
     *
     * The modal is opened from the response rather than from the click, because the click is
     * two events racing: Alpine's `modal-show` dispatch runs immediately and the Livewire
     * round trip that carries the choice has not come back yet. Opening on the click shows a
     * dialog with a heading and nothing under it, which reads as broken, and a reader who
     * reacts to that by clicking again gets nowhere.
     *
     * So the button only asks, and the answer opens the dialog once the list is in it.
     */
    public function chooseSource(int $manga): void
    {
        $this->chosenManga = $manga;

        unset($this->chosenSources);

        $this->dispatch('open-source-chooser');
    }

    public function closeSourceChooser(): void
    {
        $this->chosenManga = null;

        unset($this->chosenSources);
    }

    /**
     * Every source the chosen group turned up on, primary first.
     *
     * The group is looked up again rather than remembered, so what the chooser lists is what
     * the card is showing and not a copy taken when the click was handled. Looked up by
     * membership, because a search is still streaming when a reader clicks: see
     * {@see DedupeGroup::hasManga()}.
     *
     * @return array<int, array{manga: int, source: string, title: string, inLibrary: bool, cover: ?string}>
     */
    #[Computed]
    public function chosenSources(): array
    {
        if ($this->chosenManga === null) {
            return [];
        }

        $group = $this->groups->first(
            fn (DedupeGroup $g): bool => $g->hasManga($this->chosenManga)
        );

        if ($group === null) {
            return [];
        }

        $library = $this->libraryMap;

        $rows = [];

        foreach ([$group->primary, ...$group->alternatives->all()] as $row) {
            if (! $row instanceof SearchResult) {
                continue;
            }

            $mangaId = (int) $row->suwayomi_manga_id;

            $rows[] = [
                'manga' => $mangaId,
                'source' => (string) $row->source_name,
                'title' => (string) $row->title,
                'inLibrary' => (bool) ($library[$mangaId] ?? false),
                'cover' => $row->coverUrl(),
            ];
        }

        return $rows;
    }

    /**
     * The title of the series being chosen between, for the chooser's heading.
     */
    #[Computed]
    public function chosenTitle(): ?string
    {
        return $this->chosenSources()[0]['title'] ?? null;
    }

    /**
     * Add or remove one language from the filter.
     *
     * A method rather than `$toggle('langs')` because the pills are buttons
     * rather than checkboxes: the value and the count render separately, which
     * the checkbox group cannot do.
     */
    public function toggleLang(string $lang): void
    {
        if (in_array($lang, $this->langs, true)) {
            $this->langs = array_values(array_diff($this->langs, [$lang]));
        } else {
            $this->langs[] = $lang;
        }
    }

    /**
     * The human name for a language code.
     */
    public function languageName(string $lang): string
    {
        return LanguageName::name($lang);
    }

    /**
     * A run can only finish if a worker is draining the queue. On a fresh
     * install there may not be one, and the run would sit at zero forever
     * looking like a hang. Saying so plainly beats an indefinite spinner.
     */
    #[Computed]
    public function queueStalled(): bool
    {
        $run = $this->run;

        if ($run === null || $run->isFinished() || $run->sources_done > 0) {
            return false;
        }

        return $run->created_at->diffInSeconds(now()) > 8;
    }

    #[Computed]
    public function run(): ?SearchRun
    {
        if ($this->runId === null) {
            return null;
        }

        // Named explicitly: relations inherit the tenant scope, which fails
        // closed for anything without an authenticated user.
        return SearchRun::forUser(Auth::id())->find($this->runId);
    }

    /**
     * @return Collection<int, \App\Search\DedupeGroup>
     */
    #[Computed]
    public function groups(): Collection
    {
        $run = $this->run;

        if ($run === null) {
            return new Collection;
        }

        $results = SearchResult::forUser(Auth::id())
            ->where('search_run_id', $run->id)
            ->get();

        /*
         * A resolver only when this reader opted in.
         *
         * That is the whole opt-in: with no resolver the deduplicator keys on
         * titles exactly as it did before AniList existed, so an opted-out reader
         * gets the old behaviour rather than a slower version of the new one.
         */
        $resolver = $this->anilistEnabled()
            ? app(TitleResolver::class)
            : null;

        return new Deduplicator(
            app(\App\Search\TitleNormalizer::class),
            (float) config('papyrus.search.dedupe_fuzzy_threshold', 0.95),
            $resolver,
        )->group($results);
    }

    /**
     * @return Collection<int, SearchSourceResult>
     */
    #[Computed]
    public function outcomes(): Collection
    {
        $run = $this->run;

        if ($run === null) {
            return new Collection;
        }

        return SearchSourceResult::forUser(Auth::id())
            ->where('search_run_id', $run->id)
            ->orderByDesc('result_count')
            ->get();
    }

    /**
     * Languages with more than one installed source, most common first.
     *
     * The live catalogue is extremely long-tailed: roughly 70 of the 80
     * installed languages have exactly one source each. Rendering every one as
     * a chip produces an unusable wall, so the ones with real choice stay
     * visible and the singleton tail hides behind a disclosure.
     *
     * @return Collection<int, object>
     */
    #[Computed]
    public function languages(): Collection
    {
        return $this->languageCounts()
            ->filter(fn (object $row): bool => $row->total > 1)
            ->values();
    }

    /**
     * The singleton tail, for a reader who genuinely wants one specific
     * language that happens to have a single source.
     *
     * @return Collection<int, object>
     */
    #[Computed]
    public function rareLanguages(): Collection
    {
        return $this->languageCounts()
            ->filter(fn (object $row): bool => $row->total === 1)
            ->values();
    }

    /**
     * @return Collection<int, object>
     */
    #[Computed]
    public function languageCounts(): Collection
    {
        return Source::query()
            ->installed()
            ->selectRaw('lang, count(*) as total')
            ->groupBy('lang')
            ->orderByDesc('total')
            ->orderBy('lang')
            ->get();
    }

    #[Computed]
    public function eligibleSources(): int
    {
        return app(SearchFanout::class)->targets(Auth::user(), [
            'langs' => $this->langs === [] ? null : $this->langs,
            'content' => $this->content,
            'limit' => $this->limit,
        ])->count();
    }

    /**
     * The sources this run left out, and what each could not do.
     *
     * Only once the run has finished, because a source that has not reported yet is not
     * known to be skipped and listing it early would be a list of guesses.
     *
     * @return array<int, array{source_name: string, reason: string}>
     */
    #[Computed]
    public function skippedSources(): array
    {
        $run = $this->run;

        if ($run === null || ! $run->isFinished()) {
            return [];
        }

        return SearchSourceResult::query()
            ->where('search_run_id', $run->id)
            ->where('status', SearchSourceResult::SKIPPED)
            ->orderBy('source_name')
            ->get(['source_name', 'error'])
            ->map(fn (SearchSourceResult $row): array => [
                'source_name' => (string) $row->source_name,
                'reason' => (string) $row->error,
            ])
            ->all();
    }
}; ?>

<div
    class="w-full space-y-6"
    {{--
        The chooser is opened from the server's answer rather than from the click.

        `chooseSource()` dispatches this once its response, which carries the sources, has been
        applied. The card cannot open it by itself: Alpine would show the dialog before the
        round trip carrying the list had come back, and a heading over an empty list reads as a
        broken dialog rather than a slow one.
    --}}
    x-on:open-source-chooser.window="$dispatch('modal-show', { name: 'source-chooser' })"
>
    <x-page.header>
        <x-slot:eyebrow>{{ __('Every source, one question.') }}</x-slot:eyebrow>
        <x-slot:title>{{ __('Search every source') }}</x-slot:title>
    </x-page.header>

    {{-- Query ------------------------------------------------------------- --}}
    <form wire:submit="search" class="space-y-4">
        {{--
            The box and the button sit together: a reader's first gesture is type
            then press, and sending the button below the filters used to make that
            gesture a walk down the page and back.

            `live` with a debounce, because the chips below show what this box
            currently parses to, and a deferred model would leave them blank until the
            reader had already searched.

            Debounced rather than per keystroke. A live model with no delay is one round
            trip per character, and the value of the chips is catching a mistyped label,
            which a half second of typing makes obvious anyway.
        --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <flux:input
                wire:model.live.debounce.500ms="query"
                :label="__('Search')"
                :placeholder="__('Series title')"
                icon="magnifying-glass"
                autofocus
                required
                class="flex-1"
            />

            <flux:button variant="primary" type="submit" icon="magnifying-glass" class="shrink-0">
                {{ __('Search') }}
            </flux:button>
        </div>

        {{--
            The syntax, stated. A feature nobody can find is not a feature, and there are
            three terms, so they fit on one line.

            Which three was decided by measurement, and two candidates were cut by it.
            Across all 82 installed sources: genre reaches 77, status 74, sort 69,
            rating 64, author 15, year 15. And against what a search result actually
            carries, measured over 704 rows from 76 sources, none of the optional fields
            is worth checking afterwards: author is missing on 90% of rows and status
            reads UNKNOWN on 99% of them. So a term is pushed or it is not offered, and
            author at 18% of sources is not offered. rating and year are not either.
        --}}
        <p class="-mt-2 text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('Add a filter term. :terms', ['terms' => $this->filterHint]) }}
        </p>

        {{--
            What the last run understood, and what it treated as text.

            An unrecognised label is left in the search as words, so a title that happens
            to contain a colon still works. Saying so here is what makes that safe: a
            reader who typed edition:deluxe and got no results can see it went through
            as text rather than being dropped.
        --}}
        @if ($this->parsedFilters !== [] || $this->ignoredLabels !== [] || $this->parsedText !== '')
            <div class="flex flex-wrap items-center gap-2 text-xs">
                @if ($this->parsedText !== '')
                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ __('Searched for “:text”', ['text' => $this->parsedText]) }}
                    </span>
                @endif

                @foreach ($this->parsedFilters as $label => $value)
                    <span class="rounded bg-vermilion-100 px-1.5 py-0.5 text-vermilion-700 dark:bg-vermilion-950/60 dark:text-vermilion-300">
                        {{ $this->filterLabel($label) }}: {{ $value }}
                    </span>
                @endforeach

                @foreach ($this->ignoredLabels as $ignored)
                    <span
                        class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                        title="{{ __('Not a term this app knows, so it was searched as text.') }}"
                    >
                        {{ __('“:word” searched as text', ['word' => $ignored]) }}
                    </span>
                @endforeach
            </div>
        @endif

        {{--
            The filters, as one panel. Languages and content are the two axes a
            reader narrows a fan-out by, and giving each its own column inside one
            bordered panel keeps them from reading as two unrelated forms.
        --}}
        <div class="glass grid gap-6 rounded-2xl p-4 sm:grid-cols-2 sm:p-5">
            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <flux:label>{{ __('Languages') }}</flux:label>

                    @if (count($this->langs) > 0)
                        <button
                            type="button"
                            wire:click="$set('langs', [])"
                            class="text-xs text-zinc-500 underline-offset-2 hover:underline dark:text-zinc-400"
                        >
                            {{ __('Clear (:count)', ['count' => count($this->langs)]) }}
                        </button>
                    @endif
                </div>

                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Leave empty to search all installed sources.') }}
                </p>

                {{--
                    Buttons rather than checkboxes, and that is not decoration. The
                    codes themselves (`es-419`, `zh-Hant`) meant nothing to a reader
                    choosing between them, and a checkbox label has no room for a
                    human name beside a count of sources. The pill carries both.
                --}}
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($this->languages as $language)
                        <button
                            type="button"
                            wire:click="toggleLang('{{ $language->lang }}')"
                            aria-pressed="{{ in_array($language->lang, $langs, true) ? 'true' : 'false' }}"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                                'border-vermilion-600 bg-vermilion-600 text-white shadow-sm' => in_array($language->lang, $langs, true),
                                'border-zinc-300 text-zinc-600 hover:border-zinc-400 hover:bg-zinc-50 dark:border-white/10 dark:bg-white/[0.03] dark:text-zinc-300 dark:hover:bg-white/[0.06]' => ! in_array($language->lang, $langs, true),
                            ])
                        >
                            {{ $this->languageName($language->lang) }}

                            <span
                                @class([
                                    'rounded-full px-1.5 text-[0.65rem] tabular-nums',
                                    'bg-white/20' => in_array($language->lang, $langs, true),
                                    'bg-zinc-100 text-zinc-500 dark:bg-white/[0.06] dark:text-zinc-400' => ! in_array($language->lang, $langs, true),
                                ])
                            >{{ $language->total }}</span>
                        </button>
                    @endforeach
                </div>

                {{--
                    The installed catalogue is long-tailed - most languages have
                    exactly one source - so the singleton tail sits behind a
                    disclosure instead of pushing the search button off screen.
                --}}
                @if ($this->rareLanguages->isNotEmpty())
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm text-zinc-500 dark:text-zinc-400">
                            {{ trans_choice(
                                '{1} :count language with a single source|[2,*] :count languages with a single source',
                                $this->rareLanguages->count(),
                                ['count' => $this->rareLanguages->count()],
                            ) }}
                        </summary>

                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($this->rareLanguages as $language)
                                <button
                                    type="button"
                                    wire:click="toggleLang('{{ $language->lang }}')"
                                    aria-pressed="{{ in_array($language->lang, $langs, true) ? 'true' : 'false' }}"
                                    @class([
                                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                                        'border-vermilion-600 bg-vermilion-600 text-white shadow-sm' => in_array($language->lang, $langs, true),
                                        'border-zinc-300 text-zinc-600 hover:border-zinc-400 hover:bg-zinc-50 dark:border-white/10 dark:bg-white/[0.03] dark:text-zinc-300 dark:hover:bg-white/[0.06]' => ! in_array($language->lang, $langs, true),
                                    ])
                                >
                                    {{ $this->languageName($language->lang) }}

                                    <span
                                        @class([
                                            'rounded-full px-1.5 text-[0.65rem] tabular-nums',
                                            'bg-white/20' => in_array($language->lang, $langs, true),
                                            'bg-zinc-100 text-zinc-500 dark:bg-white/[0.06] dark:text-zinc-400' => ! in_array($language->lang, $langs, true),
                                        ])
                                    >{{ $language->total }}</span>
                                </button>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>

            <div>
                <flux:label>{{ __('Content') }}</flux:label>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('NSFW sources are excluded unless you opt in.') }}
                </p>

                <div class="mt-2">
                    <flux:checkbox.group wire:model="content" variant="pills">
                        <flux:checkbox value="SAFE" label="{{ __('Safe') }}" />
                        <flux:checkbox value="MIXED" label="{{ __('Mixed') }}" />
                        <flux:checkbox value="NSFW" label="{{ __('NSFW') }}" />
                    </flux:checkbox.group>
                </div>
            </div>
        </div>

        {{--
            AniList matching, off unless the reader turns it on.

            The switch is per reader and off by default because a lookup sends the
            title a reader typed to a third party's server. On a self-hosted box that
            is the deployer's reading habits leaving the machine, so it is a choice
            rather than a default. The explanation is inline rather than behind a
            tooltip because the consequence is a privacy consequence, and those should
            not be hidden.
        --}}
        <div class="border-t border-zinc-200 pt-4 dark:border-zinc-800">
            <flux:checkbox
                wire:model.live="anilistOn"
                label="{{ __('Match titles against AniList') }}"
                description="{{ __('Sends the titles you search to a third party so a series listed under three names in three languages is recognised as one. Off by default.') }}"
            />

            @if ($this->anilistEnabled)
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                    <flux:badge color="zinc" size="sm">{{ __('AniList matching on') }}</flux:badge>

                    @if ($this->anilistProgress['total'] > 0)
                        @php($progress = $this->anilistProgress)

                        @if ($progress['answered'] < $progress['total'])
                            <span class="text-zinc-500 dark:text-zinc-400" aria-live="polite">
                                {{ trans_choice(
                                    'Matching :count title|Matching :count titles',
                                    $progress['total'] - $progress['answered'],
                                    ['count' => $progress['total'] - $progress['answered']],
                                ) }}
                            </span>
                            <span
                                class="h-1 w-24 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="{{ $progress['total'] }}"
                                aria-valuenow="{{ $progress['answered'] }}"
                            >
                                <div
                                    class="h-full rounded-full bg-vermilion-500 transition-[width] duration-700"
                                    style="width: {{ (int) round(($progress['answered'] / $progress['total']) * 100) }}%"
                                ></div>
                            </span>
                        @else
                            <span class="text-zinc-500 dark:text-zinc-400">
                                {{ trans_choice(
                                    'All :count titles matched|All :count titles matched',
                                    $progress['total'],
                                    ['count' => $progress['total']],
                                ) }}
                            </span>
                        @endif
                    @endif
                </div>
            @endif
        </div>

        {{--
            What the current filters will cost, stated where the button used to be.
            The button itself moved up beside the box, so this line is what keeps
            the "no sources match" warning visible at all.
        --}}
        <div class="flex items-center gap-3">
            @if ($this->eligibleSources > 0)
                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ trans_choice('{1} Searching :count source|[2,*] Searching :count sources', $this->eligibleSources, ['count' => $this->eligibleSources]) }}
                </span>
            @else
                <span class="text-sm text-amber-600 dark:text-amber-400">
                    {{ __('No sources match these filters.') }}
                </span>
            @endif
        </div>
    </form>

    @if ($this->runId !== null)
        @php($run = $this->run)

        {{-- Progress --------------------------------------------------------- --}}
        @if ($run && ! $run->isFinished())
            {{--
                The refresh tick rides on the progress block rather than on a separate
                empty element beside it.

                An empty `wire:poll` div never polled. Measured with a probe: the counter
                sat at "0 of 40 sources" for the whole run while the run itself completed,
                and only jumped when the reader touched something else. A poll directive
                on an element with nothing in it is not reliably started, and the effect
                is a progress bar that looks hung. On the block that shows the progress,
                the poll starts, and unmounting the block when the run finishes stops it,
                which is the same stop condition without needing a bound value.
            --}}
            <div wire:poll.3s>
                <div class="glass rounded-2xl p-4">
                    <div class="flex items-center justify-between gap-4">
                        <span class="flex items-center gap-2.5 text-sm font-medium">
                            <span class="relative flex size-2" aria-hidden="true">
                                <span class="absolute inline-flex size-full animate-ping rounded-full bg-vermilion-500 opacity-60"></span>
                                <span class="relative inline-flex size-2 rounded-full bg-vermilion-500"></span>
                            </span>

                            {{ __('Searching :count of :total sources', [
                                'count' => $run->sources_done,
                                'total' => $run->sources_total,
                            ]) }}
                        </span>
                        <span class="tabular-nums text-sm text-zinc-500 dark:text-zinc-400">{{ $run->progressPercent() }}%</span>
                    </div>

                    <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                        <div
                            class="h-full rounded-full bg-linear-to-r from-vermilion-500 to-vermilion-400 shadow-[0_0_10px_color-mix(in_oklab,var(--color-vermilion-500)_55%,transparent)] transition-all duration-300"
                            style="width: {{ $run->progressPercent() }}%"
                        ></div>
                    </div>
                </div>
            </div>
        @endif

        @if ($this->queueStalled)
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:heading level="3" class="mb-1">{{ __('No worker is draining the queue') }}</flux:heading>
                <p class="text-sm">
                    {{ __('The search cannot finish until a queue worker runs. Start one with') }}
                    <code class="rounded bg-black/10 px-1 py-0.5 text-xs dark:bg-white/10">php artisan queue:work</code>
                </p>
            </flux:callout>
        @endif

        {{-- Per-source status ------------------------------------------------ --}}
        @if ($this->outcomes->isNotEmpty())
            <details class="rounded-lg border border-zinc-200 dark:border-white/10">
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                    {{ __('Per-source status') }}
                    <span class="text-zinc-500">{{ $run->sources_done }} of {{ $run->sources_total }} answered</span>
                </summary>

                <ul class="divide-y divide-zinc-200 border-t border-zinc-200 dark:divide-white/10 dark:border-white/10">
                    @foreach ($this->outcomes as $outcome)
                        <li class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                            <span class="flex items-center gap-2">
                                <flux:icon
                                    @class([
                                        'text-moss-600 dark:text-moss-400' => $outcome->status === 'ok',
                                        'text-amber-600 dark:text-amber-400' => $outcome->status === 'empty',
                                        'text-red-600 dark:text-red-400' => in_array($outcome->status, ['error', 'timeout'], true),
                                    ])
                                    :name="match ($outcome->status) {
                                        'ok' => 'check-circle',
                                        'empty' => 'minus-circle',
                                        'timeout' => 'clock',
                                        default => 'exclamation-triangle',
                                    }"
                                />
                                <span>{{ $outcome->source_name }}</span>
                            </span>

                            <span class="flex items-center gap-3 tabular-nums text-zinc-500">
                                @if ($outcome->status === 'ok' || $outcome->status === 'empty')
                                    <span>{{ trans_choice('{1} :count hit|[2,*] :count hits', $outcome->result_count, ['count' => $outcome->result_count]) }}</span>
                                @endif

                                @if ($outcome->duration_ms)
                                    <span class="text-xs">{{ Number::format($outcome->duration_ms) }}ms</span>
                                @endif

                                @if ($outcome->error)
                                    <flux:tooltip :content="$outcome->error">
                                        <flux:icon name="information-circle" class="text-zinc-400" />
                                    </flux:tooltip>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        {{-- Partial results warning ------------------------------------------ --}}
        @if ($run?->status === SearchRun::PARTIAL)
            <flux:callout variant="warning" icon="exclamation-triangle">
                {{ trans_choice(
                    '{1} :count source did not answer.|[2,*] :count sources did not answer.',
                    $run->sources_failed,
                    ['count' => $run->sources_failed],
                ) }}
            </flux:callout>
        @endif

        {{-- Results ---------------------------------------------------------- --}}
        @if ($this->groups->isEmpty())
            @if ($run?->isFinished())
                <flux:callout variant="secondary" icon="magnifying-glass">
                    {{ __('Nothing matched on any source that answered.') }}
                </flux:callout>
            @endif

            {{--
                What the terms cost, shown here as well as below the results.

                It was only in the results branch, which is the one branch that is absent
                when a filtered run finds nothing. So the runs that most need to explain
                themselves were the ones that could not: a `status:` run that emptied
                every source showed a bare "nothing matched" and no hint that nine
                sources had been left out of the search.
            --}}
            @include('pages.partials.search-skipped', ['run' => $run])
        @else
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ trans_choice(
                    '{1} :count result from :sources sources|[2,*] :count results, deduplicated from :sources sources',
                    $this->groups->count(),
                    [
                        'count' => $this->groups->count(),
                        'sources' => $this->outcomes->count(),
                    ],
                ) }}
            </p>

            {{--
                What each term cost. A term is pushed where the source has a matching
                filter and the source is left out where it does not, so a filtered run
                asks fewer sources.

                In its own partial because it belongs in both the results and the
                no-results branch, and the no-results branch is exactly when a reader
                most needs to be told that nine sources were never asked.
            --}}
            @include('pages.partials.search-skipped', ['run' => $run])

            <ul class="stagger-enter grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                @foreach ($this->groups as $group)
                    @php($primaryId = (int) $group->primary->suwayomi_manga_id)
                    <li data-series-card class="group relative">
                        {{--
                            A group with more than one source opens a chooser instead of
                            navigating, because the card was already telling the reader there
                            were others and then ignoring them.

                            It named them in the line under the title and made both the cover and
                            the title link to whichever source the deduplicator ranked first.
                            Which one that is has no relation to which the reader wants: the
                            primary is the closest title match, not the site they trust or the
                            language they read. A reader who is shown "+2 sources" and then
                            lands on one of them has been given a choice and had it taken away.

                            So the cover and the title become buttons, and the chooser lists
                            every source with its own link and its own bookmark. A group with
                            one source keeps the link, because a chooser over a single entry is
                            a dialog that can only be dismissed.
                        --}}
                        @if ($group->hasAlternatives())
                            <button
                                type="button"
                                class="card-press block w-full text-start"
                                data-test="choose-source"
                                wire:click="chooseSource({{ $primaryId }})"
                                aria-label="{{ __('Choose a source for :title', ['title' => $group->primary->title]) }}"
                            >
                                <x-cover.art :src="$group->primary->coverUrl()" :glow="true" :vt="true" class="group">
                                    <span class="absolute end-1.5 top-1.5 rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums on-art">
                                        +{{ $group->sourceCount() - 1 }}
                                    </span>

                                    @if ($group->strictestContentWarning() !== 'SAFE')
                                        <span class="absolute start-1.5 top-1.5 rounded-md bg-red-500/90 px-1.5 py-0.5 text-[0.65rem] font-bold tracking-wide text-white shadow-sm">
                                            {{ $group->strictestContentWarning() }}
                                        </span>
                                    @endif
                                </x-cover.art>

                                <flux:heading size="sm" class="mt-2.5 line-clamp-2 font-display font-semibold leading-snug" level="3">
                                    <span class="transition-colors group-hover:text-vermilion-600 dark:group-hover:text-vermilion-400">
                                        {{ $group->primary->title }}
                                    </span>
                                </flux:heading>
                            </button>
                        @else
                            <a
                                href="{{ route('series', ['manga' => $primaryId]) }}"
                                wire:navigate
                                class="block"
                                tabindex="-1"
                                aria-hidden="true"
                            >
                                <x-cover.art :src="$group->primary->coverUrl()" :glow="true" :vt="true">
                                    @if ($group->strictestContentWarning() !== 'SAFE')
                                        <span class="absolute start-1.5 top-1.5 rounded-md bg-red-500/90 px-1.5 py-0.5 text-[0.65rem] font-bold tracking-wide text-white shadow-sm">
                                            {{ $group->strictestContentWarning() }}
                                        </span>
                                    @endif
                                </x-cover.art>
                            </a>
                        @endif

                        {{--
                            The library toggle, on the card, and only where there is
                            nothing to choose.

                            A search is how a reader finds a series they then want to
                            keep, and the only path to the bookmark used to run through
                            each series page in turn. It sits outside the anchor: a
                            button inside a link is invalid HTML, and the click would
                            navigate instead of toggling wherever browsers forgave it.

                            The state is read from a batch over the whole result set,
                            one query for the page, which is the same lesson the
                            library page paid for in round trips.

                            It is deliberately absent when the group turned up on more
                            than one source. It used to sit there anyway, filing the
                            primary, which put two bookmarks on one card answering two
                            different questions: this one silently picked a source the
                            reader had not chosen and never named, and the one inside the
                            chooser filed whichever source it sat beside. A reader who
                            wanted Asura Scans and pressed the bookmark on the cover got
                            Manga Demon, with no way to tell from anything on screen.

                            So a card that needs a decision has no shortcut past the
                            decision. The chooser names every source and gives each one
                            its own bookmark, which is the only place the reader can see
                            what they are choosing between. Single-source cards keep the
                            toggle, because a button that files the only candidate is not
                            a guess.
                        --}}
                        @if (! $group->hasAlternatives())
                            @php($inLibrary = (bool) ($this->libraryMap[$primaryId] ?? false))

                            <button
                                type="button"
                                wire:click="toggleLibrary({{ $primaryId }})"
                                wire:loading.attr="disabled"
                                data-test="library-toggle"
                                aria-pressed="{{ $inLibrary ? 'true' : 'false' }}"
                                aria-label="{{ $inLibrary
                                    ? __('Remove :title from your library', ['title' => $group->primary->title])
                                    : __('Add :title to your library', ['title' => $group->primary->title]) }}"
                                title="{{ $inLibrary ? __('In library') : __('Add to library') }}"
                                class="absolute bottom-1.5 end-1.5 z-10 flex size-7 items-center justify-center rounded-full on-art transition-transform hover:scale-110"
                            >
                                <flux:icon
                                    name="bookmark"
                                    @class([
                                        'size-3.5 text-vermilion-400' => $inLibrary,
                                        'size-3.5 text-zinc-300' => ! $inLibrary,
                                    ])
                                />
                            </button>
                        @endif

                        @if (! $group->hasAlternatives())
                            {{-- Single source: the title is the way in, so it is a link like the cover was. --}}
                            <flux:heading size="sm" class="mt-2.5 line-clamp-2 font-display font-semibold leading-snug" level="3">
                                <a href="{{ route('series', ['manga' => $primaryId]) }}" wire:navigate class="transition-colors hover:text-vermilion-600 dark:hover:text-vermilion-400">
                                    {{ $group->primary->title }}
                                </a>
                            </flux:heading>
                        @endif

                        <p class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $group->primary->source_name }}
                        </p>

                        {{--
                            Whoever made it, when the sources say.

                            Carried on the result because `author:` is checked
                            against this field, and it is worth showing
                            regardless: a reader scanning a list of near
                            matches is often deciding between them by who
                            drew them rather than by which title reads
                            closest.
                        --}}
                        @php($authors = $group->authorNames())

                        @if ($authors !== [])
                            <p
                                data-series-author
                                class="mt-0.5 truncate text-xs text-zinc-400 dark:text-zinc-500"
                            >
                                {{ implode(', ', $authors) }}
                            </p>
                        @endif

                        @if ($group->hasAlternatives())
                            <p class="mt-1 truncate text-xs text-zinc-400 dark:text-zinc-500">
                                +{{ $group->sourceCount() - 1 }} {{ trans_choice('source|sources', $group->sourceCount() - 1) }}:
                                {{ implode(', ', $group->alternativeNames()) }}
                            </p>
                        @endif

                        {{--
                             AniList's own rating, beside the source's and never
                               instead of it.

                               `content_warning` is a rating for the whole source,
                               so a source rated SAFE can serve something AniList
                               marks adult. Showing both is the honest shape: the
                               reader sees the disagreement rather than having one
                               of the two silently applied. A badge appears only
                               where the two actually differ, because agreeing
                               twice is noise.
                        --}}
                        @php($anilist = $this->anilistMediaFor($group->primary->title))

                        @if ($anilist !== null && $anilist->anilist_id > 0)
                            <flux:tooltip
                                content="{{ $anilist->is_adult
                                    ? __('AniList marks this series as adult, while the source rates it :rating.', ['rating' => $group->strictestContentWarning()])
                                    : __('AniList does not mark this series as adult. The source rates it :rating.', ['rating' => $group->strictestContentWarning()]) }}"
                            >
                                <span class="mt-1.5 inline-flex items-center gap-1 rounded-full border border-amber-400/50 bg-amber-400/10 px-2 py-0.5 text-[0.65rem] font-medium text-amber-600 dark:text-amber-400">
                                    {{ __('AniList: adult') }}
                                </span>
                            </flux:tooltip>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    {{--
        The source chooser.

        One modal for the whole page rather than one per card. A wide search returns hundreds
        of groups and dozens carry alternatives, so a modal per card would be dozens of dialogs
        in the document, each with its own state, for something a reader opens once.

        Opened by dispatching Flux's own `modal-show` event, which is what
        `<flux:modal.trigger>` does internally. Going through the trigger component would mean
        wrapping each card's cover in a div that renders a button, and the cover is already a
        button for this case, so the event is dispatched by hand instead.

        Every source carries its own link and its own bookmark, because the two answers are
        different: a reader may want to read the one they trust most and keep the one their
        shelf is already on.
    --}}
    <flux:modal
        name="source-chooser"
        class="max-w-lg md:min-w-lg"
        @close="closeSourceChooser"
    >
        <div class="space-y-4">
            <div>
                <flux:heading size="sm">{{ __('Choose a source') }}</flux:heading>

                @if ($this->chosenTitle !== null)
                    <flux:text size="xs" class="mt-0.5">{{ $this->chosenTitle }}</flux:text>
                @endif
            </div>

            <ul class="space-y-2" data-test="source-chooser-list">
                @forelse ($this->chosenSources as $one)
                    <li class="card-press flex items-center gap-3 rounded-xl border border-zinc-200/80 bg-zinc-50/60 p-2 transition-colors hover:border-zinc-300 dark:border-white/[0.08] dark:bg-white/[0.03] dark:hover:border-white/[0.16]">
                        @if ($one['cover'] !== null)
                            <img
                                src="{{ $one['cover'] }}"
                                alt=""
                                loading="lazy"
                                class="size-12 shrink-0 rounded-md object-cover"
                            />
                        @else
                            <div class="cover-placeholder flex size-12 shrink-0 items-center justify-center text-zinc-300 dark:text-zinc-600">
                                <flux:icon name="photo" class="size-5" />
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <flux:text size="sm" class="truncate font-medium">{{ $one['source'] }}</flux:text>

                            <flux:text size="xs" class="truncate">
                                @if ($one['inLibrary'])
                                    <span class="inline-flex items-center gap-1 text-moss-600 dark:text-moss-400">
                                        <flux:icon name="bookmark" class="size-3" />
                                        {{ __('In your library') }}
                                    </span>
                                @else
                                    {{ $one['title'] }}
                                @endif
                            </flux:text>
                        </div>

                        {{-- The bookmark, outside the link for the same reason it is outside the card's. --}}
                        <button
                            type="button"
                            wire:click="toggleLibrary({{ $one['manga'] }})"
                            wire:loading.attr="disabled"
                            data-test="source-chooser-bookmark"
                            aria-pressed="{{ $one['inLibrary'] ? 'true' : 'false' }}"
                            aria-label="{{ $one['inLibrary']
                                ? __('Remove :title from your library', ['title' => $one['title']])
                                : __('Add :title to your library', ['title' => $one['title']]) }}"
                            title="{{ $one['inLibrary'] ? __('In library') : __('Add to library') }}"
                            class="flex size-8 shrink-0 items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-white/5"
                        >
                            <flux:icon
                                name="bookmark"
                                @class([
                                    'size-4 text-vermilion-500' => $one['inLibrary'],
                                    'size-4 text-zinc-400' => ! $one['inLibrary'],
                                ])
                            />
                        </button>

                        <flux:button
                            size="sm"
                            variant="outline"
                            href="{{ route('series', ['manga' => $one['manga']]) }}"
                            wire:navigate
                            data-test="source-chooser-open"
                        >
                            {{ __('Open') }}
                        </flux:button>
                    </li>
                @empty
                    {{--
                        Said out loud rather than left blank.

                        A heading over an empty list is a dialog that looks broken. This can
                        happen legitimately: a search streams results in, so the group the
                        reader clicked can be regrouped by a source that answered after their
                        click. The membership lookup in {@see DedupeGroup::hasManga()} stops
                        that losing the group, but a run that was cleared or a filter that
                        changed can still leave the id in no group at all, and that is worth
                        a sentence rather than a blank.
                    --}}
                    <li>
                        <flux:text size="sm">
                            {{ __('This result is no longer on the page. Run the search again.') }}
                        </flux:text>
                    </li>
                @endforelse
            </ul>
        </div>
    </flux:modal>
</div>
