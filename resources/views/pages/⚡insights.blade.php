<?php

use App\Support\Library;
use App\Support\ReadingInsights;
use App\Suwayomi\SuwayomiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Insights')] class extends Component {
    /**
     * The window the page counts from, as a plain date.
     *
     * One state source for every slice: a button named "today" writes today's
     * date here rather than keeping a second idea of the window, so a link, a
     * reload and a slice button can never disagree about what is being counted.
     *
     * Anything that is not a well formed past-or-present date reads as the whole
     * ledger, which is the window the page was showing before there was a picker.
     */
    #[Url(as: 'since', except: '')]
    public string $since = '';

    public function mount(): void
    {
        $this->normalise();
    }

    public function setWindow(string $window): void
    {
        $today = CarbonImmutable::today();

        $this->since = match ($window) {
            'today' => $today->toDateString(),
            'week' => $today->startOfWeek()->toDateString(),
            'month' => $today->startOfMonth()->toDateString(),
            default => '',
        };
    }

    /**
     * Dated only in the one shape the queries can use, or nothing.
     *
     * A malformed string in the URL must not decide the totals: the same `?since`
     * arrives from a link, a bookmark and a shared screenshot, and an unvalidated
     * one is a rendering surprise waiting for its moment. A future date counts
     * nothing today and can only mislead a reader who pasted one, so it reads as
     * the whole ledger rather than as an empty page.
     */
    private function normalise(): void
    {
        $trimmed = trim($this->since);

        if ($trimmed === '') {
            $this->since = '';

            return;
        }

        /*
         * createFromFormat throws rather than returning false on a malformed
         * string, measured in the failing pages, so the shape is asked first.
         */
        if (! CarbonImmutable::hasFormat($trimmed, 'Y-m-d')) {
            $this->since = '';

            return;
        }

        /*
         * A date only format leaves the clock's own time of day, hours down
         * and microseconds included, on the value it builds: parsing at 07:41
         * produced a moment after midnight, and refusing anything after today
         * wiped every valid day into the whole ledger, measured in the tests
         * that passed for the wrong reason. The moment is clamped to its own
         * midnight so a day is compared against a day.
         */
        $date = CarbonImmutable::createFromFormat('Y-m-d', $trimmed)->startOfDay();

        if ($date === false || $date->isAfter(CarbonImmutable::today())) {
            $this->since = '';

            return;
        }

        $this->since = $date->toDateString();
    }

    /**
     * The bound the rows are counted from, or null for the whole ledger.
     */
    #[Computed]
    public function bound(): ?CarbonImmutable
    {
        return $this->since === ''
            ? null
            : CarbonImmutable::parse($this->since)->startOfDay();
    }

    /**
     * The rows to render, already joined to the names the library can give them.
     *
     * Titles and unread counts come from the same lens the library page pays for,
     * so the two pages cannot hold different ideas about the same series. A server
     * error is caught rather than propagated: an insights page that 500s because
     * Suwayomi blinked pixelizes the one reader who was fine ten minutes ago.
     */
    #[Computed]
    public function rows(): Collection
    {
        $raw = app(ReadingInsights::class)->bySeries(auth()->user(), $this->bound);

        if ($raw->isEmpty()) {
            return collect();
        }

        try {
            $named = app(Library::class)
                ->entries(auth()->user())
                ->keyBy('manga_id');
        } catch (SuwayomiException) {
            $named = collect();
        }

        return $raw->map(fn (array $row): array => [
            'manga_id' => $row['manga_id'],
            'title' => $named[$row['manga_id']]['title'] ?? null,
            'unread_chapters' => $named[$row['manga_id']]['unread_chapters'] ?? null,
            'seconds' => $row['seconds'],
            'pages' => $row['pages'],
            'sessions' => $row['sessions'],
            'last_active_at' => $row['last_active_at'],
        ]);
    }

    #[Computed]
    public function totals(): array
    {
        $rows = $this->rows;

        return [
            'seconds' => (int) $rows->sum('seconds'),
            'pages' => (int) $rows->sum('pages'),
            'series' => $rows->count(),
        ];
    }

    /**
     * The slice buttons, from one source of truth.
     *
     * @return array<int, array{label: string, window: string, active: bool}>
     */
    #[Computed]
    public function windows(): array
    {
        $today = CarbonImmutable::today();

        return [
            ['label' => __('Today'), 'window' => 'today', 'active' => $this->since === $today->toDateString()],
            ['label' => __('This week'), 'window' => 'week', 'active' => $this->since === $today->startOfWeek()->toDateString()],
            ['label' => __('This month'), 'window' => 'month', 'active' => $this->since === $today->startOfMonth()->toDateString()],
            ['label' => __('All time'), 'window' => 'all', 'active' => $this->since === ''],
        ];
    }

    /**
     * The one line the reader gets first, computed from real numbers.
     */
    #[Computed]
    public function line(): string
    {
        $totals = $this->totals;
        $span = $this->bound === null
            ? __('so far')
            : __('since :date', ['date' => $this->bound->translatedFormat('F jS Y')]);
        $spent = $this->formatSeconds($totals['seconds']);

        return trans_choice('{1} :time across one series :span.|[2,*] :time across :series series :span.', $totals['series'], [
            'time' => $spent,
            'series' => Number::format($totals['series']),
            'span' => $span,
        ]);
    }

    /**
     * Whole minutes dominate; a sitting shorter than a minute still says seconds
     * because "0 minutes" would be a lie about a reading that happened.
     */
    public function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return trans_choice('{1} :count second|[2,*] :count seconds', max(1, $seconds), ['count' => Number::format(max(1, $seconds))]);
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $hourPart = trans_choice('{1} :count hour|[2,*] :count hours', $hours, ['count' => Number::format($hours)]);
        $minutePart = trans_choice('{1} :count minute|[2,*] :count minutes', $minutes, ['count' => Number::format($minutes)]);

        return $hours === 0
            ? $minutePart
            : $hourPart.' '.$minutePart;
    }
};

?>
<div class="w-full space-y-6">
    <x-page.header>
        <x-slot:eyebrow>{{ __('Everything you read, counted.') }}</x-slot:eyebrow>
        <x-slot:title>{{ __('Insights') }}</x-slot:title>
    </x-page.header>

    {{-- The ledger's own honesty, said once, in its own block ---------------- --}}
    <flux:callout variant="secondary" icon="lock-closed">
        <p class="text-sm">
            {{ __('Every number on this page comes from rows written on this machine while a chapter was open and moving. Nothing is sent anywhere and nothing is asked for: it is the ledger of what this reader already did on this server, and nothing more.') }}
        </p>
    </flux:callout>

    {{-- Windows ---------------------------------------------------------- --}}
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('The window the counts are taken over') }}">
        @foreach ($this->windows() as $window)
            <flux:button
                size="sm"
                variant="ghost"
                @class([$window['active'] ? 'ring-2 ring-zinc-900 dark:ring-white' : ''])
                wire:click="setWindow('{{ $window['window'] }}')"
                :aria-pressed="$window['active'] ? 'true' : 'false'"
            >
                {{ $window['label'] }}
            </flux:button>
        @endforeach
    </div>

    <flux:callout variant="secondary" icon="sparkles">
        <p class="font-display text-base font-semibold">{{ $this->line }}</p>
        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('Counted by when each sitting began, so no window counts the same minutes twice.') }}
        </p>
    </flux:callout>

    {{-- Totals ------------------------------------------------------------ --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <flux:card class="card-press gap-1 p-4">
            <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Reading time') }}</p>
            <p class="mt-0.5 font-display text-2xl font-semibold tabular-nums">{{ $this->formatSeconds($this->totals['seconds']) }}</p>
        </flux:card>

        <flux:card class="card-press gap-1 p-4">
            <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Pages turned') }}</p>
            <p class="mt-0.5 font-display text-2xl font-semibold tabular-nums">{{ Number::format($this->totals['pages']) }}</p>
        </flux:card>

        <flux:card class="card-press gap-1 p-4">
            <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Series read') }}</p>
            <p class="mt-0.5 font-display text-2xl font-semibold tabular-nums">{{ Number::format($this->totals['series']) }}</p>
        </flux:card>
    </div>

    {{-- Per series, largest first ----------------------------------------- --}}
    <flux:card class="p-0">
        <div class="border-b border-zinc-200 p-4 dark:border-white/10">
            <h2 class="font-display text-sm font-semibold">{{ __('Reading time by series') }}</h2>
        </div>

        @if ($this->rows->isEmpty() || $this->totals['seconds'] === 0)
            <div class="p-6 text-center">
                <flux:icon.clock class="mx-auto size-8 text-zinc-400" aria-hidden="true" />
                <p class="mt-3 text-sm font-medium">{{ __('No reading counted yet in this window.') }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Open a chapter and the ledger starts. Series never opened write nothing, so an empty page is the truth and not a failure.') }}
                </p>
            </div>
        @else
            <ul class="divide-y divide-zinc-200 dark:divide-white/10">
                @php($largest = $this->rows->first()['seconds'])

                @foreach ($this->rows as $row)
                    <li class="flex flex-col gap-2 p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            @if ($row['title'] !== null)
                                <a href="{{ route('series', ['manga' => $row['manga_id']]) }}" class="font-medium hover:underline">
                                    {{ $row['title'] }}
                                </a>
                            @else
                                <span class="font-medium text-zinc-500 dark:text-zinc-400">
                                    {{ __('Series :id', ['id' => Number::format($row['manga_id'])]) }}
                                </span>
                            @endif

                            <flux:badge size="sm">
                                {{ $this->formatSeconds($row['seconds']) }}
                            </flux:badge>
                        </div>

                        {{-- The bar. Width says the number, the fill says it again: one glance, no
                             axis. Full width for the largest, a share for the rest. --}}
                        <div class="h-2.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10">
                            <div
                                class="h-full rounded-full bg-vermilion-500"
                                style="width: {{ Number::format($largest > 0 ? 100 * $row['seconds'] / $largest : 0, precision: 1) }}%"
                                role="img"
                                aria-label="{{ __(':title: :time of reading', ['title' => $row['title'] ?? __('Series :id', ['id' => Number::format($row['manga_id'])]), 'time' => $this->formatSeconds($row['seconds'])]) }}"
                            ></div>
                        </div>

                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ trans_choice('{1} :count page|[2,*] :count pages', $row['pages'], ['count' => Number::format($row['pages'])]) }}
                            &middot;
                            {{ trans_choice('{1} :count sitting|[2,*] :count sittings', $row['sessions'], ['count' => Number::format($row['sessions'])]) }}
                            @if ($row['unread_chapters'] !== null)
                                &middot;
                                {{ trans_choice('{1} still :count chapter unread|[2,*] still :count chapters unread', $row['unread_chapters'], ['count' => Number::format($row['unread_chapters'])]) }}
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </flux:card>
</div>
