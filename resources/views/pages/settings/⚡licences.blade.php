<?php

use App\Models\LicenceAcknowledgement;
use App\Support\Licences;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Licences')] class extends Component {
    /*
     * Generated rather than typed out, and honest about it.
     *
     * Every row on this page comes from the lock files the build installs from, so it cannot claim
     * coverage it does not have. A hand written list of licences is a list that is wrong the moment a
     * dependency moves, and wrong in the direction that matters: it looks like evidence of compliance
     * and is not.
     *
     * The page is built to be checked rather than to reassure. A reader who opens it is asking what
     * they have been given, and the answer has to be able to disagree with the marketing.
     */

    /**
     * The text key whose licence text is open on this render, at most one at a time.
     *
     * The key is held and the text is read in the view, so every render answers with the file as
     * it sits on disk rather than with a copy that could age inside the component. A second press
     * on the same row closes the row it opened.
     */
    public string $open = '';

    #[Computed]
    public function licences(): Licences
    {
        return app(Licences::class);
    }

    /**
     * The three groups in their read order, read once per render rather than three times over.
     */
    #[Computed]
    public function groups(): array
    {
        return [
            ['heading' => __('Bundled with this install'), 'packages' => $this->licences()->bundled()],
            ['heading' => __('PHP'), 'packages' => $this->licences()->composerPackages()],
            ['heading' => __('JavaScript'), 'packages' => $this->licences()->npmPackages()],
        ];
    }

    /**
     * This reader's standing for every row the page shows, keyed by the row's text key.
     *
     * One query for the whole page, answered by the ledger the rows are compared against.
     * With no signed in reader the map answers NONE everywhere by shape: without a reader
     * there is no acknowledgement to show, which is the honest state and not a default.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function ackStates(): array
    {
        $identities = [];

        foreach ($this->groups() as $group) {
            foreach ($group['packages'] as $package) {
                $identities[$package->textKey] = [$package->version, $package->licence];
            }
        }

        return LicenceAcknowledgement::statesFor(auth()->user(), $identities);
    }

    /**
     * Open, or close, the licence text behind one row.
     *
     * The key is resolved against the service before anything is set, so a key the page never
     * produced opens nothing: the rows' keys are not reader supplied, but they also arrive here
     * through a wire round trip and are checked on arrival for exactly that reason.
     */
    public function reveal(string $key): void
    {
        if ($this->licences()->licenceByKey($key) === null) {
            return;
        }

        $this->open = $this->open === $key ? '' : $key;
    }

    /**
     * Record that this reader has read this package as the page shows it.
     *
     * The write carries the identity the row is showing, so a later version or licence change
     * re-arms the reader instead of the old handheld firm-pill doing the work of a read.
     */
    public function acknowledge(string $key): void
    {
        $package = $this->licences()->licenceByKey($key);
        $reader = auth()->user();

        if ($package === null || $reader === null) {
            return;
        }

        LicenceAcknowledgement::markFor($reader, $key, $package->version, $package->licence);

        unset($this->ackStates);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Licences') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Licences')" :subheading="__('What this install is made of')">
        {{--
            The two numbers first, because several hundred rows with no shape is a list rather than an
            answer.
        --}}
        <dl class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Papyrus') }}</dt>
            <dd>{{ $this->licences()->papyrus() }}</dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Packages') }}</dt>
            <dd>{{ $this->licences()->totalCount() }}</dd>
        </dl>

        {{--
            One callout, only when there is something to say, and it disappears when there is not.

            `livewire/flux` reports a proprietary licence and it is the component library every page in
            this application is built from, so it is not an incidental dependency. A reader deciding
            whether to keep using something is entitled to know that in a sentence rather than by
            hunting for it in a table.
        --}}
        @if ($this->licences()->proprietaryCount() > 0)
            <flux:callout class="mt-6" variant="warning" icon="exclamation-triangle">
                <x-slot name="heading">
                    {{ trans_choice(
                        ':count package here is not open source.|:count packages here are not open source.',
                        $this->licences()->proprietaryCount(),
                    ) }}
                </x-slot>

                {{
                    __(
                        'It is marked in the list below and its licence is named beside it, so you can read it yourself. Papyrus ships it rather than because anything is wrong with it.'
                    )
                }}
            </flux:callout>
        @endif

        {{--
            Kept apart from the callout above, and quieter, on purpose.

            A package that records no licence is a gap in the record, not a statement about the package.
            Calling it closed would be a false accusation, and a page that overstates its own
            restrictions is not evidence of anything either. These are platform specific prebuilt
            binaries for darwin, freebsd and arm, so none of them ship on a Windows install either.
        --}}
        @if ($this->licences()->unrecordedCount() > 0)
            <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                {{
                    trans_choice(
                        ':count package records no licence at all, and is listed below as not recorded rather than guessed at. It is a platform specific binary for a platform this does not run on.|:count packages record no licence at all, and are listed below as not recorded rather than guessed at. They are platform specific binaries for platforms this does not run on.',
                        $this->licences()->unrecordedCount(),
                    )
                }}
            </p>
        @endif

        {{--
            Grouped so a reader looking for one thing is not reading several hundred rows to find it.
            The bundled pieces come first because they are the largest and the least likely to be in a
            lock file at all.
        --}}
        <div class="mt-8 space-y-8">
            @foreach ($this->groups() as $group)
                @if ($group['packages'] !== [])
                    <section>
                        <flux:heading size="sm" class="mb-3">
                            {{ $group['heading'] }}
                            <span class="text-zinc-500 dark:text-zinc-400">
                                ({{ count($group['packages']) }})
                            </span>
                        </flux:heading>

                        <ul class="divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                            @foreach ($group['packages'] as $package)
                                @php($ack = $this->ackStates[$package->textKey] ?? LicenceAcknowledgement::NONE)

                                <li class="py-2">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                        <span class="min-w-0">
                                            <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                                {{ $package->label() }}
                                            </span>

                                            @if ($package->isProprietary)
                                                <span class="ms-2 text-xs text-amber-700 dark:text-amber-400">
                                                    {{ __('not open source') }}
                                                </span>
                                            @endif

                                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">
                                                {{ $package->source }}
                                            </span>
                                        </span>

                                        <span class="flex shrink-0 flex-wrap items-baseline gap-x-3 gap-y-1">
                                            @if ($this->licences()->hasText($package))
                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    wire:click="reveal('{{ $package->textKey }}')"
                                                >
                                                    {{ __('Read the licence') }}
                                                </flux:button>
                                            @endif

                                            @if ($ack === LicenceAcknowledgement::ACKNOWLEDGED)
                                                <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                                    {{ __('Acknowledged') }}
                                                </span>
                                            @else
                                                @if ($ack === LicenceAcknowledgement::REARMED)
                                                    <span class="text-xs text-amber-700 dark:text-amber-400">
                                                        {{ __('changed since') }}
                                                    </span>
                                                @endif

                                                <flux:button
                                                    size="sm"
                                                    variant="ghost"
                                                    wire:click="acknowledge('{{ $package->textKey }}')"
                                                >
                                                    {{ __('I have read this licence') }}
                                                </flux:button>
                                            @endif

                                            <span class="text-zinc-500 dark:text-zinc-400">
                                                {{ $package->licence }}
                                            </span>
                                        </span>
                                    </div>

                                    @if ($this->open === $package->textKey)
                                        {{--
                                            Byte for byte from the file that ships, never a summary.
                                            A paraphrase would be a second copy of a claim whose whole
                                            value is being the first copy.
                                        --}}
                                        <pre class="mt-2 max-h-96 overflow-y-auto whitespace-pre-wrap rounded-md border border-zinc-200 bg-zinc-50 p-3 text-xs leading-relaxed text-zinc-700 dark:border-white/10 dark:bg-white/5 dark:text-zinc-300">{{ $this->licences()->textFor($package) }}</pre>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endforeach
        </div>

        <p class="mt-8 text-sm text-zinc-500 dark:text-zinc-400">
            {{
                __(
                    'Every package above is read from the lock files this build ships, so this list cannot claim something that is not here and cannot quietly miss something that is. The two bundled pieces appear in neither lock file, so their versions come from the manifests the build verifies against.'
                )
            }}
        </p>
    </x-pages::settings.layout>
</section>