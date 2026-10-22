<?php

use App\Support\Legal;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Terms of service')] class extends Component {
    /*
     * The terms of service page, answered inside the app.
     *
     * The same two seats as the privacy page and the FAQ: the content is in
     * `App\Support\Legal`, the markup is in one component, and this page
     * owns the settings chrome and nothing else.
     */

    #[Computed]
    public function document(): Legal
    {
        return app(Legal::class);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Terms of service') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Terms of service')" :subheading="__('What running this on your own machine means')">
        <x-legal.document :document="$this->document()->terms()" />
    </x-pages::settings.layout>
</section>
