<?php

use App\Support\Legal;
use App\Support\Outbound;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Privacy')] class extends Component {
    /*
     * The privacy page, answered inside the app.
     *
     * ## Why there is a second page for one document
     *
     * Because the privacy page is a document a reader may want to read twice:
     * once before they trust the download, and once later, in the place they
     * already are when the question comes up. The public page stays exactly
     * where it is, because a reader who has just run an application from an
     * unknown download is, by definition, not signed in yet, and it is the
     * page somebody else can be sent to.
     *
     * So the content is in `App\Support\Legal`, the markup is in one
     * component, and this page owns the settings chrome and nothing else. The
     * two pages cannot answer different things, because there is only one
     * document.
     *
     * The reading column here is the settings slot, which is narrower than
     * the public page's. That is the width every settings page gets, and the
     * text is written to read in it.
     */

    #[Computed]
    public function document(): Legal
    {
        return app(Legal::class);
    }

    /**
     * @return list<array{what: string, who: string}>
     */
    #[Computed]
    public function outbound(): array
    {
        return app(Outbound::class)->routes();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Privacy') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Privacy')" :subheading="__('What this application collects, and what it does not')">
        <x-legal.document
            :document="$this->document()->privacy()"
            :outbound="$this->outbound()"
        />
    </x-pages::settings.layout>
</section>
