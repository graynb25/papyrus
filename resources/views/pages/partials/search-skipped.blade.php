{{--
    What a run's filter terms cost, in sources left out.

    A term is pushed where the source has a matching filter and the source is skipped
    where it does not. Measured across the 82 installed sources, `genre` reaches 77,
    `status` 74 and `sort` 69, which is between five and thirteen of the forty the
    fan-out targets left unasked depending on the term.

    ## Why this has to be visible

    A filter that quietly removed a third of the sources looks exactly like a filter
    that found nothing. Both show a short result list, and the reader has no other way
    to tell which happened. Without this number the honest behaviour and a broken one
    are indistinguishable from the outside, which is the one thing a filter must not be.

    ## Why it is a partial

    It was written inline, in the branch that renders results, which is the branch that
    is *absent* when a filtered run finds nothing. So the runs that most needed to
    explain themselves were the ones that could not: a `status:` run that left nine
    sources unasked and matched nothing showed a bare "nothing matched" and said
    nothing about the nine.

    @param  \App\Models\SearchRun|null  $run
--}}
@if ($run && $run->sources_skipped > 0)
    {{--
        The count carries a hook so a check can find this line rather than the hint
        above the search box, which also uses the words "left out". A check matching on
        wording alone passed on the hint while the report itself was absent, which is
        the shape of a check that has quietly stopped checking anything.
    --}}
    <p
        data-skipped-sources="{{ (int) $run->sources_skipped }}"
        class="text-sm text-amber-700 dark:text-amber-400"
    >
        {{ trans_choice(
            ':count source could not be asked and was left out|[2,*] :count sources could not be asked and were left out',
            (int) $run->sources_skipped,
            ['count' => (int) $run->sources_skipped],
        ) }}
    </p>

    {{--
        Only once the run has finished. A source that has not reported yet is not known
        to have been skipped, so listing it while the run is still going would be a list
        of guesses presented as a fact.
    --}}
    @if ($run->isFinished())
        <details class="rounded-lg border border-amber-200 px-3 py-2 text-sm dark:border-amber-900">
            <summary class="cursor-pointer text-amber-800 dark:text-amber-300">
                {{ __('Which ones, and why') }}
            </summary>

            <ul data-skipped-list class="mt-2 space-y-1 text-amber-700 dark:text-amber-400">
                @foreach ($this->skippedSources as $skipped)
                    <li class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium">{{ $skipped['source_name'] }}</span>
                        <span class="text-xs opacity-80">{{ $skipped['reason'] }}</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
@endif
