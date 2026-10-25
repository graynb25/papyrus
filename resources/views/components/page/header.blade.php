@props(['back' => null, 'backLabel' => null])

{{--
    The page header. One copy of the eyebrow / display title / meta / actions
    treatment every page used to hand-roll, which is how the H1 sizes drifted
    apart. The ink wash behind it is drawn oversized and reaches transparent
    before its edge, so it never grows a scrollbar.

    ## The back link names its parent rather than calling history

    `back` is a URL the page knows, not `history.back()`. A reader who arrived
    from a bookmark, a reload or a pasted link has no history along the path
    the page assumes, and a back control that does nothing is worse than none.
    Source health knows it was reached from Sources, and a migration review
    knows it came from Sources too, so both can say where the arrow goes.
--}}
<header {{ $attributes->class('relative') }}>
    <div class="hero-glow" aria-hidden="true"></div>

    @if ($back !== null)
        <div class="relative mb-3">
            <flux:button
                variant="ghost"
                size="sm"
                icon="arrow-left"
                :href="$back"
                wire:navigate
                class="-ms-2"
            >
                {{ $backLabel ?? __('Back') }}
            </flux:button>
        </div>
    @endif

    <div class="relative flex flex-wrap items-end justify-between gap-x-8 gap-y-4">
        <div class="min-w-0">
            @isset($eyebrow)
                <p class="serif-italic text-lg text-zinc-500 dark:text-zinc-400">
                    {{ $eyebrow }}
                </p>
            @endisset

            <h1 class="display-page mt-1 text-zinc-900 dark:text-zinc-50">
                {{ $title }}
            </h1>

            @isset($meta)
                <div class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $meta }}
                </div>
            @endisset
        </div>

        @isset($actions)
            <div class="flex flex-none items-center gap-2 pb-1">
                {{ $actions }}
            </div>
        @endisset
    </div>
</header>
