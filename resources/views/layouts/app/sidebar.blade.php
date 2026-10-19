<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen app-backdrop antialiased">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200/80 bg-zinc-50 dark:border-white/[0.06] dark:bg-zinc-950">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('library') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                {{-- Library first. It is what the app is for, and a reader who
                     has just arrived goes straight to it. --}}
                <flux:sidebar.group :heading="__('Reading')">
                    <flux:sidebar.item icon="book-open" :href="route('library')" :current="request()->routeIs('library')" wire:navigate>
                        {{ __('Library') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="magnifying-glass" :href="route('search')" :current="request()->routeIs('search')" wire:navigate>
                        {{ __('Search') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="chart-bar" :href="route('insights')" :current="request()->routeIs('insights')" wire:navigate>
                        {{ __('Insights') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('System')">
                    <flux:sidebar.item icon="cloud-arrow-down" :href="route('downloads')" :current="request()->routeIs('downloads')" wire:navigate>
                        {{ __('Downloads') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="folder-git-2" :href="route('sources')" :current="request()->routeIs('sources')" wire:navigate>
                        {{ __('Sources') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="heart" :href="route('health')" :current="request()->routeIs('health')" wire:navigate>
                        {{ __('Health') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @include('partials.behaviors')

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
