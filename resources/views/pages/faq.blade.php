<?php

use App\Support\Faq;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The auth content layout, chosen explicitly, and not by accident.
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
 * The layout is named `layouts::auth.content`, which is the anonymous component namespace rather
 * than a view path. `components.layouts.auth` is a view path, and Livewire raises
 * MissingLayoutException on it even though the file exists, because page layouts are resolved as
 * components.
 */
new #[Layout('layouts::auth.content')] #[Title('FAQ')] class extends Component {
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
        <h1 class="display-page mt-1 text-zinc-900 dark:text-zinc-50">{{ __('FAQ') }}</h1>
    </div>

    <div class="max-w-2xl">
        {{--
            The list itself is a component, because the same questions are answered inside the app
            now and two copies of this markup would be two copies of a page that claims to be
            honest. What lives here is this page's own chrome: the promise above and the licence
            link below.
        --}}
        <x-faq.entries :faq="$this->faq()" />

        {{--
            The licences page, offered rather than mentioned, because "open source" is worth nothing
            to a reader who cannot see the list. And the list is honest: it names the one dependency
            that is not open source rather than leaving that to be discovered.
        --}}
        <div class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-800">
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
