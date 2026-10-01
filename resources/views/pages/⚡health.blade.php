<?php

use App\Models\Source;
use App\Models\UserSource;
use App\Search\HealthDashboard;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

    public function toggleSite(string $site): void
    {
        $this->openSite = $this->openSite === $site ? '' : $site;
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
        return app(HealthDashboard::class)->sites([
            'state' => $this->state !== '' ? $this->state : null,
            'query' => trim($this->search),
        ]);
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
     * @return array<int, int>
     */
    #[Computed]
    public function skipped(): array
    {
        return app(HealthDashboard::class)->disabledSourceIds(auth()->id());
    }

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

        return $this->variantIds((string) $row->site, intersect: true) !== [];
    }

    /**
     * Source ids behind a site.
     *
     * @return array<int, int>
     */
    private function variantIds(string $site, bool $intersect = false): array
    {
        $ids = Source::query()
            ->when($site === 'unknown', fn ($q) => $q->where(fn ($w) => $w->whereNull('home_url')->orWhere('home_url', '')))
            ->when($site !== 'unknown', fn ($q) => $q->where('home_url', $site))
            ->pluck('suwayomi_source_id')
            ->all();

        return $intersect ? array_values(array_intersect($ids, $this->skipped)) : $ids;
    }
}; ?>

<div class="w-full space-y-6">
    <flux:heading level="1" class="sr-only">{{ __('Source health') }}</flux:heading>

    {{-- Summary tiles ---------------------------------------------------- --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <flux:card class="gap-1 p-4">
            <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">
                {{ __('Sites') }}
            </p>
            <p class="text-2xl font-semibold tabular-nums">{{ Number::format($this->tally['sites']) }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ trans_choice('{1} :count language variant|[2,*] :count language variants', $this->installedSources, ['count' => Number::format($this->installedSources)]) }}
            </p>
        </flux:card>

        @foreach ([
            HealthDashboard::STATE_HEALTHY => 'green',
            HealthDashboard::STATE_UNOBSERVED => 'zinc',
            HealthDashboard::STATE_SILENT => 'orange',
            HealthDashboard::STATE_FAILING => 'red',
            HealthDashboard::STATE_DEAD => 'red',
        ] as $key => $colour)
            @php($isActive = $state === $key)

            <flux:card
                class="gap-1 p-4 transition"
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
                    <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">
                        {{ $this->stateLabel($key) }}
                    </p>

                    <p class="text-2xl font-semibold tabular-nums">{{ Number::format($this->tally[$key]) }}</p>
                </button>
            </flux:card>
        @endforeach
    </div>

    <flux:callout variant="secondary" icon="information-circle">
        <p class="text-sm">
            {{ __('Health is observed, never asked for. Suwayomi exposes no per-source status anywhere in its API, so this ledger is built only from what our own searches came back with.') }}
        </p>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ trans_choice(
                'Keiyoushi ships one extension per language, so a single site arrives many times over. These :total sources are grouped into :sites sites.',
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
        <flux:callout variant="secondary" icon="magnifying-glass">
            {{ __('No sites match. Run a search to start filling the ledger.') }}
        </flux:callout>
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
                            </div>

                            <p class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $row->site === 'unknown' ? __('no home URL published') : $row->site }}
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
                                    <flux:button
                                        size="sm"
                                        wire:click="skip('{{ $row->site }}')"
                                        wire:confirm="{{ __('Stop searching this site and all its language variants? It stays installed and can be restored.') }}"
                                        icon="eye-slash"
                                    >
                                        {{ trans_choice('Skip this variant|[2,*] Skip all :count variants', $row->variants, ['count' => Number::format($row->variants)]) }}
                                    </flux:button>
                                @endif
                            </div>

                            <ul class="space-y-1">
                                @foreach ($worstFirst->take(self::DRILLDOWN_LIMIT) as $variant)
                                    <li class="flex flex-wrap items-center justify-between gap-3 text-sm">
                                        <span class="flex items-center gap-2">
                                            <flux:icon
                                                @class([
                                                    'text-green-600 dark:text-green-400' => $variant->total_successes > 0 && $variant->consecutive_failures === 0,
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