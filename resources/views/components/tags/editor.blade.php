@props([
    'tags' => [],
    'max' => 20,
    'maxLength' => 40,
])

@php
    use App\Support\ReadingState;

    $cap = is_int($max) ? $max : ReadingState::TAG_MAX_COUNT;
    $limit = is_int($maxLength) ? $maxLength : ReadingState::TAG_MAX_LENGTH;
    $tags = is_array($tags) ? array_values($tags) : [];
    $remaining = max(0, $cap - count($tags));

    /*
     * `Js::from()` rather than an inline `{{ }}`, because a tag is a reader's own text and will
     * sooner or later contain a quote. Interpolated raw it lands in the attribute as markup, and
     * the failure is a page that renders wrong rather than an error anyone would trace back
     * here. This is the pattern the health page already uses for its site names.
     */
    $removeAction = fn (string $tag): string => 'removeTag('.Illuminate\Support\Js::from($tag)->toHtml().')';
@endphp

{{--
    Tags are the reader's own labels and nothing more.

    No sharing, no upstream write: the rating and these tags are the two fields AniList also
    has and this app deliberately does not send. That is stated in the hint rather than left
    for the reader to discover by noticing their AniList account did not update, because "my
    score did not show up in AniList" is a question that should never have to be asked.
--}}
<div class="space-y-2">
    @if ($tags !== [])
        <ul class="flex flex-wrap items-center gap-1.5">
            @foreach ($tags as $tag)
                <li>
                    <span class="inline-flex items-center gap-0.5 rounded-full border border-zinc-300 bg-white py-0.5 pr-0.5 pl-2.5 text-xs text-zinc-700 dark:border-white/10 dark:bg-white/[0.03] dark:text-zinc-200">
                        <span class="max-w-40 truncate">{{ $tag }}</span>

                        <button
                            type="button"
                            wire:click="{{ $removeAction($tag) }}"
                            wire:loading.attr="disabled"
                            class="rounded-full p-0.5 text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-700 focus-visible:ring-2 focus-visible:ring-vermilion-500 focus-visible:outline-none dark:hover:bg-white/10 dark:hover:text-zinc-100"
                            {{-- The tag's own name in the label. Five buttons reading "Remove"
                                 are five buttons a screen reader announces identically, and the
                                 only thing telling them apart is text that is not in the name. --}}
                            aria-label="{{ __('Remove the tag :tag', ['tag' => $tag]) }}"
                        >
                            <flux:icon name="x-mark" class="size-3.5" />
                        </button>
                    </span>
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('No tags yet.') }}
        </p>
    @endif

    <form wire:submit="addTag" class="flex items-center gap-2">
        <label for="series-tags" class="sr-only">{{ __('Add a tag') }}</label>

        <input
            id="series-tags"
            type="text"
            wire:model="tagDraft"
            maxlength="{{ $limit }}"
            placeholder="{{ __('Add a tag') }}"
            @disabled($remaining === 0)
            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-vermilion-500 focus:ring-2 focus:ring-vermilion-500/30 focus:outline-none disabled:opacity-50 dark:border-white/10 dark:bg-zinc-950 dark:text-zinc-100"
        />

        {{-- A real form rather than a div with a keydown listener, so Enter submits from any
             browser and from a phone's soft keyboard without a second code path. Livewire's
             `wire:submit` intercepts the submit itself, which is what stops the form doing a
             real GET and reloading the page under the reader. --}}
        <flux:button
            type="submit"
            size="sm"
            variant="ghost"
            wire:loading.attr="disabled"
        >
            {{ __('Add') }}
        </flux:button>
    </form>

    <p class="text-xs text-zinc-500 dark:text-zinc-400">
        @if ($remaining === 0)
            {{ trans_choice('That is the :count tag this series may carry. Remove one to add another.|That is the :count tags this series may carry. Remove one to add another.', $cap, ['count' => $cap]) }}
        @else
            {{ __('Your own labels for this series. Nothing is shared and nothing is sent to AniList.') }}
        @endif
    </p>
</div>