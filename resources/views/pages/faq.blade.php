<?php

use App\Support\Faq;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The auth layout, chosen explicitly, and not by accident.
 *
 * Livewire's default page layout is the application shell, whose sidebar reads
 * `auth()->user()->name` in four places. This page is public, so on the very first request from a
 * reader who has not signed in, that layout threw "Attempt to read property name on null" and the
 * page that exists to answer "is this thing safe" was the one thing on the site that crashed.
 *
 * That failure is worth recording rather than only fixing. It is not a subtle bug: the page was
 * added, the route was added, the link was added, and every one of those was verified by asking
 * whether the route was defined and whether the view existed. Nobody rendered it signed out until a
 * test did.
 *
 * Both attributes sit on the `new` expression together rather than one per line. Livewire compiles a
 * page component by injecting a `return` ahead of the class, so an attribute written on its own line
 * above `new` lands on that `return` instead of the class, and the compiled file fails to parse with
 * an error naming neither the view nor the attribute.
 *
 * The layout is named `layouts::auth`, which is the anonymous component namespace rather than a view
 * path. `components.layouts.auth` is a view path, and Livewire raises MissingLayoutException on it
 * even though the file exists, because page layouts are resolved as components.
 */
new #[Layout('layouts::auth')] #[Title('Questions')] class extends Component {
    /*
     * Read only, and public on purpose.
     *
     * A reader who has just installed this and does not yet trust it cannot sign in, so a page
     * behind the login is a page that answers the question for everybody except the person asking
     * it. That is the wrong reader to lose.
     *
     * The content is prose, and prose is the weak point in a codebase that tests everything. Which is
     * why the claims live in `App\Support\Faq` rather than here, so a test can hold them. A privacy
     * statement is the single easiest thing in an application to let rot, because it stays true
     * right up until somebody adds a script and nobody re-reads the paragraph.
     */

    #[Computed]
    public function faq(): Faq
    {
        return app(Faq::class);
    }
}; ?>

<section class="w-full">
    {{--
        The promise, above everything else, before any of the questions.

        It is the first thing on the page because it is the reason a reader would look for an FAQ
        about an application they do not know and have just run from an unknown download. Everything
        below is detail in service of being able to check this sentence rather than take it.
    --}}
    <div class="mb-8">
        <p class="serif-italic text-base text-zinc-500 dark:text-zinc-400">
            {{ __('Everything here stays on this computer.') }}
        </p>
        <h1 class="display-page mt-1 text-zinc-900 dark:text-zinc-50">{{ __('Questions') }}</h1>
    </div>

    <div class="max-w-2xl space-y-8">
        @foreach ($this->faq()->entries() as $entry)
            <section>
                <h2 class="font-display text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
                    {{ $entry->question }}
                </h2>

                @foreach ($entry->answer as $paragraph)
                    <p class="mt-3 text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                        {{ $paragraph }}
                    </p>
                @endforeach

                {{--
                    The exceptions, as a list rather than a sentence buried in a paragraph.

                    This is the part of a privacy answer that gets skimmed, so it is given its own
                    block with its own heading. It appears only for the entry that has exceptions, so
                    the other seven questions are not padded with an apology they do not need.
                --}}
                @if ($entry->exceptions !== [])
                    <div class="mt-4 rounded-lg border border-amber-300/60 bg-amber-50/40 p-4 dark:border-amber-500/30 dark:bg-amber-500/5">
                        <p class="text-xs font-medium uppercase tracking-wide text-amber-800 dark:text-amber-300">
                            {{ __('What does reach the internet') }}
                        </p>

                        <ul class="mt-2 space-y-2">
                            @foreach ($this->faq()->outbound() as $route)
                                <li class="text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $route['what'] }}
                                    </span>
                                    <span class="block text-zinc-600 dark:text-zinc-400">
                                        {{ $route['who'] }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endforeach

        {{--
            The licences page, offered rather than mentioned, because "open source" is worth nothing
            to a reader who cannot see the list. And the list is honest: it names the one dependency
            that is not open source rather than leaving that to be discovered.
        --}}
        <div class="border-t border-zinc-200 pt-6 dark:border-zinc-800">
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                @if (auth()->check())
                    <a
                        href="{{ route('licences.edit') }}"
                        wire:navigate
                        class="font-medium text-zinc-900 underline underline-offset-4 dark:text-zinc-100"
                    >{{ __('Every package in this install, and its licence') }}</a>
                @else
                    <span>{{ __('Sign in to read every package in this install, and its licence.') }}</span>
                @endif
            </p>
        </div>
    </div>
</section>