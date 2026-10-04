<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        <title>{{ __('Something broke') }} &middot; {{ config('app.name') }}</title>
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        @include('errors.partials.ink', [
            'code' => '500',
            'line' => __('A page fell out of its binding.'),
            'message' => __('The request failed inside the app, not at a source. Nothing you did caused it, and the reader of this server can find the details in the logs.'),
            'icon' => 'exclamation-triangle',
        ])

        @include('partials.behaviors')
        @fluxScripts
    </body>
</html>
