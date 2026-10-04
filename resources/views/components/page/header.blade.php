@props([])

{{--
    The page header. One copy of the eyebrow / display title / meta / actions
    treatment every page used to hand-roll, which is how the H1 sizes drifted
    apart. The ink wash behind it is drawn oversized and reaches transparent
    before its edge, so it never grows a scrollbar.
--}}
<header {{ $attributes->class('relative') }}>
    <div class="hero-glow" aria-hidden="true"></div>

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
