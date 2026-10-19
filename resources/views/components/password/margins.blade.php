{{-- A password's margins, on the page where the password is decided.

     The rows are written from the one place the validating rule is built
     from, so the browser can never know a different law than the server.
     The state is read from the fields themselves at every pass, so a field
     cleared by a Livewire reset rewrites the ticks instead of leaving them
     behind. Each row carries a shape change (a tick appears) rather than a
     colour change alone, and the breach margin refuses a tick it cannot
     earn, which is the one this list could otherwise use to lie. --}}
@props([
    'for' => null,
    'margins' => null,
    'matchFor' => null,
])

@php
$margins ??= config('papyrus.password_margins');

/*
 * The aria anchors are derived from the field ids rather than generated,
 * because the field points at this list through aria-describedby and the
 * two ends of that link have to agree across a render without anyone
 * negotiating it.
 */
$isMatchMeter = $matchFor !== null;
$anchor = ($isMatchMeter ? $matchFor : $for).'-margins';

/*
 * The rows, in the order the margins are written. `mixedCase` subsumes
 * `letters` (a password with both cases is made of letters), which is why
 * there is one case row and no letters row beside it; the label is the
 * only thing that differs between the two configurations.
 */
$rows = [];

if ($isMatchMeter) {
    $rows[] = ['key' => 'matches', 'label' => __('Matches the first password')];
} else {
    $rows[] = ['key' => 'length', 'label' => __('At least :n characters', ['n' => $margins['min']])];
    if (!empty($margins['mixedCase'])) {
        $rows[] = ['key' => 'case', 'label' => __('Uses upper and lower case')];
    } elseif (!empty($margins['letters'])) {
        $rows[] = ['key' => 'case', 'label' => __('Has letters')];
    }
    if (!empty($margins['numbers'])) {
        $rows[] = ['key' => 'number', 'label' => __('Has a number')];
    }
    if (!empty($margins['symbols'])) {
        $rows[] = ['key' => 'symbol', 'label' => __('Has a symbol')];
    }

    /*
     * The breach margin is the one a browser cannot run: the server asks
     * the compromise database when the form is sent. Where the margin
     * exists it is named on its own row, stated rather than ticked,
     * because a tick this list cannot earn would be the one thing here
     * that lied.
     */
    if (!empty($margins['breach_on_submit'])) {
        $rows[] = ['key' => 'breach', 'label' => __('Not in known leak lists (checked when you submit)')];
    }
}

/*
 * `keys` are the margins this meter can evaluate and the announcement
 * counts. The breach row is deliberately not a key: it is never met
 * client side, so it must never be counted as one that was.
 */
$keys = $isMatchMeter
    ? ['matches']
    : array_values(array_filter([
        'length',
        (empty($margins['mixedCase']) && empty($margins['letters'])) ? null : 'case',
        empty($margins['numbers']) ? null : 'number',
        empty($margins['symbols']) ? null : 'symbol',
    ]));

/*
 * Both fields join the watch in match mode, because the row turns on the
 * pair's agreement and a keystroke in either field can change it.
 */
$watch = $isMatchMeter ? [$for, $matchFor] : [$for];

/*
 * The words the meter speaks live with the component's other facts, where
 * the one summary row speaks them the same way in both themes.
 */
$copy = $isMatchMeter
    ? ['met' => __('The passwords match'), 'unmet' => __('Not matching yet')]
    : ['met' => __('All margins met'), 'unmet' => __(':n of :m margins met')];

$config = [
    'main' => (string) $for,
    'match' => $isMatchMeter ? $matchFor : null,
    'min' => (int) $margins['min'],
    'keys' => $keys,
    'watch' => $watch,
    'mode' => $isMatchMeter ? 'match' : 'margins',
    'copy' => $copy,
];
@endphp

<div
    id="{{ $anchor }}"
    data-meter="{{ json_encode($config) }}"
    x-data="{
        main: null,
        match: null,
        min: 12,
        keys: [],
        watch: [],
        mode: 'margins',
        copy: {},
        state: {},
        holding: false,

        met(key) {
            return this.state[key] === true;
        },
        rowColor(key) {
            return this.met(key)
                ? 'text-moss-600 dark:text-moss-400'
                : 'text-zinc-400 dark:text-zinc-600';
        },
        liveColor() {
            const done = this.keys.filter((key) => this.met(key)).length;

            return this.holding && done === this.keys.length
                ? 'text-moss-600 dark:text-moss-400'
                : 'text-zinc-500 dark:text-zinc-400';
        },
        recheck() {
            const main = document.getElementById(this.main);
            const other = this.match ? document.getElementById(this.match) : null;
            const value = main ? main.value : '';
            const state = {};

            /*
             * The predicates are the server's, translated: `\\p{L}` and
             * `\\p{N}` are the same character classes the validating rule
             * reads, so an accented letter is a letter here rather than a
             * symbol the row asks for.
             */
            state.length = value.length >= this.min;
            state.case = /\p{Ll}/u.test(value) && /\p{Lu}/u.test(value);
            state.number = /\p{N}/u.test(value);
            state.symbol = /[^\p{L}\p{N}]/u.test(value);
            state.matches = other !== null && value.length > 0 && other.value === value;

            this.holding = value.length > 0;
            this.state = state;
            this.announce();
        },
        react(event) {
            const id = event && event.target ? event.target.id : null;

            if (this.watch.indexOf(id) === -1) {
                return;
            }

            this.recheck();
        },
        announce() {
            const ref = this.$refs.live;

            if (!ref) {
                return;
            }

            let text = '';

            if (this.holding) {
                const done = this.keys.filter((key) => this.met(key)).length;

                text = done === this.keys.length
                    ? this.copy.met
                    : this.copy.unmet.replace(':n', done).replace(':m', this.keys.length);
            }

            /*
             * The region is polite, so it only speaks when its text changes,
             * which is why a keystroke that changes nothing announces
             * nothing and the count is not read out per keystroke.
             */
            if (ref.textContent !== text) {
                ref.textContent = text;
            }
        },
        watchResets() {
            if (typeof window.Livewire !== 'undefined' && typeof window.Livewire.hook === 'function') {
                this.followResets();

                return;
            }

            /*
             * The shared behaviors file records the ordering: livewire:init
             * is dispatched at the top of Livewire's own start, so the call
             * waits for the event and tries again exactly once.
             */
            document.addEventListener('livewire:init', (function () { this.watchResets(); }).bind(this), { once: true });
        },
        followResets() {
            const root = this.$el;

            window.Livewire.hook('morphed', (payload) => {
                const el = payload && payload.el;

                if (el && (el === root || (el.contains && el.contains(root)))) {
                    this.recheck();
                }
            });
        },
        init() {
            const config = JSON.parse(this.$el.getAttribute('data-meter'));

            this.main = config.main;
            this.match = config.match;
            this.min = config.min;
            this.keys = config.keys;
            this.watch = config.watch;
            this.mode = config.mode;
            this.copy = config.copy;
            this.recheck();
            this.watchResets();
        },
    }"
    x-on:input.window.debounce.300ms="react($event)"
    class="flex flex-col gap-1.5"
>
    <ul class="flex flex-col gap-1.5">
        @foreach ($rows as $row)
            <li class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                <span aria-hidden="true" class="flex size-4 flex-none items-center justify-center">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 16 16"
                        fill="none"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="size-4"
                        x-bind:class="rowColor('{{ $row['key'] }}')"
                    >
                        <circle cx="8" cy="8" r="6.5" stroke="currentColor" />
                        @if ($row['key'] !== 'breach')
                            {{-- The tick owes its first hidden state to the inline style:
                                 before Alpine runs, x-show is inert, and a page that
                                 renders every tick is a page that argues with the server. --}}
                            <path
                                d="M5 8.2l2 2 4-4.4"
                                stroke="currentColor"
                                style="display: none"
                                x-show="met('{{ $row['key'] }}')"
                            />
                        @else
                            <path d="M5 8h6" stroke="currentColor" />
                        @endif
                    </svg>
                </span>

                <span>{{ $row['label'] }}</span>
            </li>
        @endforeach
    </ul>

    <p x-ref="live" aria-live="polite" class="text-sm" x-bind:class="liveColor()"></p>
</div>
