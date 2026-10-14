<?php

use App\Backup\BackupLibrary;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Backups')] class extends Component {
    /*
     * A list of what is on disk, and a truthful account of the last run.
     *
     * ## Why there is no button on this page that says "Back up now"
     *
     * Because a backup cannot be taken from inside the application. The manga half has to be copied
     * with nothing writing it, and proving that means Suwayomi has to be stopped, which means the
     * application has to be stopped, which means the request that asked for the backup is gone.
     *
     * A button here would have to either lie about being able to do that, or work and surprise the
     * reader by taking their app down. Both are worse than a sentence saying what to type, and the
     * sentence is one line long.
     *
     * ## Why there is no progress bar either
     *
     * Because Papyrus is the thing that would be serving it. `wire:poll` and `EventSource` both run
     * inside this process, so a bar climbing through a backup dies at the exact moment the reader
     * most wants to see it, and a bar that stopped climbing is indistinguishable from one that
     * finished. That is the whole of "what a user does not know, they will think worked".
     *
     * So the launcher prints each stage to the terminal as it happens, and writes each one down as it
     * goes. This page reads the result back when the application returns, which is the only window
     * where there is a server to draw anything on.
     *
     * ## Why it reads the launcher's own record
     *
     * `RunRecord` and `BackupPair` are autoloadable from here, so there is one implementation of each
     * format rather than a second one to drift. See `RunOutcome`.
     */

    #[Computed]
    public function backups(): BackupLibrary
    {
        return app(BackupLibrary::class);
    }

    /** @return list<\App\Backup\BackupSummary> */
    #[Computed]
    public function pairs(): array
    {
        return $this->backups()->pairs();
    }

    /** @return list<array{name: string, bytes: int}> */
    #[Computed]
    public function leftovers(): array
    {
        return $this->backups()->leftovers();
    }

    public function directory(): string
    {
        return $this->backups()->directory();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Backups') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Backups')" :subheading="__('What you can restore, and what the last one did')">
        {{--
            Why this page cannot start a backup.

            Said once, at the top, in the place a button would otherwise be. A reader who came here to
            make one and finds only an explanation has been told the truth and given the thing that
            works. A reader who finds a button that takes their app down without saying so has been
            treated as someone who will not notice.
        --}}
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            <p class="font-medium">{{ __('Taking a backup stops Papyrus, so it cannot be done from inside Papyrus.') }}</p>
            <p class="mt-2">
                {{ __('Both databases have to be copied at the same moment, and the manga one cannot be copied safely while Suwayomi is running. To take one, open a terminal and run:') }}
            </p>
            <p class="mt-2 font-mono text-sm">papyrus snapshot</p>
            <p class="mt-2">
                {{ __('It prints each step as it happens, and starts Papyrus again afterwards even if something went wrong.') }}
            </p>
        </div>

        @php($run = $this->backups()->lastRun())

        <flux:heading level="3" class="mb-3 text-base">{{ __('The last time') }}</flux:heading>

        @if ($run)
            <div @class([
                'mb-6 rounded-lg border p-4 text-sm',
                'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200' => $run->failed() || $run->stalled(),
                'border-green-200 bg-green-50 text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-200' => ! $run->failed() && ! $run->stalled(),
            ])>
                <p class="font-medium">{{ $run->verdict() }}</p>

                @if ($run->retries() !== [])
                    <p class="mt-1">
                        {{ trans_choice(
                            'One step was tried more than once: :stages.|:count steps were tried more than once: :stages.',
                            count($run->retries()),
                            ['stages' => implode(', ', array_map($run->readable(...), $run->retries()))],
                        ) }}
                    </p>
                @endif

                <p class="mt-1 opacity-80">{{ __('Started :when.', ['when' => $run->ageForHumans()]) }}</p>
            </div>

            {{--
                Every stage, in the order it happened.

                Not a bar. A bar for something that either finished or did not tells a reader nothing
                they can act on, and a bar frozen at 40% is exactly the picture this whole arrangement
                exists to avoid. What a reader can act on is which step failed and why, and that is
                what is written out.
            --}}
            <div class="mb-6 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($run->stages() as $stage)
                            @php($state = $stage['state'] ?? 'pending')
                            <tr>
                                <td class="w-8 px-3 py-2 text-center" aria-hidden="true">
                                    <span @class([
                                        'inline-block size-2 rounded-full',
                                        'bg-green-500' => $state === 'done',
                                        'bg-amber-500' => $state === 'running',
                                        'bg-red-500' => $state === 'failed',
                                        'bg-gray-300 dark:bg-gray-700' => ! in_array($state, ['done', 'running', 'failed'], true),
                                    ])></span>
                                </td>
                                <td class="px-3 py-2 font-medium">
                                    {{ $run->readable((string) ($stage['stage'] ?? '')) }}
                                    @if (($stage['attempts'] ?? 1) > 1)
                                        <span class="ml-1 text-xs font-normal text-amber-700 dark:text-amber-400">
                                            {{ __('tried :count times', ['count' => $stage['attempts']]) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-400">
                                    @if ($state === 'failed')
                                        <span class="text-red-700 dark:text-red-300">{{ $stage['message'] }}</span>
                                    @elseif ($state === 'skipped')
                                        {{ __('Not needed for this run.') }}
                                    @elseif (is_string($stage['message'] ?? null) && $stage['message'] !== '')
                                        {{ $stage['message'] }}
                                    @else
                                        {{ $run->readable($state) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Nothing has been recorded yet, which is the whole of what has happened: no backup has been taken since Papyrus was installed.') }}
            </p>
        @endif

        <flux:heading level="3" class="mb-3 text-base">{{ __('What you can restore') }}</flux:heading>

        @if ($this->pairs() === [])
            <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('No backups yet. Run papyrus snapshot and one will appear here.') }}
            </p>
        @else
            <div class="mb-6 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Taken') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('What it holds') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Size') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Files') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($this->pairs() as $pair)
                            <tr>
                                <td class="px-3 py-2">
                                    <span class="font-medium">{{ $pair->ageForHumans() }}</span>
                                    <span class="block font-mono text-xs text-gray-500 dark:text-gray-400">{{ $pair->token }}</span>
                                </td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">
                                    {{ $pair->describe() }}
                                    @if ($pair->serverVersion)
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('Suwayomi :version', ['version' => $pair->serverVersion]) }}
                                        </span>
                                    @endif
                                    @if ($pair->problem)
                                        <span class="mt-1 block text-xs text-red-700 dark:text-red-300">{{ $pair->problem }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $pair->sizeForHumans() }}</td>
                                <td class="px-3 py-2">
                                    @if ($pair->intact)
                                        <span class="text-green-700 dark:text-green-300">{{ __('Both halves match') }}</span>
                                    @elseif ($pair->complete)
                                        <span class="text-amber-700 dark:text-amber-300">{{ __('One does not match') }}</span>
                                    @else
                                        <span class="text-red-700 dark:text-red-300">{{ __('Incomplete') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{--
            Where they are, and what a restore looks like.

            Named because a reader who wants to restore something will otherwise go looking, and
            because a backup on a disk the reader cannot see is a backup they do not have.
        --}}
        <flux:heading level="3" class="mb-3 text-base">{{ __('Where they are') }}</flux:heading>

        <p class="mb-2 font-mono text-sm break-all text-gray-700 dark:text-gray-300">{{ $this->directory() }}</p>

        <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
            {{ __('To put one back, stop Papyrus and run papyrus restore with the path to its backup file. Both halves are put back together, and whatever was there before is kept rather than deleted.') }}
        </p>

        @if ($this->leftovers() !== [])
            <flux:heading level="3" class="mb-3 text-base">{{ __('What a restore kept') }}</flux:heading>

            <ul class="mb-6 space-y-1 text-sm text-gray-600 dark:text-gray-400">
                @foreach ($this->leftovers() as $leftover)
                    <li>
                        <span class="font-mono">{{ $leftover['name'] }}</span>
                        <span class="text-xs">({{ number_format($leftover['bytes'] / 1048576, 1) }} MB)</span>
                        <span>{{ __('the files that were there before a restore replaced them') }}</span>
                    </li>
                @endforeach
            </ul>

            <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('These are safe to delete once you are sure the restore was the one you wanted.') }}
            </p>
        @endif
    </x-pages::settings.layout>
</section>