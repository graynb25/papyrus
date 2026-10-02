<x-layouts::app.sidebar :title="$title ?? null">
    {{-- The main region is what wire:navigate crossfades between pages, and the
         max width keeps 60-cover grids breathable on very wide screens. The
         important modifiers beat flux:main's own default padding. --}}
    <flux:main
        wire:transition.navigate
        class="mx-auto w-full max-w-[110rem]! px-4! py-7! sm:px-6! lg:px-10! lg:py-10!"
    >
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
