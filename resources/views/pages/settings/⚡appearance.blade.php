<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Appearance settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
        </flux:radio.group>

        {{--
            What the choice looks like on the shelf. Static miniatures rather
            than a live preview, because the real page is one click away and a
            miniature that lied about the grid would be worse than none. Each
            one is the same three covers, in the paper and the ink.
        --}}
        <div class="mt-8 grid grid-cols-2 gap-4" aria-hidden="true">
            <div class="rounded-2xl border border-zinc-200/80 bg-zinc-50 p-4 dark:border-white/[0.06] dark:bg-zinc-950">
                <p class="mb-3 text-[0.65rem] font-medium tracking-wide text-zinc-500 uppercase">
                    {{ __('Paper') }}
                </p>

                <div class="grid grid-cols-3 gap-2">
                    @foreach (['bg-zinc-300', 'bg-vermilion-200', 'bg-zinc-200'] as $swatch)
                        <div class="aspect-[2/3] rounded-md {{ $swatch }} shadow-sm ring-1 ring-black/5"></div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200/80 bg-white p-4 dark:border-white/[0.06] dark:bg-zinc-900">
                <p class="mb-3 text-[0.65rem] font-medium tracking-wide text-zinc-400 uppercase dark:text-zinc-500">
                    {{ __('Ink') }}
                </p>

                <div class="grid grid-cols-3 gap-2">
                    @foreach (['bg-zinc-700', 'bg-vermilion-800', 'bg-zinc-800'] as $swatch)
                        <div class="aspect-[2/3] rounded-md {{ $swatch }} shadow-sm ring-1 ring-white/10"></div>
                    @endforeach
                </div>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
