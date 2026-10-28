@props([
    // The document to render.
    'document',
    // The outbound list, for the one section that carries it.
    'outbound' => [],
])

{{--
    A legal page, as one document.

    ## Why this is a component and not the same markup on two pages

    The content lives in `App\Support\Legal`, so the claims are one document
    of one kind. The page that shows it is two: the public one, which a reader
    who does not trust this download has to be able to read before signing in,
    and the one inside the app, which a signed in reader reaches from
    Settings. Two blades each looping the same sections would be two copies
    of the same markup, and a copy of prose is a copy that can drift from the
    prose it claims to show.

    So the document is here, and each page owns only its own chrome: the
    promise and the title on the public one, the settings heading and the
    navlist on the other.

    The sections are not disclosures. The FAQ's questions are, because a
    reader arrives there with one question in mind; a privacy page is a
    document and is read as one, and a page that hides half of itself behind
    a click is a page whose shape is chosen by the reader's mood.
--}}
<div class="space-y-8">
    @foreach ($document->sections as $section)
        <section>
            <h2 class="font-display text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">
                {{ $section->heading }}
            </h2>

            <div class="mt-3 space-y-3">
                @foreach ($section->paragraphs as $paragraph)
                    <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-300">
                        {{ $paragraph }}
                    </p>
                @endforeach
            </div>

            {{--
                The exceptions, as a list rather than a sentence buried in a
                paragraph.

                This is the part of a privacy answer that gets skimmed, so it
                is given its own block. It appears only for the section that
                carries it, so the other sections are not padded with an
                apology they do not need.
            --}}
            @if ($section->exceptions)
                <div class="mt-4 rounded-lg border border-amber-300/60 bg-amber-50/40 p-4 dark:border-amber-500/30 dark:bg-amber-500/5">
                    <p class="text-xs font-medium uppercase tracking-wide text-amber-800 dark:text-amber-300">
                        {{ __('Each connection, one by one') }}
                    </p>

                    <ul class="mt-2 space-y-2">
                        @foreach ($outbound as $route)
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
</div>
