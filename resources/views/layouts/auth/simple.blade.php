<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        {{--
            The auth screen for everything that is not the login: register,
            reset, confirm, two-factor. The same reading-room idea at a calmer
            volume, since these pages are visited rarely and often under mild
            stress: the ink gradient and the glow stay, the wall does not come
            along.
        --}}
        <div class="relative flex min-h-svh">
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute inset-0 bg-linear-to-br from-zinc-100 via-zinc-50 to-vermilion-100/40 dark:from-zinc-900 dark:via-zinc-950 dark:to-zinc-900"></div>
                <div class="absolute -top-40 -start-40 size-[34rem] rounded-full bg-vermilion-500/15 blur-[120px] dark:bg-vermilion-500/10"></div>
                <div class="absolute -bottom-48 end-0 size-[30rem] rounded-full bg-zinc-400/20 blur-[120px] dark:bg-vermilion-700/10"></div>
            </div>

            {{-- Statement panel --}}
            <div class="relative hidden w-[46%] flex-col justify-between overflow-hidden p-12 lg:flex xl:p-16">
                <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-3" tabindex="-1" aria-hidden="true">
                    <span class="seal size-10">
                        <x-app-logo-icon class="size-5 fill-current" />
                    </span>
                    <span class="font-display text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">{{ config('app.name', 'Papyrus') }}</span>
                </a>

                <div class="max-w-md">
                    <p class="serif-italic text-3xl leading-snug text-zinc-700 dark:text-zinc-200">
                        {{ __('Your whole library, every source, one quiet room.') }}
                    </p>

                    <p class="mt-4 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
                        {{ __('Papyrus sits between you and Suwayomi: shelves, reading progress, chapter notes and offline downloads, all per reader, all yours.') }}
                    </p>
                </div>

                {{-- The shell's footer, rather than the promise buried as a link inside a sentence. --}}
                <x-auth-footer />
            </div>

            {{-- Form panel --}}
            <div class="relative flex min-w-0 flex-1 items-start justify-center p-6 pt-12 md:p-10 md:pt-16">
                <div class="w-full max-w-sm animate-rise-in">
                    <div class="mb-8 flex items-center gap-3 lg:hidden">
                        <span class="seal size-10">
                            <x-app-logo-icon class="size-5 fill-current" />
                        </span>
                        <span class="font-display text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">{{ config('app.name', 'Papyrus') }}</span>
                    </div>

                    <div class="glass flex flex-col gap-6 rounded-3xl p-7 sm:p-8">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>

        @include('partials.behaviors')

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
