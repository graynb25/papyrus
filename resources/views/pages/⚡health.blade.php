<?php

use App\Models\Source;
use App\Models\UserSource;
use App\Support\Incognito;
use App\Search\HealthDashboard;
use App\Search\HealthProbe;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Health')] class extends Component {
    #[Url(as: 'state', except: '')]
    public string $state = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * The site whose language variants are open.
     *
     * In the URL rather than in component state so a view of a specific site can
     * be linked to, which is the whole reason for having a drill-down at all.
     */
    #[Url(as: 'site', except: '')]
    public string $openSite = '';

    /**
     * How many language variants the drill-down lists.
     *
     * The largest site carries 61, and rendering all of them pushes the rest of
     * the page off screen. Ordered worst-first, so the cap keeps the variants
     * that would explain a bad state rather than the alphabetically pleasant
     * ones.
     */
    public const DRILLDOWN_LIMIT = 30;

    /**
     * Clamp the linked site before it is used as a key.
     *
     * `sources.home_url` is a 255 character column, so a longer value cannot
     * name a site the ledger holds. Left in the address it would be printed
     * back as though it were one; clearing it returns the reader to the list.
     */
    public function mount(): void
    {
        if (mb_strlen($this->openSite) > 255) {
            $this->openSite = '';
        }
    }

    /**
     * Skip every variant of a site in this user's searches.
     *
     * Per-user on purpose. The health ledger is global, because a dead upstream
     * is dead for everyone, but the consequence is personal: one reader may
     * want to keep a flaky source they know works and another does not.
     */
    public function skip(string $site): void
    {
        $ids = $this->variantIds($site);

        if ($ids === []) {
            return;
        }

        UserSource::setDisabledFor(auth()->id(), $ids, true);

        Flux::toast(
            text: trans_choice('{1} :count language variant will no longer be searched.|[2,*] :count language variants will no longer be searched.', count($ids), ['count' => count($ids)]),
            heading: __('Skipped in your searches'),
            variant: 'success',
        );
    }

    public function unskip(string $site): void
    {
        UserSource::setDisabledFor(auth()->id(), $this->variantIds($site), false);

        Flux::toast(
            text: __('It will be searched again.'),
            heading: __('Restored to your searches'),
            variant: 'info',
        );
    }

    /**
     * Pin every variant of a site for this reader's searches.
     *
     * The mirror of skip(), and written beside it so the two halves of "what my
     * searches reach" stand as one conversation: skip says "leave me alone", pin
     * says "ask me first". A pin on site A does not switch anything off; it makes
     * the pinned list the whole scope, and everything not pinned simply waits.
     *
     * Per-user for the same reading skip() records: the consequence is personal.
     */
    public function pin(string $site): void
    {
        $ids = $this->variantIds($site);

        if ($ids === []) {
            return;
        }

        UserSource::setPinnedFor(auth()->id(), $ids, true);

        unset($this->pinnedIds);

        Flux::toast(
            text: trans_choice(
                '{1} Search asks this one variant alone while the pin stands.|[2,*] Search asks the :count variants of this site alone while the pin stands.',
                count($ids),
                ['count' => count($ids)],
            ),
            heading: __('Pinned for search'),
            variant: 'success',
        );
    }

    public function unpin(string $site): void
    {
        UserSource::setPinnedFor(auth()->id(), $this->variantIds($site), false);

        unset($this->pinnedIds);

        Flux::toast(
            text: __('Search is back to every source that can run.'),
            heading: __('Pin cleared'),
            variant: 'info',
        );
    }

    public function toggleSite(string $site): void
    {
        $this->openSite = $this->openSite === $site ? '' : $site;
    }

    public function siteSummary(string $site): string
    {
        $variants = app(HealthDashboard::class)->variantsOf($site);
        $counts = [
            'dead' => 0,
            'failing' => 0,
            'silent' => 0,
        ];

        foreach ($variants as $variant) {
            if ((bool) $variant->is_dead) {
                $counts['dead']++;

                continue;
            }

            if ((int) $variant->consecutive_failures >= 2) {
                $counts['failing']++;

                continue;
            }

            if ((int) $variant->consecutive_failures === 0 && (int) $variant->consecutive_empty >= 20) {
                $counts['silent']++;
            }
        }

        $parts = [];

        foreach (['dead' => __('dead'), 'failing' => __('failing'), 'silent' => __('going quiet')] as $key => $label) {
            $count = $counts[$key] ?? 0;

            if ($count > 0) {
                $parts[] = trans_choice('{1} :count '.$label.'|[2,*] :count '.$label, $count, ['count' => Number::format($count)]);
            }
        }

        $total = trans_choice('{1} :count variant|[2,*] :count variants', $variants->count(), ['count' => Number::format($variants->count())]);

        return $parts === [] ? $total : $total.', '.implode(', ', $parts);
    }

    public function setState(string $state): void
    {
        $this->state = $this->state === $state ? '' : $state;
    }

    /**
     * The site rows, paged.
     *
     * @return LengthAwarePaginator<int, object>
     */
    #[Computed]
    public function sites(): LengthAwarePaginator
    {
        $options = [
            'state' => $this->state !== '' ? $this->state : null,
            'query' => trim($this->search),
        ];

        /*
         * A linked site is shown on its own rather than at the page it would
         * otherwise have fallen on. MangaDot is on page two of the healthy
         * sites, so a link that only set openSite expanded a row the reader
         * could not see. Asking for the site and page one is one filtered
         * query, not a second list.
         */
        if ($this->openSite !== '') {
            $options['site'] = $this->openSite;
            $options['page'] = 1;
        }

        return app(HealthDashboard::class)->sites($options);
    }

    /**
     * Whether Suwayomi answered the last short probe.
     *
     * The cached probe is the seam that keeps a page render from becoming a
     * two second wait on every click.
     */
    #[Computed]
    public function suwayomiReachable(): bool
    {
        return app(HealthProbe::class)->reachable();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function tally(): array
    {
        return app(HealthDashboard::class)->siteTally();
    }

    /**
     * Sources mirrored, as distinct from the sites they group into.
     *
     * The gap between this and the tile is the whole reason this page groups:
     * measured live, 82 sources behind 16 sites.
     */
    #[Computed]
    public function installedSources(): int
    {
        return Source::query()->installed()->count();
    }

    /**
     * The language variants of the open site.
     *
     * @return Collection<int, \App\Models\SourceHealth>
     */
    #[Computed]
    public function variants(): Collection
    {
        if ($this->openSite === '') {
            return new Collection;
        }

        return app(HealthDashboard::class)->variantsOf($this->openSite);
    }

    /**
     * The sources this reader has pinned, as Suwayomi ids.
     *
     * One query for the whole page rather than one per site row, for the same
     * reason `skipped()` is one query: the read lives on the model, so the
     * fanout's rule and this badge are reading the same rows, not two queries
     * that could drift.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function pinnedIds(): array
    {
        return UserSource::pinnedSourceIdsFor((int) auth()->id());
    }

    /**
     * @return array<int, int>
     */
    #[Computed]
    public function skipped(): array
    {
        return app(HealthDashboard::class)->disabledSourceIds(auth()->id());
    }

    /**
     * The sources this reader has put on incognito, as Suwayomi ids.
     *
     * One query for the whole page rather than one per site row, for the same reason
     * `skipped()` is one query: the dashboard renders one row per site and a row that asked
     * its own question would be sixteen queries for a page that is otherwise flat in its size.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function incognitoSites(): array
    {
        return app(Incognito::class)->incognitoIds((int) auth()->id());
    }

    /**
     * Per-site library counts, memoised for the length of the request.
     *
     * A plain property rather than a `#[Computed]`, because `seriesCountFor()` writes into it.
     * Livewire's computed properties resolve through `__get`, which returns a value rather than
     * a reference, so assigning to an offset of one is silently discarded: "Indirect
     * modification of overloaded property has no effect". The number is then recomputed on
     * every call, which is the exact cost the memo was for. The health page hit this the first
     * time and the drill-down test caught it.
     *
     * @var array<string, int>
     */
    private array $incognitoCounts = [];

    public function stateLabel(string $state): string
    {
        return app(HealthDashboard::class)->stateLabel($state);
    }

    public function isSkipped(object $row): bool
    {
        $skipped = $this->skipped;

        if ($skipped === []) {
            return false;
        }

        return $this->variantIds((string) $row->site, $this->skipped) !== [];
    }

    /**
     * Leave no read history on this site, without touching search.
     *
     * Separate from skip(), and deliberately not the same thing. Skip answers "do not ask
     * this source again", which `user_sources.disabled` already owns and which is what the
     * health page's existing control is for. Incognito answers "do not record that I read
     * this", which is a different promise and a different column, and a reader who conflates
     * the two loses either the searches or the history they meant to keep.
     *
     * Read state only, and that is the whole scope: opening a chapter writes no
     * `last_read_at`, no page position, no read flag and no read seconds, and pushes nothing
     * to AniList. Library, shelves, favourites, read later, notes and covers are untouched,
     * because a reader deciding to keep a series is not the same gesture as reading it and
     * refusing one would be refusing the other.
     *
     * Per-user on the same reasoning as skip(): the health ledger is global because a dead
     * upstream is dead for everyone, but what a reader records is nobody else's business.
     */
    public function incognito(string $site): void
    {
        $ids = $this->variantIds($site);

        if ($ids === []) {
            return;
        }

        $series = $this->seriesCountFor($site);

        Incognito::setFor(auth()->id(), $ids, true);

        unset($this->incognitoSites);
        Flux::toast(
            text: $series === 0
                ? __('Reading here will leave no history, and nothing is recorded either way today.')
                : trans_choice(
                    'Reading here will leave no history. :count series stays in your library.|Reading here will leave no history. :count series stay in your library.',
                    $series,
                    ['count' => Number::format($series)],
                ),
            heading: __('Incognito from now on'),
            variant: 'success',
        );
    }

    /**
     * Record read state here again.
     *
     * Restoring does not retroactively write what was suppressed while it was on: the pages
     * were never fetched and the chapters were never marked, so there is nothing to recover.
     * The toast says so rather than implying the reader's history came back.
     */
    public function reveal(string $site): void
    {
        Incognito::setFor(auth()->id(), $this->variantIds($site), false);

        unset($this->incognitoSites);
        Flux::toast(
            text: __('Reading here is recorded again. What was read while it was hidden was not kept.'),
            heading: __('History is back on'),
            variant: 'info',
        );
    }

    /**
     * Whether every variant of this site is incognito for this reader.
     *
     * All of them, not any. Partial state across 61 language variants of one site is not a
     * state a reader can act on, and reporting it as off while some variants still suppress
     * would be a badge that lies about half the rows behind it.
     */
    public function isIncognito(object $row): bool
    {
        $site = (string) $row->site;
        $incognito = $this->incognitoSites;

        if ($incognito === []) {
            return false;
        }

        $ids = $this->variantIds($site);

        return $ids !== [] && count(array_intersect($ids, $incognito)) === count($ids);
    }

    /**
     * How many of this reader's library series sit on this site.
     *
     * Only shown on the incognito control, because it is the number that decides whether
     * pressing it is a good idea: a reader who keeps twenty series from a scanlation site
     * wants to know the trace stops before they turn it on, not after.
     */
    public function seriesCountFor(string $site): int
    {
        $key = (string) $site;

        return $this->incognitoCounts[$key] ??= app(Incognito::class)
            ->seriesCountFor((int) auth()->id(), $this->variantIds($site));
    }

    /**
     * Whether the pinned list reaches this row at all.
     *
     * Any, not every: skip's badge answers whether the reader retired the site, and
     * pins do not retire anything, so a site whose reader pinned it reads as pinned
     * whether its reader pinned one variant or the whole site. A reader who pinned
     * "these sites" is in the pinned state wherever any of their variants is.
     */
    public function isPinned(object $row): bool
    {
        $pinned = $this->pinnedIds;

        if ($pinned === []) {
            return false;
        }

        return $this->variantIds((string) $row->site, $pinned) !== [];
    }

    /**
     * Source ids behind a site.
     *
     * @return array<int, int>
     */
    private function variantIds(string $site, ?array $intersect = null): array
    {
        $ids = Source::query()->ofSite($site)->pluck('suwayomi_source_id')->all();

        return $intersect === null ? $ids : array_values(array_intersect($ids, $intersect));
    }
}; ?>

<div class="w-full space-y-6">
    <x-page.header back="{{ route('sources') }}" :back-label="__('Back to sources')">
        <x-slot:eyebrow>{{ __('Every source, watched.') }}</x-slot:eyebrow>
        <x-slot:title>{{ __('Source health') }}</x-slot:title>
    </x-page.header>

    @if (! $this->suwayomiReachable)
        <flux:callout variant="danger" icon="exclamation-triangle">
            {{ __('Not checked now, Suwayomi is unreachable. The counts below are the last observations.') }}
        </flux:callout>
    @endif

    {{-- Summary tiles ---------------------------------------------------- --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <flux:card class="card-press gap-1 p-4">
            <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">
                {{ __('Sites') }}
            </p>
            <p class="mt-0.5 font-display text-2xl font-semibold tabular-nums">{{ Number::format($this->tally['sites']) }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ trans_choice('{1} :count language variant|[2,*] :count language variants', $this->installedSources, ['count' => Number::format($this->installedSources)]) }}
            </p>
        </flux:card>

        @foreach ([
            HealthDashboard::STATE_HEALTHY => 'bg-moss-500',
            HealthDashboard::STATE_UNOBSERVED => 'bg-zinc-400 dark:bg-zinc-600',
            HealthDashboard::STATE_SILENT => 'bg-amber-500',
            HealthDashboard::STATE_FAILING => 'bg-red-500',
            HealthDashboard::STATE_DEAD => 'bg-red-700',
        ] as $key => $dot)
            @php($isActive = $state === $key)

            <flux:card
                class="card-press gap-1 p-4 transition"
                @class([
                    'ring-2 ring-zinc-900 dark:ring-white' => $isActive,
                    'opacity-60' => ! $isActive && $this->tally[$key] === 0,
                ])
            >
                <button
                    type="button"
                    wire:click="setState('{{ $key }}')"
                    class="w-full text-start"
                    aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                >
                    <p class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">
                        <span class="size-1.5 rounded-full {{ $dot }}" aria-hidden="true"></span>
                        {{ $this->stateLabel($key) }}
                    </p>

                    <p class="mt-0.5 font-display text-2xl font-semibold tabular-nums">{{ Number::format($this->tally[$key]) }}</p>
                </button>
            </flux:card>
        @endforeach
    </div>

    <flux:callout variant="secondary" icon="information-circle">
        <p class="text-sm">
            {{ __('Health shows what Papyrus has seen while searching. Suwayomi does not report a live status for each source.') }}
        </p>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ trans_choice(
                'Extension stores ship one extension per language, so a single site arrives many times over. These :total sources are grouped into :sites sites.',
                $this->tally['sites'],
                [
                    'total' => Number::format($this->installedSources),
                    'sites' => Number::format($this->tally['sites']),
                ],
            ) }}
        </p>
    </flux:callout>

    {{-- Filter ------------------------------------------------------------ --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <flux:input
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Filter by site or source name') }}"
            aria-label="{{ __('Filter by site or source name') }}"
            icon="funnel"
            class="max-w-sm"
        />

        <div class="flex flex-wrap items-center gap-2">
            @if ($state !== '')
                <flux:button size="sm" wire:click="$set('state', '')" icon="x-mark">
                    {{ __('Clear state filter') }}
                </flux:button>
            @endif

            @foreach (['dead', 'failing', 'silent'] as $trouble)
                @if ($this->tally[$trouble] > 0)
                    <flux:button size="sm" wire:click="setState('{{ $trouble }}')" color="red" icon="exclamation-triangle">
                        {{ trans_choice('{1} :count site|[2,*] :count sites', $this->tally[$trouble], ['count' => $this->tally[$trouble]]) }}
                        {{ $this->stateLabel($trouble) }}
                    </flux:button>
                @endif
            @endforeach
        </div>
    </div>

    {{-- Sites ------------------------------------------------------------- --}}
    @if ($this->sites->isEmpty())
        @if ($openSite !== '')
            {{--
                A linked site that is no longer in the ledger is not an empty
                search. Naming it and offering the list back is the truth the
                reader followed the link for.
            --}}
            <flux:callout variant="secondary" icon="magnifying-glass">
                <p class="text-sm">{{ __('No site in the health ledger matches :site.', ['site' => $openSite]) }}</p>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Clear the site filter to see every site.') }}</p>

                <flux:button class="mt-3" size="sm" :href="route('health')" wire:navigate icon="arrow-uturn-left">
                    {{ __('Show every site') }}
                </flux:button>
            </flux:callout>
        @else
            <flux:callout variant="secondary" icon="magnifying-glass">
                {{ __('No sites match. Run a search to start filling the ledger.') }}
            </flux:callout>
        @endif
    @else
        <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10">
            @foreach ($this->sites as $row)
                @php($skipped = $this->isSkipped($row))

                <li>
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <flux:button
                            variant="ghost"
                            size="sm"
                            wire:click="toggleSite('{{ $row->site }}')"
                            :aria-expanded="$openSite === $row->site ? 'true' : 'false'"
                            icon="{{ $openSite === $row->site ? 'chevron-down' : 'chevron-right' }}"
                            {{-- The site's own name, because a row of chevrons is a
                                 row of buttons a screen reader announces identically
                                 without it. The house's shape is the thing in the
                                 name ("Add <title> to your library", "Open <title>
                                 on <source>"). --}}
                            aria-label="{{ __('Show the sources of :site', ['site' => $row->label ?: $row->site]) }}"
                        />

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ $row->label ?: __('(unnamed)') }}</span>

                                <flux:badge
                                    size="sm"
                                    color="{{ match ($row->state) {
                                        HealthDashboard::STATE_DEAD, HealthDashboard::STATE_FAILING => 'red',
                                        HealthDashboard::STATE_SILENT => 'orange',
                                        HealthDashboard::STATE_HEALTHY => 'green',
                                        default => 'zinc',
                                    } }}"
                                >
                                    {{ $this->stateLabel($row->state) }}
                                </flux:badge>

                                @if ($skipped)
                                    <flux:badge size="sm" color="zinc" icon="eye-slash">{{ __('skipped by you') }}</flux:badge>
                                @endif

                                @if ($this->isPinned($row))
                                    <flux:badge size="sm" color="zinc">{{ __('pinned by you') }}</flux:badge>
                                @endif
                            </div>

                            <p class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $row->site === 'unknown' ? __('no home URL published') : $row->site }}
                            </p>

                            <p class="mt-1 text-[0.7rem] font-medium uppercase tracking-[0.12em] text-zinc-500 dark:text-zinc-400">
                                {{ $this->siteSummary($row->site) }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-4 text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                            <span>
                                {{ trans_choice('{1} :count variant|[2,*] :count variants', $row->variants, ['count' => $row->variants]) }}
                            </span>

                            <span @class([
                                'text-amber-600 dark:text-amber-400' => $row->worst_latency_ms >= 5000,
                            ])>
                                {{ $row->worst_latency_ms ? Number::format($row->worst_latency_ms).'ms' : __('unknown') }}
                            </span>
                        </div>

                        {{--
                            No Skip button here. It lives in the drill-down, where
                            the count of variants it would affect is on screen,
                            so a single button cannot quietly retire 61 sources.
                            The badge still shows if the site is already skipped.
                        --}}
                        @if ($skipped)
                            <flux:button
                                size="sm"
                                variant="ghost"
                                wire:click="toggleSite('{{ $row->site }}')"
                                icon="arrow-uturn-left"
                            >
                                {{ __('Skipped') }}
                            </flux:button>
                        @endif

                        @if ($this->isPinned($row))
                            <flux:button
                                size="sm"
                                variant="ghost"
                                wire:click="toggleSite('{{ $row->site }}')"
                            >
                                {{ __('Pinned') }}
                            </flux:button>
                        @endif
                    </div>

                    {{-- Drill-down: the language variants behind the site ---- --}}
                    @if ($openSite === $row->site)
                        {{-- The row's own variant count, not the rollup across all sources, so the header cannot contradict the list below it. --}}
                        <div class="border-t border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                            {{--
                                Capped, because the largest site carries 61
                                variants and rendering all of them pushes the rest
                                of the list off the page. MangaDex is worst
                                observed at 1,153ms against a 300ms median, so the
                                slow ones need to be reachable rather than buried.
                            --}}
                            @php($worstFirst = $this->variants->sortByDesc(fn ($v) => $v->consecutive_failures > 0 ? 1 : ($v->avg_latency_ms ?? 0)))

                            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ trans_choice('{1} :count language, slowest and worst first|[2,*] :count languages, slowest and worst first', $this->variants->count(), ['count' => Number::format($this->variants->count())]) }}
                                </p>

                                {{-- Skipping lives here, not on the row: one button for "leave me alone", next to the 61 rows it would affect. --}}
                                @if ($skipped)
                                    <flux:button size="sm" wire:click="unskip('{{ $row->site }}')" icon="arrow-uturn-left">
                                        {{ __('Search this site again') }}
                                    </flux:button>
                                @else
                                    @php($skipAction = 'skip('.Illuminate\Support\Js::from($row->site)->toHtml().')')

                                    <x-confirm
                                        :action="$skipAction"
                                        :title="__('Stop searching this site?')"
                                        :body="__('It stays installed and can be restored.')"
                                        :confirm="__('Skip')"
                                    >
                                        <flux:button size="sm" icon="eye-slash">
                                            {{ trans_choice('Skip this variant|[2,*] Skip all :count variants', $row->variants, ['count' => Number::format($row->variants)]) }}
                                        </flux:button>
                                    </x-confirm>
                                @endif

                                {{--
                                    Pin, beside Skip, because the two phrases are one
                                    conversation: skip says what the app may not ask, pin
                                    says what it asks first. Its own confirm, because the
                                    consequence is the same size in the other direction:
                                    while any pin stands, every site without one waits.
                                --}}
                                @if ($this->isPinned($row))
                                    <flux:button size="sm" wire:click="unpin('{{ $row->site }}')">
                                        {{ __('Search every site again') }}
                                    </flux:button>
                                @else
                                    @php($pinAction = 'pin('.Illuminate\Support\Js::from($row->site)->toHtml().')')

                                    <x-confirm
                                        :action="$pinAction"
                                        :title="__('Search only this site while its pin stands?')"
                                        :body="trans_choice(
                                            'Search asks this one variant alone until the pin is cleared, and every other site waits out of the way.|[2,*] Search asks the :count variants of this site alone until the pin is cleared, and every other site waits out of the way.',
                                            $row->variants,
                                            ['count' => Number::format($row->variants)],
                                        )"
                                        :confirm="__('Pin')"
                                    >
                                        <flux:button size="sm">
                                            {{ __('Pin for search') }}
                                        </flux:button>
                                    </x-confirm>
                                @endif

                                {{--
                                    Incognito, beside Skip and phrased as its own question, because
                                    it is one. Skip is about what the app asks for; incognito is
                                    about what it keeps. Putting the two next to each other
                                    without that distinction would let a reader turn off the
                                    wrong one, and the two have separate columns because they
                                    answer separate questions.

                                    Its own confirm, naming what it does and does not do. The
                                    library count is in the body rather than after the press,
                                    because "20 series stay in your library" is the fact that
                                    decides whether someone wants this, and it is only knowable
                                    before.
                                --}}
                                @if ($this->isIncognito($row))
                                    <flux:button size="sm" wire:click="reveal('{{ $row->site }}')" icon="eye">
                                        {{ __('Record my reading here') }}
                                    </flux:button>
                                @else
                                    @php($libraryHere = $this->seriesCountFor($row->site))
                                    @php($incognitoAction = 'incognito('.Illuminate\Support\Js::from($row->site)->toHtml().')')

                                    <x-confirm
                                        :action="$incognitoAction"
                                        :title="__('Read here without leaving a trace?')"
                                        :body="$libraryHere > 0
                                            ? trans_choice(
                                                'Reading from this site will not be recorded: no history, no page position, nothing sent to AniList. Your :count series stays in your library.',
                                                'Reading from this site will not be recorded: no history, no page position, nothing sent to AniList. Your :count series stay in your library.',
                                                ['count' => Number::format($libraryHere)],
                                                $libraryHere,
                                            )
                                            : __('Reading from this site will not be recorded: no history, no page position, nothing sent to AniList.')"
                                        :confirm="__('Go incognito')"
                                    >
                                        <flux:button size="sm" icon="eye-slash">
                                            {{ trans_choice('Read without history|[2,*] Read all :count variants without history', $row->variants, ['count' => Number::format($row->variants)]) }}
                                        </flux:button>
                                    </x-confirm>
                                @endif
                            </div>

                            <ul class="space-y-1">
                                @foreach ($worstFirst->take(self::DRILLDOWN_LIMIT) as $variant)
                                    <li class="flex flex-wrap items-center justify-between gap-3 text-sm">
                                        <span class="flex items-center gap-2">
                                            <flux:icon
                                                @class([
                                                    'text-moss-600 dark:text-moss-400' => $variant->total_successes > 0 && $variant->consecutive_failures === 0,
                                                    'text-amber-600 dark:text-amber-400' => $variant->consecutive_empty > 0,
                                                    'text-red-600 dark:text-red-400' => $variant->consecutive_failures > 0,
                                                ])
                                                :name="match (true) {
                                                    $variant->consecutive_failures > 0 => 'exclamation-triangle',
                                                    $variant->consecutive_empty > 0 => 'minus-circle',
                                                    default => 'check-circle',
                                                }"
                                            />

                                            <span>{{ $variant->name }}</span>

                                            <span class="text-xs text-zinc-500">{{ $variant->lang }}</span>
                                        </span>

                                        <span class="flex items-center gap-3 text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                                            @if ($variant->last_error)
                                                <flux:tooltip :content="$variant->last_error">
                                                    <flux:icon name="information-circle" class="text-zinc-400" />
                                                </flux:tooltip>
                                            @endif

                                            <span>{{ $variant->total_successes }} {{ __('ok') }}</span>

                                            @if ($variant->consecutive_empty > 0)
                                                <span>{{ $variant->consecutive_empty }} {{ __('empty') }}</span>
                                            @endif

                                            @if ($variant->consecutive_failures > 0)
                                                <span class="text-red-600 dark:text-red-400">
                                                    {{ $variant->consecutive_failures }} {{ __('failed') }}
                                                </span>
                                            @endif

                                            @if ($variant->last_success_at)
                                                <span>{{ $variant->last_success_at->diffForHumans() }}</span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            @if ($this->variants->count() > self::DRILLDOWN_LIMIT)
                                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ trans_choice(
                                        'Showing the :shown slowest and worst first of :total.|[2,*] Showing the :shown slowest and worst first of :total.',
                                        $this->variants->count(),
                                        [
                                            'shown' => self::DRILLDOWN_LIMIT,
                                            'total' => Number::format($this->variants->count()),
                                        ],
                                    ) }}
                                </p>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        <div>
            {{ $this->sites->links() }}
        </div>
    @endif
</div>
