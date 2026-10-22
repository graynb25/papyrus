<?php

use App\Support\Legal;
use App\Support\Outbound;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The privacy page, and the public seat of it.
 *
 * The auth content layout, for the same reason the FAQ's public page uses it:
 * a reader who has just run an application from an unknown download is, by
 * definition, not signed in yet, and that is exactly the reader who needs to
 * read what the application collects before they trust it. The FAQ page's own
 * comment records the crash that layout choice prevents, and this page would
 * hit the same one.
 *
 * The content is prose, and prose is the weak point in a codebase that tests
 * everything. Which is why the claims live in `App\Support\Legal` rather than
 * here, so a test can hold them. A privacy statement is the single easiest
 * thing in an application to let rot, because it stays true right up until
 * somebody adds a script and nobody re-reads the paragraph.
 */
new #[Layout('layouts::auth.content')] #[Title('Privacy')] class extends Component {
    #[Computed]
    public function document(): Legal
    {
        return app(Legal::class);
    }

    /**
     * The three things that contact the internet.
     *
     * Read from the same place the FAQ reads them, because they are the same
     * three facts and two copies of a list of what leaves the machine is a
     * copy that can drift from the machine.
     *
     * @return list<array{what: string, who: string}>
     */
    #[Computed]
    public function outbound(): array
    {
        return app(Outbound::class)->routes();
    }
}; ?>

<section class="w-full">
    {{--
        The promise, above everything else, before any of the sections.

        It is the first thing on the page because it is the reason a reader
        would look for a privacy page about an application they do not know
        and have just run from an unknown download. Everything below is detail
        in service of being able to check this sentence rather than take it.
    --}}
    <div class="mb-8">
        <p class="serif-italic text-base text-zinc-500 dark:text-zinc-400">
            {{ $this->document()->privacy()->promise }}
        </p>
        <h1 class="display-page mt-1 text-zinc-900 dark:text-zinc-50">{{ __('Privacy') }}</h1>
    </div>

    <div class="max-w-2xl">
        {{--
            The document itself is a component, because the same text is
            answered inside the app now and two copies of this markup would be
            two copies of a page that claims to be honest. What lives here is
            this page's own chrome: the promise above.
        --}}
        <x-legal.document
            :document="$this->document()->privacy()"
            :outbound="$this->outbound()"
        />

        {{--
            The terms, offered rather than mentioned, because a reader who
            has read what is collected deserves the other half of the pair
            next to it.
        --}}
        <div class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-800">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                <a
                    href="{{ route('terms') }}"
                    wire:navigate
                    class="font-medium text-zinc-900 underline underline-offset-4 dark:text-zinc-100"
                >{{ __('The terms of service, which say what running this yourself means') }}</a>
            </p>
        </div>
    </div>
</section>
