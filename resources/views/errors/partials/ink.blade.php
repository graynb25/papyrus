{{-- A shared material for the app's error pages: one seal, one serif line,
     one way out. Deliberately not the app sidebar shell: an error can render
     for a guest, and the shell reads auth()->user() without a guard. --}}
@props([])
<div class="flex min-h-svh flex-col items-center justify-center gap-5 p-6 text-center">
    <span class="seal size-14" aria-hidden="true">
        <flux:icon name="{{ $icon ?? 'question-mark-circle' }}" class="size-6" />
    </span>

    <div>
        <p class="display-hero text-zinc-900 dark:text-zinc-50">{{ $code }}</p>

        <p class="serif-italic mt-2 text-xl text-zinc-500 dark:text-zinc-400">
            {{ $line }}
        </p>
    </div>

    <p class="max-w-md text-sm text-zinc-500 dark:text-zinc-400">{{ $message }}</p>

    @if (auth()->check())
        <flux:button variant="primary" href="{{ route('library') }}" wire:navigate icon="book-open">
            {{ __('Back to your library') }}
        </flux:button>
    @else
        <flux:button variant="primary" href="{{ route('home') }}" icon="book-open">
            {{ __('Back to the reading room') }}
        </flux:button>
    @endif
</div>
