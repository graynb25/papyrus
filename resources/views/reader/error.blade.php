<!DOCTYPE html>
{{--
    The reader could not open.

    Two cases worth telling apart, because they need different things from the
    reader: a timeout means try again, and an upstream refusal means the source is
    broken and retrying will not help.

    The palette is Ink & Vermilion by value rather than by import, for the same
    reason the reader itself stays a plain document: a dozen tokens are not worth
    the app bundle. The values are copies of resources/css/app.css and must move
    with it.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    @include('partials.head')

    <title>{{ __('Cannot open chapter') }} &middot; {{ config('app.name') }}</title>

    <style>
        :root {
            --ink-50: oklch(0.945 0.004 260);
            --ink-100: oklch(0.902 0.005 260);
            --ink-200: oklch(0.822 0.006 260);
            --ink-300: oklch(0.722 0.007 260);
            --ink-400: oklch(0.612 0.008 260);
            --ink-500: oklch(0.502 0.009 260);
            --ink-700: oklch(0.322 0.008 260);
            --ink-800: oklch(0.235 0.007 260);
            --ink-900: oklch(0.185 0.006 260);
            --ink-950: oklch(0.138 0.005 260);
            --vermilion-400: oklch(0.778 0.132 34);
            --vermilion-500: oklch(0.712 0.168 33);
            --vermilion-600: oklch(0.643 0.188 32);
        }

        * { box-sizing: border-box; }

        html {
            background: var(--ink-950);
            scrollbar-gutter: stable;
        }

        body {
            margin: 0;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 2rem;
            background:
                radial-gradient(42rem 19rem at 80% 0%, color-mix(in oklab, var(--vermilion-600) 8%, transparent), transparent 62%),
                var(--ink-950);
            color: var(--ink-200);
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
        }

        .box { max-width: 34rem; text-align: center; }

        .mark {
            display: inline-grid;
            place-items: center;
            inline-size: 3rem;
            block-size: 3rem;
            border-radius: .875rem;
            background: linear-gradient(145deg, var(--vermilion-500), var(--vermilion-600));
            color: white;
            transform: rotate(-3deg);
            box-shadow:
                inset 0 1px 0 color-mix(in oklab, white 35%, transparent),
                0 10px 24px -10px color-mix(in oklab, var(--vermilion-600) 70%, transparent);
        }

        h1 {
            font-family: 'Bricolage Grotesque', 'Instrument Sans', ui-sans-serif, sans-serif;
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: -0.02em;
            margin: 1.25rem 0 .75rem;
            color: var(--ink-100);
        }

        .whisper {
            font-family: 'Instrument Serif', Georgia, serif;
            font-style: italic;
            font-size: 1rem;
            color: var(--ink-400);
            margin: 0 0 1.25rem;
        }

        p { margin: 0 0 1.25rem; color: var(--ink-400); font-size: .875rem; line-height: 1.6; }

        pre {
            text-align: start;
            padding: .75rem;
            border-radius: .625rem;
            background: color-mix(in oklab, var(--ink-900) 88%, transparent);
            border: 1px solid color-mix(in oklab, white 8%, transparent);
            color: var(--ink-300);
            font-size: .75rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .actions { display: flex; gap: .625rem; justify-content: center; flex-wrap: wrap; margin-top: 1.5rem; }

        a, button {
            display: inline-flex;
            align-items: center;
            padding: .5rem .875rem;
            border-radius: .625rem;
            font-size: .8125rem;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid color-mix(in oklab, white 10%, transparent);
            background: color-mix(in oklab, var(--ink-800) 82%, transparent);
            color: var(--ink-200);
            cursor: pointer;
            transition: background .2s cubic-bezier(0.22, 1, 0.36, 1), border-color .2s;
        }

        a:hover, button:hover {
            background: color-mix(in oklab, var(--ink-700) 82%, transparent);
            border-color: color-mix(in oklab, white 16%, transparent);
        }

        .primary { background: var(--vermilion-500); color: var(--ink-950); border-color: transparent; }
        .primary:hover { background: var(--vermilion-400); border-color: transparent; }
    </style>
</head>

<body>
    <div class="box">
        <span class="mark" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
        </span>

        <h1>
            @if ($isTimeout)
                {{ __('The source took too long') }}
            @else
                {{ __('The source would not serve this chapter') }}
            @endif
        </h1>

        <p class="whisper">{{ __('Every source has its weather.') }}</p>

        <p>
            @if ($isTimeout)
                {{ __('Suwayomi downloads every page in a chapter before the reader can show it, and this one exceeded the time budget. The images may still arrive on a second attempt.') }}
            @else
                {{ __('Suwayomi reached the source and it refused, or returned nothing. This is usually a chapter that has been removed, or a source whose layout has changed.') }}
            @endif
        </p>

        <pre>{{ $message }}</pre>

        <div class="actions">
            @if ($isTimeout)
                <button type="button" onclick="window.location.reload()">
                    {{ __('Try again') }}
                </button>
            @endif

            <a href="{{ route('series', ['manga' => $mangaId]) }}">
                {{ __('Back to chapter list') }}
            </a>
        </div>
    </div>
</body>
</html>
