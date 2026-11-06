<?php

use App\Suwayomi\Gateway;
use App\Support\Versions;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * The browser tab follows the selected language.
     *
     * `#[Title]` is a PHP attribute and cannot call `__()`.
     */
    public function render(): \Illuminate\View\View
    {
        return $this->view()->title(__('About this install'));
    }

    /*
     * Everything on this page is read, never written.
     *
     * There is no update button here, deliberately. A reader who clicks one and gets a spinner and
     * no update has learned that Papyrus cannot update itself, which is true and worse than a
     * sentence saying so. What this page does is give the two numbers a bug report starts with, and
     * say plainly when the second of them is a problem worth an afternoon.
     */

    #[Computed]
    public function versions(): Versions
    {
        return app(Versions::class);
    }

    /** The Suwayomi in front of us, or null when it could not be reached at all. */
    #[Computed]
    public function suwayomi(): ?string
    {
        return $this->versions()->runningSuwayomi(app(Gateway::class));
    }

    #[Computed]
    public function verdict(): string
    {
        return $this->versions()->verdict($this->suwayomi());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('About this install') }}</flux:heading>

    <x-pages::settings.layout :heading="__('About')" :subheading="__('Which versions are running')">
        {{--
            The numbers, and the commit under the first.

            A plain definition list rather than a card per fact. There are three of them and they are
            one answer to one question, so anything giving each its own border would be saying they
            were separate things. The values stay in the design's ordinary text colour because a
            version number is content rather than a status, and colouring it would imply a verdict the
            reader has not been given yet.
        --}}
        <dl class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Papyrus') }}</dt>
            <dd>
                {{ $this->versions()->papyrus() ?? __('unknown') }}
                @if ($this->versions()->commit())
                    <span class="text-zinc-500 dark:text-zinc-400">
                        ({{ $this->versions()->commit() }})
                    </span>
                @endif
            </dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Suwayomi') }}</dt>
            <dd>{{ $this->suwayomi() ?? __('not answering') }}</dd>

            @if ($this->versions()->compatibility()->isKnown())
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Suwayomi this install was tested with') }}</dt>
                <dd>{{ $this->versions()->compatibility()->tested() }}</dd>
            @endif
        </dl>

        {{--
            One callout, and only when there is something to say.

            The three cases are genuinely different and are not collapsed into one "notice". A server
            too old to work is a danger and says what will break. A server newer than the one this
            build was tested against is a warning rather than a failure, because it is very likely fine
            and saying otherwise in the failure colour teaches a reader to ignore the one line on this
            page that is worth reading. And a server that could not be reached is neither: it is
            already reported as its own row above, and is not dressed up as a version problem.
        --}}
        @if ($this->verdict() === Versions::TOO_OLD)
            <flux:callout class="mt-6" variant="danger" icon="exclamation-triangle">
                <x-slot name="heading">{{ __('Suwayomi is too old for this install') }}</x-slot>

                {{
                    __(
                        'This install needs Suwayomi '.$this->versions()->compatibility()->minimum().' or newer, but the one running is older. Chapters may be missing, downloads may fail and searches may return nothing.'
                    )
                }}

                @if (app()->environment('local'))
                    {{ __('You are running from source. Update the jar and rebuild; docs/SETUP-SUWAYOMI.md has the steps.') }}
                @else
                    {{ __('This is a packaged install, so updating Papyrus brings a newer Suwayomi. Until then, downloads and search may not work.') }}
                @endif
            </flux:callout>
        @elseif ($this->verdict() === Versions::UNTESTED)
            <flux:callout class="mt-6" variant="warning" icon="information-circle">
                <x-slot name="heading">{{ __('Suwayomi is newer than this install was tested against') }}</x-slot>

                {{
                    __(
                        'This build was tested with Suwayomi '.$this->versions()->compatibility()->tested().', but a newer one is running. It will probably work, but it has not been tested here. Include both versions if something goes wrong.'
                    )
                }}
            </flux:callout>
        @endif

        {{--
            What to put in a bug report, as a closing line rather than a callout.

            This is the whole reason the page exists. Every number above is something a reader would
            otherwise have to go and find, and the first three questions of any report are about them.
        --}}
        <p class="mt-6 text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('If you report a problem, include the Papyrus and Suwayomi versions shown above.') }}
        </p>

        {{--
            D6 chose this sentence over a network check. A reader who wants to know whether a
            newer Papyrus exists should not have to guess, and this install should not make an
            outbound request to tell them. The link is the only place the release list lives.
        --}}
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
            {{ __('Updates are manual. This install never checks for new versions. Watch the') }}
            <a
                href="https://github.com/graynb25/papyrus/releases"
                target="_blank"
                rel="noopener noreferrer"
                class="underline underline-offset-2 hover:text-vermilion-600 dark:hover:text-vermilion-400"
            >{{ __('releases page') }}</a>
            {{ __('or follow its') }}
            <a
                href="https://github.com/graynb25/papyrus/releases.atom"
                target="_blank"
                rel="noopener noreferrer"
                class="underline underline-offset-2 hover:text-vermilion-600 dark:hover:text-vermilion-400"
            >{{ __('RSS feed') }}</a>
            {{ __('for new ones.') }}
        </p>
    </x-pages::settings.layout>
</section>
