<?php

use App\Support\Faq;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('FAQ')] class extends Component {
    /*
     * The same questions, answered inside the app.
     *
     * ## Why there is a second page for one list
     *
     * Because the Settings navlist's FAQ item pointed at the public page, and the public page
     * sits on the marketing layout. A reader who opened Settings to ask a question was thrown
     * out of the app to read it, and then had to find their way back in.
     *
     * The public page stays exactly where it is, because a reader who has just run an
     * application from an unknown download is, by definition, not signed in yet, and it is the
     * page somebody else can be sent to. So the list is in `App\Support\Faq`, the markup is in
     * one component, and this page owns the settings chrome and nothing else. The two pages
     * cannot answer different questions, because there is only one list.
     *
     * The reading column here is the settings slot, which is narrower than the public page's.
     * That is the width every settings page gets, and the answers are written to read in it.
     */

    #[Computed]
    public function faq(): Faq
    {
        return app(Faq::class);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('FAQ') }}</flux:heading>

    <x-pages::settings.layout :heading="__('FAQ')" :subheading="__('What the app does, and what it will not do')">
        <x-faq.entries :faq="$this->faq()" />
    </x-pages::settings.layout>
</section>
