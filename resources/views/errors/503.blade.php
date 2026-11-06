<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        <title>{{ __('Temporarily unavailable') }} &middot; {{ config('app.name') }}</title>
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        @include('errors.partials.ink', [
            'code' => '503',
            'line' => __('The reading room is closed for a moment.'),
            'message' => __('Papyrus cannot serve this page right now. Try again in a moment. If it keeps happening, the person who owns this server can find the details in the logs.'),
            'icon' => 'exclamation-triangle',
        ])

        @include('partials.behaviors')
        @fluxScripts
    </body>
</html>
