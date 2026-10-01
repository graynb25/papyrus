<!DOCTYPE html>
{{--
    The reader could not open.

    Two cases worth telling apart, because they need different things from the
    reader: a timeout means try again, and an upstream refusal means the source is
    broken and retrying will not help.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    @include('partials.head')

    <title>{{ __('Cannot open chapter') }} &middot; {{ config('app.name') }}</title>

    <style>
        html { background: #0a0a0a; }

        body {
            margin: 0;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 2rem;
            background: #0a0a0a;
            color: #e4e4e7;
            font-family: ui-sans-serif, system-ui, sans-serif;
        }

        .box { max-width: 34rem; text-align: center; }

        h1 { font-size: 1.125rem; font-weight: 600; margin: 0 0 .75rem; }

        p { margin: 0 0 1.25rem; color: #a1a1aa; font-size: .875rem; line-height: 1.6; }

        pre {
            text-align: start;
            padding: .75rem;
            border-radius: .5rem;
            background: rgb(24 24 27);
            color: #d4d4d8;
            font-size: .75rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .actions { display: flex; gap: .625rem; justify-content: center; flex-wrap: wrap; }

        a, button {
            display: inline-flex;
            align-items: center;
            padding: .5rem .875rem;
            border-radius: .5rem;
            font-size: .8125rem;
            text-decoration: none;
            border: 1px solid #3f3f46;
            background: #18181b;
            color: #e4e4e7;
            cursor: pointer;
        }

        a:hover, button:hover { background: #27272a; }

        .primary { background: #e4e4e7; color: #18181b; border-color: #e4e4e7; }
        .primary:hover { background: #fff; }
    </style>
</head>

<body>
    <div class="box">
        <h1>
            @if ($isTimeout)
                {{ __('The source took too long') }}
            @else
                {{ __('The source would not serve this chapter') }}
            @endif
        </h1>

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
