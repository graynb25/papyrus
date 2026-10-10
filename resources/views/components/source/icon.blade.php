@props([
    // The icon path Suwayomi sent, e.g. /api/v1/extension/icon/<packageName>.
    'path' => null,
    // Decorative by default: the name is always rendered beside it as text.
    'alt' => '',
    'size' => 'size-10',
    'class' => '',
])

{{--
    A source or extension icon, on the app's own origin.

    Both `sources.icon_url` and the extension list's `iconUrl` have been stored and
    fetched since the catalogue was mirrored, and neither had ever been rendered. The
    lists were drawn from text alone, which is what made them read as flat and generic:
    fifty rows of a name and a badge, fifty times, with nothing to tell them apart.

    ## Why it goes through the proxy

    Suwayomi's icon endpoint requires the bearer token. Measured: anonymous 401 with a
    twelve byte body, with a token 200 and image/png. A browser cannot hold that token,
    so an `<img>` pointed straight at Suwayomi either renders nothing or leaks the
    credential into the markup. The existing `/suwayomi` proxy already solves this for
    covers, and extension icons were added to its allowlist rather than opening a new
    route.

    ## Why the frame is there regardless

    The tile reserves the square before the bytes arrive, so fifty icons appearing in a
    grid do not shove the layout sideways one at a time. It is also the state an icon
    that 404s ends up in: the image removes itself and what is left reads as a
    deliberate placeholder rather than a broken image glyph.
--}}
@php
    $frame = 'flex shrink-0 items-center justify-center overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-white/[0.04] '.$size.' '.$class;

    $src = $path === null
        ? null
        : url(rtrim((string) config('papyrus.suwayomi.public_path_prefix'), '/').'/'.ltrim($path, '/'));
@endphp

<span class="{{ $frame }}">
    @if ($src !== null)
        <img
            src="{{ $src }}"
            alt="{{ $alt }}"
            loading="lazy"
            decoding="async"
            class="size-full object-contain"
            onerror="this.remove()"
        />
    @else
        <flux:icon name="globe-alt" class="size-1/2 text-zinc-300 dark:text-zinc-600" />
    @endif
</span>