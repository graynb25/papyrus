<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[260px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>
                <span class="block">{{ __('Profile') }}</span>
                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('Name and email for this account') }}</span>
            </flux:navlist.item>
            <flux:navlist.item :href="route('connections.edit')" wire:navigate>
                <span class="block">{{ __('Connections') }}</span>
                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('AniList tracking and tokens') }}</span>
            </flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>
                <span class="block">{{ __('Security') }}</span>
                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('Password and sessions') }}</span>
            </flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>
                <span class="block">{{ __('Appearance') }}</span>
                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('Theme and language') }}</span>
            </flux:navlist.item>
            <flux:navlist.item :href="route('import.edit')" wire:navigate>
                <span class="block">{{ __('Import') }}</span>
                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('Bring reading time from a backup') }}</span>
            </flux:navlist.item>
            {{-- Set apart from the account pages, and only for the install owner: this page
                 can replace the whole library, not just change one account. --}}
            @can('manage-install')
                <flux:navlist.item :href="route('backups.edit')" wire:navigate>
                    <span class="block">{{ __('Backups') }}</span>
                    <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('Export and restore for the whole install') }}</span>
                </flux:navlist.item>
            @endcan
    <flux:navlist.item :href="route('about.edit')" wire:navigate>
        <span class="block">{{ __('About') }}</span>
        <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('Version and server status') }}</span>
    </flux:navlist.item>
              {{-- Stays inside the app, because answering a question should not throw the reader
                   out of it. The public FAQ page is still there and is still public, for a reader
                   who has not signed in yet and for somebody else to be sent to; this is the same
                   list at a seat the reader can stay in. Listed with the read-only pages because
                   it changes nothing about the reader's account or their library. --}}
              <flux:navlist.item :href="route('settings.faq')" wire:navigate>
                <span class="block">{{ __('FAQ') }}</span>
                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('Answers without leaving the app') }}</span>
              </flux:navlist.item>
              {{-- The privacy page and the terms of service, with the read only pages for the
                   same reason: they change nothing about the reader's account or their library,
                   they only say what the application does. --}}
              <flux:navlist.item :href="route('settings.privacy')" wire:navigate>
                <span class="block">{{ __('Privacy') }}</span>
                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('What is recorded and where it stays') }}</span>
              </flux:navlist.item>
              <flux:navlist.item :href="route('settings.terms')" wire:navigate>
                <span class="block">{{ __('Terms') }}</span>
                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('The rules for using this install') }}</span>
              </flux:navlist.item>
              <flux:navlist.item :href="route('licences.edit')" wire:navigate>
                <span class="block">{{ __('Licences') }}</span>
                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('Open source credits') }}</span>
              </flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading class="font-display font-semibold tracking-tight">{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
