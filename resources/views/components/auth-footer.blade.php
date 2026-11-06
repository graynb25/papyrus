{{--
    The footer the public shells share.

    How the FAQ used to be reachable: a sentence, styled one shade dimmer than the text beside it,
    with the link buried in the middle of it, so the page a reader who does not trust this download
    needs was visible only to a reader who already knew it existed. The owner named that pattern as
    the thing the other model shipped, and this component is the repair. The promise stands as text
    that cannot be mistaken for navigation, and the two places that answer to it are links with
    their own weight, so the FAQ is reachable from every public screen without reading anything.

    Seated by each caller: the bottom of the statement panel on the simple shell, the bottom row of
    the reading room, and under the page body on the content layout. The dark text shadow lives here
    rather than at each seat, because the shells are all forced dark and the wall behind the promise
    is different every time; the light faces stay shadowless for a day that never renders here.
--}}
<footer {{ $attributes->merge(['class' => 'text-xs']) }}>
    <p class="leading-relaxed text-zinc-500 dark:text-zinc-400 dark:[text-shadow:0_1px_10px_rgb(0_0_0/0.9)]">
        {{ __('Self-hosted. No tracking, no telemetry, no third party between you and your sources.') }}
    </p>

    <nav aria-label="{{ __('About Papyrus') }}" class="mt-2.5 flex flex-wrap items-center gap-3">
        <a
            href="{{ route('faq') }}"
            @if (request()->routeIs('faq')) aria-current="page" @endif
            class="font-normal text-zinc-500 underline underline-offset-4 transition-colors hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200"
        >{{ __('FAQ') }}</a>

        <span aria-hidden="true" class="text-zinc-400 dark:text-zinc-600">&middot;</span>

        {{--
            The privacy page and the terms of service, beside the FAQ.

            The reader who is standing on the login screen, deciding whether to
            trust a download, is the reader who most needs to read what the
            application collects and what running it means. Leaving them to
            find it would be the same mistake the FAQ's link was.
        --}}
        <a
            href="{{ route('privacy') }}"
            @if (request()->routeIs('privacy')) aria-current="page" @endif
            class="font-normal text-zinc-500 underline underline-offset-4 transition-colors hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200"
        >{{ __('Privacy') }}</a>

        <span aria-hidden="true" class="text-zinc-400 dark:text-zinc-600">&middot;</span>

        <a
            href="{{ route('terms') }}"
            @if (request()->routeIs('terms')) aria-current="page" @endif
            class="font-normal text-zinc-500 underline underline-offset-4 transition-colors hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200"
        >{{ __('Terms') }}</a>

        <span aria-hidden="true" class="text-zinc-400 dark:text-zinc-600">&middot;</span>

        <a
            href="{{ route('login') }}"
            @if (request()->routeIs('login')) aria-current="page" @endif
            class="font-normal text-zinc-500 underline underline-offset-4 transition-colors hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200"
        >{{ __('Log in') }}</a>
    </nav>

    <x-locale-switcher class="mt-3" />
</footer>
