{{--
    The questions and their answers, as one list.

    ## Why this is a component and not the same markup on two pages

    The content lives in `App\Support\Faq`, so the claims are one list of one kind. The page that
    shows it is now two: the public one, which a reader who does not trust this download has to
    be able to read before signing in, and the one inside the app, which a signed in reader
    reaches from Settings. Two blades each looping the same list would be two copies of the
    same markup, and a copy of prose is a copy that can drift from the prose it claims to show.

    So the list is here, and each page owns only its own chrome: the promise, the footer and the
    licence link on the public one, the settings heading and the navlist on the other.

    The question is an `h2` on both pages, because on the public one it sits under the page's
    `h1` and on the settings one it sits under the settings heading, and an `h1` inside a
    disclosure would be two page titles in one document.
--}}
@props(['faq'])

{{--
    Each question opens its own answer in place, as a disclosure.

    A wall of every answer at once is what this looked like when the owner called it
    amateurish, and the fix is the pattern the rest of the app already carries: the native
    details element with a summary, exactly as the series page's folded description does it,
    keyboard and screen reader support coming free with the element. The privacy question is
    the one the page exists for, so it starts open and every other one starts shut.
--}}
<div class="space-y-3">
    @foreach ($faq->entries() as $entry)
        <details
            class="group rounded-lg border border-zinc-200 dark:border-white/10"
            {{ $entry->isPrivacy ? 'open' : '' }}
        >
            <summary
                class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-lg px-5 py-4 transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03]"
            >
                <h2 class="font-display text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
                    {{ $entry->question }}
                </h2>

                <flux:icon name="chevron-down" class="size-4 shrink-0 text-zinc-400 transition-transform group-open:rotate-180 dark:text-zinc-500" />
            </summary>

            <div class="flex flex-col gap-3 px-5 pb-5">
                @foreach ($entry->answer as $paragraph)
                    <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                        {{ $paragraph }}
                    </p>
                @endforeach

                {{--
                    The exceptions, as a list rather than a sentence buried in a paragraph.

                    This is the part of a privacy answer that gets skimmed, so it is given its own
                    block with its own heading. It appears only for the entry that has exceptions, so
                    the other questions are not padded with an apology they do not need.
                --}}
                @if ($entry->exceptions !== [])
                    <div class="rounded-lg border border-amber-300/60 bg-amber-50/40 p-4 dark:border-amber-500/30 dark:bg-amber-500/5">
                        <p class="text-xs font-medium uppercase tracking-wide text-amber-800 dark:text-amber-300">
                            {{ __('What does reach the internet') }}
                        </p>

                        <ul class="mt-2 space-y-2">
                            @foreach ($faq->outbound() as $route)
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
            </div>
        </details>
    @endforeach
</div>
