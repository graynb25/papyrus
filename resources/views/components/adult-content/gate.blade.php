@props([
    // Whether the gate is on for this reader right now.
    'enabled' => false,
])

{{--
    The adult content gate, and the one control that opens it.

    One copy of the state and the control, for the two pages that filter by the
    catalogue's rating. The Sources page filters extensions and the Search page
    filters sources, and the sentence is the same in both because the setting is
    the same: what is off is what the catalogue marks NSFW, and what is on is a
    rating in scope rather than a rating shown.

    The button opens the house's own `x-confirm` dialog rather than a new
    component, because the dialog it needs is one that already exists and
    carries the 18+ statement in its body. The action it runs is
    `confirmAdultContent`, which the `AdultContentGate` concern gives both
    pages, so the control and the action cannot drift apart.
--}}
<div class="flex flex-wrap items-center gap-2">
    @if ($enabled)
        <flux:badge size="sm" color="red" icon="exclamation-triangle">{{ __('Adult content on') }}</flux:badge>

        <span class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('The NSFW rating is in scope. The filter above decides what is shown of it.') }}
        </span>

        <flux:button size="sm" variant="ghost" wire:click="turnAdultContentOff">
            {{ __('Turn it off') }}
        </flux:button>
    @else
        <flux:badge size="sm" color="zinc" icon="lock-closed">{{ __('Adult content off') }}</flux:badge>

        <span class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing marked NSFW is listed or searched.') }}
        </span>

        <x-confirm
            action="confirmAdultContent"
            :title="__('Turn on adult content?')"
            :body="app(App\Support\AdultContent::class)->statement()"
            :confirm="__('I am 18 or older, turn it on')"
        >
            <flux:button size="sm" variant="outline" icon="exclamation-triangle">
                {{ __('Turn it on') }}
            </flux:button>
        </x-confirm>
    @endif
</div>
