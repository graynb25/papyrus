<x-layouts::app.sidebar :title="$title ?? null">
    {{-- The main region is what wire:navigate crossfades between pages, and the
         max width keeps 60-cover grids breathable on very wide screens. The
         important modifiers beat Flux's own default padding. A native main keeps
         the landmark the walk asked for; data-flux-main and [grid-area:main] are
         what Flux's grid CSS keys off, so they replace what flux:main added. --}}
    <main
        id="main-content"
        data-flux-main
        wire:transition.navigate
        class="[grid-area:main] mx-auto w-full max-w-[110rem]! px-4! py-7! sm:px-6! lg:px-10! lg:py-10!"
    >
        {{ $slot }}
    </main>

    <livewire:onboarding.first-run-tour
        :auto-start="request()->routeIs('library')"
        :current-route="request()->route()?->getName()"
    />
</x-layouts::app.sidebar>
