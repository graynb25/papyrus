<?php

use App\Support\Legal;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The terms of service page, and the public seat of it.
 *
 * The auth content layout, for the same reason the privacy page's public
 * seat uses it: the reader who has just run the application from an unknown
 * download is the one who needs to read this before they sign in, and that
 * reader is by definition not signed in yet.
 *
 * The content is prose, and it lives in `App\Support\Legal` so a test can
 * hold it, the same way the FAQ's answers and the privacy page's sections are
 * held.
 */
new #[Layout('layouts::auth.content')] #[Title('Terms of service')] class extends Component {
    #[Computed]
    public function document(): Legal
    {
        return app(Legal::class);
    }
}; ?>

<section class="w-full">
    <div class="mb-8">
        <p class="serif-italic text-base text-zinc-500 dark:text-zinc-400">
            {{ $this->document()->terms()->promise }}
        </p>
        <h1 class="display-page mt-1 text-zinc-900 dark:text-zinc-50">{{ __('Terms of service') }}</h1>
    </div>

    <div class="max-w-2xl">
        <x-legal.document :document="$this->document()->terms()" />

        {{--
            The privacy page, offered rather than mentioned, because a reader
            who has read what they are agreeing to deserves the other half of
            the pair next to it.
        --}}
        <div class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-800">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                <a
                    href="{{ route('privacy') }}"
                    wire:navigate
                    class="font-medium text-zinc-900 underline underline-offset-4 dark:text-zinc-100"
                >{{ __('The privacy page, which says what this application collects') }}</a>
            </p>
        </div>
    </div>
</section>
