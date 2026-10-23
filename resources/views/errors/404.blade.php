@php
    $registration = app(App\Support\Registration::class);
    $registerPath = trim((string) request()->path(), '/');
    $closedRegistrationPage = $registerPath === 'register' && ! $registration->isOpen();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')

        <title>{{ __('Page not found') }} &middot; {{ config('app.name') }}</title>
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        @if ($closedRegistrationPage)
            <div class="flex min-h-svh flex-col items-center justify-center gap-5 p-6 text-center">
                <span class="seal size-14" aria-hidden="true">
                    <flux:icon name="lock-closed" class="size-6" />
                </span>

                <div>
                    <p class="display-hero text-zinc-900 dark:text-zinc-50">404</p>

                    <p class="serif-italic mt-2 text-xl text-zinc-500 dark:text-zinc-400">
                        {{ __('Sign-up is closed on this install.') }}
                    </p>
                </div>

                <p class="max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('If you have an account, sign in.') }}
                </p>

                <flux:button variant="primary" href="{{ route('login') }}" wire:navigate icon="arrow-right-circle">
                    {{ __('Sign in') }}
                </flux:button>
            </div>
        @else
            @include('errors.partials.ink', [
                'code' => '404',
                'line' => __('This shelf does not exist.'),
                'message' => __('The page you asked for is not here. It may have moved, or it may never have been built.'),
                'icon' => 'question-mark-circle',
            ])
        @endif

        @include('partials.behaviors')
        @fluxScripts
    </body>
</html>
