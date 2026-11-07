<?php

use App\Support\Duration;
use App\Support\Library;
use App\Support\ReadingInsights;
use App\Suwayomi\SuwayomiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
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

    /**
     * The browser tab follows the selected language.
     *
     * `#[Title]` is a PHP attribute and cannot call `__()`.
     */
    public function render(): \Illuminate\View\View
    {
        return $this->view()->title(__('Insights'));
    }

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
     * The rows to render, already joined to what the library's lens can give
     * them: the name, the cover and the unread figure from one entry.
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

        return $raw->map(function (array $row) use ($named): array {
            $entry = $named[$row['manga_id']] ?? null;
            $title = $entry['title'] ?? null;

            return [
                'manga_id' => $row['manga_id'],
                /*
                 * A blank string is not a name either: a filed row whose own state
                 * carries no title renders the same honest "Series :id" as one the
                 * whole lens could not see, because an empty link is not a name.
                 */
                'title' => filled($title) ? $title : null,
                // The same entry answers for the picture, so a named row and its
                // cover cannot disagree about what the server knows.
                'cover' => $entry['cover'] ?? null,
                'unread_chapters' => $entry['unread_chapters'] ?? null,
                'seconds' => $row['seconds'],
                'native_seconds' => $row['native_seconds'],
                'imported_seconds' => $row['imported_seconds'],
                'pages' => $row['pages'],
                'sessions' => $row['sessions'],
                'last_active_at' => $row['last_active_at'],
            ];
        });
    }

    #[Computed]
    public function totals(): array
    {
        $rows = $this->rows;

        return [
            'seconds' => (int) $rows->sum('seconds'),
            'native_seconds' => (int) $rows->sum('native_seconds'),
            'imported_seconds' => (int) $rows->sum('imported_seconds'),
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
            : __('since :date', ['date' => $this->bound->isoFormat('LL')]);
        $spent = Duration::forHumans($totals['seconds']);

        return trans_choice('{1} :time across one series :span.|[2,*] :time across :series series :span.', $totals['series'], [
            'time' => $spent,
            'series' => Number::format($totals['series']),
            'span' => $span,
        ]);
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
            {{ __('These numbers come from reading recorded on this machine, plus reading time carried over from backups you imported. Papyrus does not send or request anything to create them.') }}
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

    @if (! ($this->rows->isEmpty() || $this->totals['seconds'] === 0))
        <flux:callout variant="secondary" icon="sparkles">
            <p class="font-display text-base font-semibold">{{ $this->line }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('Counted by the day each sitting began, so no window counts the same minutes twice.') }}
            </p>
        </flux:callout>
    @endif

    {{-- Totals. Hidden until there is something to show, so an empty ledger does
         not announce three zero cards. -------------------------------------- --}}
    @if (! ($this->rows->isEmpty() || $this->totals['seconds'] === 0))
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <flux:card class="card-press gap-1 p-4">
                <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Reading time') }}</p>
                <p class="mt-0.5 font-display text-2xl font-semibold tabular-nums">{{ Duration::forHumans($this->totals['seconds']) }}</p>
                @if ($this->totals['imported_seconds'] > 0)
                    {{-- Imported time is in the total, so it is named rather than left
                         for the reader to discover by subtracting. --}}
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Includes :time from imported backups', ['time' => Duration::forHumans($this->totals['imported_seconds'])]) }}
                    </p>
                @endif
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
    @endif

    {{-- Per series, largest first ----------------------------------------- --}}
    <flux:card class="p-0">
        <div class="border-b border-zinc-200 p-4 dark:border-white/10">
            <h2 class="font-display text-sm font-semibold">{{ __('Reading time by series') }}</h2>
        </div>

        @if ($this->rows->isEmpty() || $this->totals['seconds'] === 0)
            <div class="p-6 text-center">
                <flux:icon.clock class="mx-auto size-8 text-zinc-400" aria-hidden="true" />
                <p class="mt-3 text-sm font-medium">{{ __('No reading recorded yet. Open a chapter and it will show up here.') }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    <a href="{{ route('library') }}" class="underline hover:text-zinc-700 dark:hover:text-zinc-200">{{ __('Go to your library') }}</a>
                </p>
            </div>
        @else
            <ul class="divide-y divide-zinc-200 dark:divide-white/10">
                {{-- Written as a block, not @php(...), because Blade pairs the first
                     @php with the next @endphp: the inline form here would swallow
                     every directive down to the row facts block at the bottom. --}}
                @php
                    $largest = $this->rows->first()['seconds'];
                @endphp

                @foreach ($this->rows as $row)
                    <li class="flex gap-3 p-4" data-test="insight-row" data-manga="{{ $row['manga_id'] }}" data-seconds="{{ $row['seconds'] }}">
                        {{-- The reader recognises a series by its cover before its title, so the
                             row opens with the same atom the migrate rows carry: row scale, no
                             tilt, the frame holding its own space. A cover the lens' entry does
                             not have is a null, and the atom's own placeholder answers: no cover
                             link is invented for a manga the server does not know. --}}
                        <div class="w-12 shrink-0">
                            <x-cover.art
                                :src="$row['cover']"
                                :alt="$row['title'] ?? ''"
                                :vt="false"
                            ></x-cover.art>
                        </div>

                        <div class="min-w-0 flex-1">
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

                                {{-- The minutes are the row's carrying number, in figures the eye
                                     can line up across every row. --}}
                                <flux:badge size="sm" class="tabular-nums">
                                    {{ Duration::forHumans($row['seconds']) }}
                                </flux:badge>
                            </div>

                            {{-- The bar. Width says the number the badge says in words, so the bar
                                 announces nothing rather than speaking the same value a second
                                 time: one glance, no axis, one spoken copy. Full width for the
                                 largest, a share for the rest. --}}
                            <div class="h-2 mt-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10" aria-hidden="true">
                                <div
                                    class="h-full rounded-full bg-vermilion-500"
                                    style="width: {{ number_format($largest > 0 ? 100 * $row['seconds'] / $largest : 0, 1, '.', '') }}%"
                                ></div>
                            </div>

                            @php
                                /*
                                 * Pages and sittings are facts only the native ledger has.
                                 * An imported day carries neither, so the row does not say
                                 * "0 sittings" beside two hours: it says where the time came
                                 * from instead, and keeps the native figures when both exist.
                                 */
                                $facts = [];

                                if ($row['native_seconds'] > 0) {
                                    $facts[] = trans_choice('{1} :count page|[2,*] :count pages', $row['pages'], ['count' => Number::format($row['pages'])]);
                                    $facts[] = trans_choice('{1} :count sitting|[2,*] :count sittings', $row['sessions'], ['count' => Number::format($row['sessions'])]);
                                }

                                if ($row['imported_seconds'] > 0) {
                                    $facts[] = __(':time from an imported backup', ['time' => Duration::forHumans($row['imported_seconds'])]);
                                }

                                if ($row['unread_chapters'] !== null) {
                                    $facts[] = trans_choice('{1} still :count chapter unread|[2,*] still :count chapters unread', $row['unread_chapters'], ['count' => Number::format($row['unread_chapters'])]);
                                }
                            @endphp

                            @if ($facts !== [])
                                <p class="text-xs mt-2 text-zinc-500 dark:text-zinc-400">
                                    {{ implode(' · ', $facts) }}
                                </p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </flux:card>
</div>
