<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        <title>{{ __('Page not found') }} &middot; {{ config('app.name') }}</title>
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        @include('errors.partials.ink', [
            'code' => '404',
            'line' => __('This shelf does not exist.'),
            'message' => __('The page you asked for is not here. It may have moved, or it may never have been built.'),
            'icon' => 'question-mark-circle',
        ])

        @include('partials.behaviors')
        @fluxScripts
    </body>
</html>
