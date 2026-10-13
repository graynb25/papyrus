<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('connections.edit')" wire:navigate>{{ __('Connections') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            <flux:navlist.item :href="route('import.edit')" wire:navigate>{{ __('Import') }}</flux:navlist.item>
            {{-- Last, and read only. Everything above changes something about the reader's account
                 or their library; this one only reports what is installed, which is why it sits at
                 the bottom rather than among the things you set up. --}}
            <flux:navlist.item :href="route('about.edit')" wire:navigate>{{ __('About') }}</flux:navlist.item>
              {{-- Points out of Settings rather than into it, because /faq is public and a reader
                   should be able to reach it from either side. Listed with the read-only pages
                   because it changes nothing about the reader's account or their library. --}}
              <flux:navlist.item :href="route('faq')" wire:navigate>{{ __('Questions') }}</flux:navlist.item>
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
