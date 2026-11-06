<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        <title>{{ __('Too many requests') }} &middot; {{ config('app.name') }}</title>
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        @include('errors.partials.ink', [
            'code' => '429',
            'line' => __('Slow down a little.'),
            'message' => __('Too many requests arrived at once. Wait a moment, then try again.'),
            'icon' => 'clock',
        ])

        @include('partials.behaviors')
        @fluxScripts
    </body>
</html>
