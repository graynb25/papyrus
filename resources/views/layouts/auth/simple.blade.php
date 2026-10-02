<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        {{--
            The auth screen. A reading room, not a form: the left panel is the
            product statement set in the display faces over a slow ink gradient,
            and the card holds the one thing a returning reader needs. On a phone
            the statement steps aside and the form leads.
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
                    <span class="flex size-10 items-center justify-center rounded-xl bg-vermilion-600 shadow-lg shadow-vermilion-600/30">
                        <x-app-logo-icon class="size-5 fill-current text-white" />
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

                <p class="text-xs text-zinc-400 dark:text-zinc-600">
                    {{ __('Self-hosted. No tracking, no telemetry, no third party between you and your sources.') }}
                </p>
            </div>

            {{-- Form panel --}}
            <div class="relative flex min-w-0 flex-1 items-center justify-center p-6 md:p-10">
                <div class="w-full max-w-sm animate-rise-in">
                    <div class="mb-8 flex items-center gap-3 lg:hidden">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-vermilion-600 shadow-lg shadow-vermilion-600/30">
                            <x-app-logo-icon class="size-5 fill-current text-white" />
                        </span>
                        <span class="font-display text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">{{ config('app.name', 'Papyrus') }}</span>
                    </div>

                    <div class="flex flex-col gap-6 rounded-3xl border border-zinc-200/80 bg-white/80 p-7 shadow-xl shadow-zinc-950/5 backdrop-blur-md sm:p-8 dark:border-white/[0.07] dark:bg-zinc-900/70 dark:shadow-black/30">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
