<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('connections.edit')" wire:navigate>{{ __('Connections') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            <flux:navlist.item :href="route('import.edit')" wire:navigate>{{ __('Import') }}</flux:navlist.item>
            {{-- Set apart from the account pages, and only for the install owner: this page
                 can replace the whole library, not just change one account. --}}
            @can('manage-install')
                <flux:navlist.item :href="route('backups.edit')" wire:navigate>{{ __('Backups') }}</flux:navlist.item>
            @endcan
    <flux:navlist.item :href="route('about.edit')" wire:navigate>{{ __('About') }}</flux:navlist.item>
              {{-- Stays inside the app, because answering a question should not throw the reader
                   out of it. The public FAQ page is still there and is still public, for a reader
                   who has not signed in yet and for somebody else to be sent to; this is the same
                   list at a seat the reader can stay in. Listed with the read-only pages because
                   it changes nothing about the reader's account or their library. --}}
              <flux:navlist.item :href="route('settings.faq')" wire:navigate>{{ __('FAQ') }}</flux:navlist.item>
              {{-- The privacy page and the terms of service, with the read only pages for the
                   same reason: they change nothing about the reader's account or their library,
                   they only say what the application does. --}}
              <flux:navlist.item :href="route('settings.privacy')" wire:navigate>{{ __('Privacy') }}</flux:navlist.item>
              <flux:navlist.item :href="route('settings.terms')" wire:navigate>{{ __('Terms') }}</flux:navlist.item>
              <flux:navlist.item :href="route('licences.edit')" wire:navigate>{{ __('Licences') }}</flux:navlist.item>
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
