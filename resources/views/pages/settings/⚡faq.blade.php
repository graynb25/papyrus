<?php

use App\Models\SeriesState;
use App\Models\User;
use App\Support\Faq;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('FAQ')] class extends Component {
    /*
     * The same questions, answered inside the app.
     *
     * ## Why there is a second page for one list
     *
     * Because the Settings navlist's FAQ item pointed at the public page, and the public page
     * sits on the marketing layout. A reader who opened Settings to ask a question was thrown
     * out of the app to read it, and then had to find their way back in.
     *
     * The public page stays exactly where it is, because a reader who has just run an
     * application from an unknown download is, by definition, not signed in yet, and it is the
     * page somebody else can be sent to. So the list is in `App\Support\Faq`, the markup is in
     * one component, and this page owns the settings chrome and nothing else. The two pages
     * cannot answer different questions, because there is only one list.
     *
     * The reading column here is the settings slot, which is narrower than the public page's.
     * That is the width every settings page gets, and the answers are written to read in it.
     */

    #[Computed]
    public function faq(): Faq
    {
        return app(Faq::class);
    }

    /**
     * Start the tour again from the first step that exists on this account.
     *
     * A reader who already has a library has no empty-library panel to
     * spotlight, so a replay starts at Sources rather than pointing at a card
     * that is not on the page. The tour is owner-only because every store and
     * extension action is behind `manage-install`.
     */
    public function replayTour(): void
    {
        Gate::authorize('manage-install');

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $step = SeriesState::query()->where('in_library', true)->exists() ? 2 : 1;

        $user->forceFill([
            'tour_step' => $step,
            'tour_completed_at' => null,
        ])->save();

        $this->redirectRoute($step === 1 ? 'library' : 'sources', navigate: true);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('FAQ') }}</flux:heading>

    <x-pages::settings.layout :heading="__('FAQ')" :subheading="__('What the app does, and what it will not do')">
        @can('manage-install')
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200/80 bg-zinc-50/60 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                <div class="min-w-0">
                    <p class="text-sm font-medium">{{ __('Replay the guided tour') }}</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Walk through adding a source, searching, and reading again.') }}
                    </p>
                </div>

                <flux:button variant="outline" icon="arrow-path" wire:click="replayTour">
                    {{ __('Replay the tour') }}
                </flux:button>
            </div>
        @endcan

        <x-faq.entries :faq="$this->faq()" />
    </x-pages::settings.layout>
</section>
