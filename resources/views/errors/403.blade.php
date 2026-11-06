<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        <title>{{ __('Forbidden') }} &middot; {{ config('app.name') }}</title>
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        @include('errors.partials.ink', [
            'code' => '403',
            'line' => __('This shelf is not yours.'),
            'message' => __('You do not have permission to open this page. If you think this is a mistake, ask the person who owns this server.'),
            'icon' => 'lock-closed',
        ])

        @include('partials.behaviors')
        @fluxScripts
    </body>
</html>
