<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        {{--
            The auth screen for pages that are content rather than a form, which the FAQ is today.

            The simple shell's seat for the slot is a max-w-sm glass card sized for a form, and the
            page of prose that was seated there read as a squeezed column beside a dead pane. Here
            the slot is a reading column wide enough for prose, and the statement panel stays, so the
            page keeps the same two sided composition without pretending to be a form.

            The footer is the shared one rather than a buried sentence, because the promise plus the
            FAQ and log-in links are part of the shell, not of any one page's copy.
        --}}
        <div class="relative flex min-h-svh flex-col">
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute inset-0 bg-linear-to-br from-zinc-100 via-zinc-50 to-vermilion-100/40 dark:from-zinc-900 dark:via-zinc-950 dark:to-zinc-900"></div>
                <div class="absolute -top-40 -start-40 size-[34rem] rounded-full bg-vermilion-500/15 blur-[120px] dark:bg-vermilion-500/10"></div>
                <div class="absolute -bottom-48 end-0 size-[30rem] rounded-full bg-zinc-400/20 blur-[120px] dark:bg-vermilion-700/10"></div>
            </div>

            <div class="relative flex flex-1">
                {{-- Statement panel --}}
                <aside class="relative hidden w-[46%] flex-col overflow-hidden p-12 lg:flex xl:p-16">
                    <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-3" tabindex="-1" aria-hidden="true">
                        <span class="seal size-10">
                            <x-app-logo-icon class="size-[17px]" />
                        </span>
                        <span class="font-display text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">{{ config('app.name', 'Papyrus') }}</span>
                    </a>

                    <div class="my-auto max-w-md">
                        <p class="serif-italic text-3xl leading-snug text-zinc-700 dark:text-zinc-200">
                            {{ __('Your whole library, every source, one quiet room.') }}
                        </p>

                        <p class="mt-4 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
                            {{ __('Papyrus sits between you and Suwayomi: shelves, reading progress, chapter notes and offline downloads, all per reader, all yours.') }}
                        </p>
                    </div>
                </aside>

                {{-- Content panel --}}
                <main class="relative flex min-w-0 flex-1 items-start justify-center p-6 md:p-10">
                    <div class="w-full max-w-2xl animate-rise-in py-10 lg:pt-16">
                        <div class="mb-10 flex items-center gap-3 lg:hidden">
                            <span class="seal size-10">
                                <x-app-logo-icon class="size-[17px]" />
                            </span>
                            <span class="font-display text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">{{ config('app.name', 'Papyrus') }}</span>
                        </div>

                        {{ $slot }}

                        <div class="mt-12 border-t border-zinc-200/70 pt-6 dark:border-white/10">
                            <x-auth-footer />
                        </div>
                    </div>
                </main>
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
