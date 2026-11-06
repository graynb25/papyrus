@props(['compact' => false, 'returnTo' => null])

@php
    $locales = \App\Support\Locale::catalogued();
    $current = app()->getLocale();
@endphp

<form
    method="POST"
    action="{{ route('locale.update') }}"
    {{ $attributes->merge(['class' => $compact ? 'px-2 py-1.5' : 'flex flex-wrap items-center gap-2']) }}
>
    @csrf

    <input type="hidden" name="return" value="{{ $returnTo ?? request()->getRequestUri() }}">

    <flux:select
        name="locale"
        :size="$compact ? 'sm' : null"
        aria-label="{{ __('Language') }}"
        :onchange="$compact ? 'this.form.submit()' : null"
    >
        @foreach ($locales as $locale)
            <option value="{{ $locale }}" @selected($locale === $current)>
                {{ \App\Support\Locale::nativeName($locale) }}
            </option>
        @endforeach
    </flux:select>

    @unless ($compact)
        <flux:button type="submit" size="sm">
            {{ __('Change language') }}
        </flux:button>
    @endunless

    <noscript>
        <flux:button type="submit" size="sm">
            {{ __('Change language') }}
        </flux:button>
    </noscript>
</form>
