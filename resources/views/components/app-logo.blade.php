@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex size-10 items-center justify-center">
            <span class="seal size-8">
                <x-app-logo-icon class="size-3.5" />
            </span>
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex size-10 items-center justify-center">
            <span class="seal size-8">
                <x-app-logo-icon class="size-3.5" />
            </span>
        </x-slot>
    </flux:brand>
@endif
