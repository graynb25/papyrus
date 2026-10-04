@props([
    'value' => null,
    'action' => 'rate',
    'label' => null,
])

@php
    use App\Support\ReadingState;

    $max = ReadingState::RATING_MAX;
    $current = max(0, (int) ($value ?? 0));

    /*
     * A button's accessible name has to carry the value it sets, because "star" five times over
     * tells a screen reader user nothing about what pressing one will do. The group label says
     * what the control is, the button label says what it will do.
     */
    $groupLabel = $label ?? __('Your rating');
@endphp

{{--
    `role="radiogroup"` with `role="radio"` children rather than five toggle buttons.

    The difference is not cosmetic. Toggle buttons give every star its own tab stop, so a
    keyboard reader presses Tab five times to rate a series, and a screen reader announces a
    radiogroup containing five tabbable children as the malformed arrangement it is. With the
    radio role the group is one tab stop and the arrow keys move inside it.

    The one thing `role="radio"` does not give is press-the-checked-one-to-clear, because a
    native radio does not fire on re-click. `rate()` in the page does that on purpose, so
    "press 3, press 3 again" means unrated and the `aria-checked` that results is true.
--}}
<div
    class="rating"
    data-rating
    role="radiogroup"
    aria-label="{{ $groupLabel }}"
    style="--rating: {{ $current }}; --max: {{ $max }};"
>
    @for ($star = 1; $star <= $max; $star++)
        <button
            type="button"
            data-rating-star
            role="radio"
            class="rating-star"
            style="--index: {{ $star }};"
            aria-checked="{{ $current === $star ? 'true' : 'false' }}"
            aria-label="{{ __('Rate :out of :max', ['out' => $star, 'max' => $max]) }}"
            {{-- No tabindex here on purpose.

                 behaviours.blade.php collapses the group to a single tab stop once it runs, and
                 it prefers wherever the reader's focus already is, then the rated star, then
                 the first. Rendering `tabindex="-1"` server-side would be tidier markup and
                 strictly worse behaviour: if the script never loads, every star is unreachable
                 and the rating cannot be given at all.

                 Rendering all five as ordinary buttons means the unenhanced state is five tab
                 stops, which is verbose but works, and the enhanced state is one, which is
                 correct. Failing towards usable is the whole choice. --}}
            {{-- Pointer events rather than `disabled` while the write is in flight.

                 Pressing the same star twice sets it and then clears it, so a double click on
                 star three would land on "unrated" through no decision of the reader's. That
                 has to be blocked. `wire:loading.attr="disabled"` blocks it by taking the
                 button out of the tab order mid-press, and a browser then moves focus to the
                 body, which loses the reader's place in the group entirely.

                 `pointer-events-none` stops the second click reaching the handler and leaves
                 focus exactly where it was. --}}
            wire:loading.class="pointer-events-none"
            wire:click="{{ $action }}({{ $star }})"
        >
            <flux:icon name="star" />
            <flux:icon name="star" variant="solid" class="rating-star--on" />
        </button>
    @endfor
</div>