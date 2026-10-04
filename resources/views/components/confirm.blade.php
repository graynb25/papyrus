@props([
    // The Livewire action the confirmation runs, e.g. `removeSelected`.
    'action',
    'title',
    'body' => null,
    'confirm' => null,
    'cancel' => null,
])

@php($modal = 'confirm-'.md5($action.'|'.$title))

{{--
    A destructive action's second thought, in the app's own material instead
    of a browser `confirm()`. The trigger button is the slot; the action moves
    to the button inside the modal, which is why the trigger keeps its label
    and the modal's button carries `wire:click`: feature tests that assert on
    the action string still see it, and calling the method directly still
    works.
--}}
<div {{ $attributes }}>
    <flux:modal.trigger :name="$modal">
        {{ $slot }}
    </flux:modal.trigger>

    <flux:modal :name="$modal" class="max-w-md">
        <div class="flex items-start gap-4">
            <span
                class="mt-0.5 flex size-10 flex-none items-center justify-center rounded-xl bg-vermilion-600/10 text-vermilion-600 dark:bg-vermilion-500/15 dark:text-vermilion-400"
                aria-hidden="true"
            >
                <flux:icon name="exclamation-triangle" class="size-5" />
            </span>

            <div class="min-w-0">
                <flux:heading size="lg">{{ $title }}</flux:heading>

                @if ($body)
                    <flux:subheading class="mt-2">{{ $body }}</flux:subheading>
                @endif
            </div>
        </div>

        <div class="mt-7 flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled">{{ $cancel ?? __('Never mind') }}</flux:button>
            </flux:modal.close>

            <flux:modal.close>
                <flux:button variant="danger" wire:click="{{ $action }}" wire:loading.attr="disabled">
                    {{ $confirm ?? __('Confirm') }}
                </flux:button>
            </flux:modal.close>
        </div>
    </flux:modal>
</div>
