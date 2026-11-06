<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        <title>{{ __('Page expired') }} &middot; {{ config('app.name') }}</title>
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        @include('errors.partials.ink', [
            'code' => '419',
            'line' => __('This page expired.'),
            'message' => __('Your session expired before the form was sent. Go back, reload the page, and try again.'),
            'icon' => 'exclamation-triangle',
        ])

        @include('partials.behaviors')
        @fluxScripts
    </body>
</html>
