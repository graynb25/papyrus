@props([
    // Resolved cover URL, or null for the placeholder.
    'src' => null,
    'alt' => '',
    // Reading progress as 0-100, or null for no hairline.
    'progress' => null,
    // Blurred light behind the card. Sits outside the clipping frame.
    'glow' => false,
    // Perspective lean on hover. Owned by the wrap, not the frame.
    'tilt' => false,
    // Shimmer + blur-up reveal for the artwork.
    'blurUp' => true,
    // Set on grid cards so a click morphs the artwork into the series hero.
    'vt' => false,
    'placeholderIcon' => 'photo',
    'sizes' => '(min-width: 1280px) 20vw, (min-width: 768px) 25vw, 50vw',
])

@php
    $srcset = $src === null ? null : \App\Support\ImageUrl::responsiveSet($src);
@endphp

{{--
    The cover atom. One copy of the frame/scrim/progress treatment the grid
    pages used to hand-maintain, so the craft cannot drift between them the
    way it did when it was four inline copies.

    The frame reserves the 2/3 ratio itself rather than the image, so a cover
    that has not loaded still holds its space and the grid does not jump as
    the page fills. Everything overlaid on the artwork (badges, toggles) goes
    in the slot and sits outside the frame's clipping context, so the root is
    always its positioned ancestor: with glow and tilt both off nothing else
    in the chain is positioned, and measured live the badges then anchored on
    the nearest card instead, every row's chip stacked on one corner.
--}}
<div
    {{ $attributes->class([
        'relative',
        'cover-wrap' => $glow || $tilt,
        'cover-tilt' => $tilt,
    ]) }}
>
    @if ($glow)
        <span class="cover-glow" aria-hidden="true"></span>
    @endif

    <div class="cover-frame" @if ($vt) data-vt-cover @endif>
        @if ($src !== null && $blurUp)
            <span class="cover-underlay skeleton" aria-hidden="true"></span>
        @endif

        @if ($src !== null)
            <img
                src="{{ $src }}"
                @if ($srcset !== null) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
                alt="{{ $alt }}"
                loading="lazy"
                decoding="async"
                @if ($blurUp) class="img-blur-up" data-blur-up @endif
            >
        @else
            <div class="cover-placeholder flex items-center justify-center text-zinc-300 dark:text-zinc-600">
                <flux:icon name="{{ $placeholderIcon }}" class="size-8" />
            </div>
        @endif

        <div class="cover-scrim" aria-hidden="true"></div>

        @if ($progress !== null && $progress > 0)
            <div class="cover-progress" aria-hidden="true">
                <span style="width: {{ min(100, $progress) }}%"></span>
            </div>
        @endif
    </div>

    {{-- The slot's overlays sit above the frame rather than under the artwork:
         the wrapper owns the stacking, and pointer events pass through to the
         frame unless a child opts back in, so cover clicks still reach it. --}}
    <div class="cover-slot">
        {{ $slot }}
    </div>
</div>
