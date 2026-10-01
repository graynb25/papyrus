<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

{{--
    Needed by the reader, which posts progress with fetch during reading and with
    sendBeacon on page close. Neither is a Livewire request, so neither picks up a
    token automatically. It reads this meta tag rather than a cookie, because
    sendBeacon cannot set headers.
--}}
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
