<?php

use App\Backup\BackupLibrary;
use App\Jobs\RunLiveBackup;
use App\Models\LiveBackupRun;
use Flux\Flux;
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
     *
     * ## Added later, and where its story is
     *
     * Phase 7's live backup takes both halves without stopping anything, and the card carrying it
     * comments its own story. This block stays written above until the page rework retires the
     * launcher-only age, because the banner beside it is read by tests that step then rewrites to
     * the new behavior rather than deleted.
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

    /*
     * ## The live backup
     *
     * Both halves are staged and verified while everything stays up, which is why a press here
     * exists now: Suwayomi exports its own fresh backup through its GraphQL surface, the reader
     * half is a `VACUUM INTO` against the live database, and neither service is stopped for any
     * of it. The full arrangement is in {@see \App\Backup\LiveBackup}.
     *
     * ## Why a second press is refused while one is happening
     *
     * Because the runs would stomp each other's staging: every run empties `staging` beside the
     * backups folder before it starts, and two runs sweeping the same folder is how a half staged
     * file disappears from under the run that is verifying it. A queued run behind a finished one
     * is fine: it sweeps stale staging of its own. So the refusal asks the row the runs write,
     * and only the row that claims to be running and is not abandoned.
     */

    public bool $includeDownloads = false;

    public bool $dialogOpen = false;

    /**
     * Whether to keep watching after a press, in the import page's shape.
     *
     * Separate from whether a run exists, because of the gap between the two: the job is queued
     * and the row is only created once a worker picks it up. For that gap and, on a server with
     * no worker, forever, there is nothing in `live_backup_runs` to look at.
     */
    public bool $polling = false;

    /** How long a run may go without touching its row before the page says something went quiet. */
    public const STUCK_SECONDS = 300;

    public function openDialog(): void
    {
        $this->dialogOpen = true;

        $this->dispatch('open-back-up-now');
    }

    public function dialogClosed(): void
    {
        $this->dialogOpen = false;
    }

    public function start(): void
    {
        $pressing = $this->activeRun();

        if ($pressing !== null && $pressing->isRunning() && ! $this->runIsAbandoned($pressing)) {
            Flux::toast(
                text: __('A backup is already being taken. Wait for it to finish: two runs sweep the same staging folder, and the first pair cannot be verified under the second.'),
                heading: __('Already running'),
                variant: 'danger',
            );

            return;
        }

        RunLiveBackup::dispatch(
            userId: (int) auth()->id(),
            includeDownloads: $this->includeDownloads,
        );

        Flux::toast(
            text: __('Backup started. Both halves are taken without stopping anything, and the pair appears in the listing here when it is done.'),
            heading: __('Started'),
            variant: 'success',
        );

        $this->reset('includeDownloads', 'dialogOpen');

        $this->polling = true;

        $this->dispatch('close-back-up-now');
    }

    /**
     * Whether the page should keep asking the server what is happening.
     *
     * The import page's shape: false before any press, so a page a reader is only reading costs
     * nothing; true after one, until a row reaches an end. A run that is still in the queue with
     * no row yet counts as something to wait for, which is what hid the first inspect result there.
     */
    public function shouldPoll(): bool
    {
        if (! $this->polling) {
            return false;
        }

        $run = $this->activeRun();

        if ($run === null) {
            return true;
        }

        return $run->isRunning() && ! $this->runIsAbandoned($run);
    }

    /**
     * The run this reader is watching, if any.
     *
     * The latest by id rather than a filtered one, because a reader who pressed the button and
     * watched nothing happen should be shown the run that is sitting there rather than an empty
     * state implying nothing was ever pressed.
     */
    #[Computed]
    public function activeRun(): ?LiveBackupRun
    {
        return LiveBackupRun::query()->latest('id')->first();
    }

    /** @return \Illuminate\Support\Collection<int, LiveBackupRun> */
    #[Computed]
    public function liveRuns(): \Illuminate\Support\Collection
    {
        return LiveBackupRun::query()->latest('id')->limit(20)->get();
    }

    /**
     * Whether a run says it is running and has not been heard from in far too long.
     *
     * The import page's reasoning, carried whole: a worker killed at its timeout, an out of
     * memory kill, or a machine that went down all leave the row reading running with nothing
     * behind it, and there is no longer a process whose word anyone could ask. The page treats
     * silence as the end of the run rather than repeating a claim that is no longer true.
     */
    public function runIsAbandoned(LiveBackupRun $run): bool
    {
        return $run->isRunning()
            && $run->updated_at->diffInSeconds(now()) > self::STUCK_SECONDS;
    }

    /**
     * What a run is saying, in the row's own sentences.
     *
     * The notes are written full by the job for exactly this surface, so this computes less and
     * quotes more: a finished run carries its own closing count sentence, a refused one carries
     * the sentence the run itself refused by, and a running one carries its stage note.
     */
    public function runSummary(LiveBackupRun $run): string
    {
        return match (true) {
            $this->runIsAbandoned($run) => __('This run stopped without finishing. A backup takes nothing half way: its staging was swept, and the pair of the moment that survived is the one before it.'),
            $run->state === LiveBackupRun::REFUSED => (string) ($run->refusal_reason ?? __('No reason recorded.')),
            $run->state === LiveBackupRun::FAILED => (string) ($run->progress_note ?? __('No reason recorded.')),
            $run->state === LiveBackupRun::RUNNING => (string) ($run->progress_note ?? __('Starting.')),
            $run->state === LiveBackupRun::COMPLETE => (string) ($run->progress_note ?? __('The pair is in the backups folder.')),
            default => (string) $run->state,
        };
    }

    /**
     * How big the downloads folder is, so the press is made against a number.
     *
     * Asked only while the dialog is open, so a reader reading the page costs Suwayomi nothing.
     * Unknown is received here as null and said plainly: the alternative to not knowing is not
     * an invented number.
     */
    public function downloadsSize(): ?int
    {
        return $this->dialogOpen
            ? app(\App\Backup\DownloadsFolder::class)->bytes()
            : null;
    }

    /** The bytes the way this page's own list already reads sizes. */
    public function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 && $unit > 0 ? 1 : 0).' '.$units[$unit];
    }

    /** The badge colours the run states are read in, the import page's words for the same states. */
    public function badgeColour(LiveBackupRun $run): string
    {
        return match (true) {
            $this->runIsAbandoned($run) => 'orange',
            $run->state === LiveBackupRun::COMPLETE => 'green',
            $run->state === LiveBackupRun::RUNNING => 'blue',
            $run->state === LiveBackupRun::FAILED => 'red',
            $run->state === LiveBackupRun::REFUSED => 'orange',
            default => 'zinc',
        };
    }

    /** What a run's state says to a reader, one word, with silence named as an end. */
    public function stateLabel(LiveBackupRun $run): string
    {
        return match (true) {
            $this->runIsAbandoned($run) => __('Stopped'),
            $run->state === LiveBackupRun::COMPLETE => __('Finished'),
            $run->state === LiveBackupRun::RUNNING => __('Running'),
            $run->state === LiveBackupRun::FAILED => __('Stopped'),
            $run->state === LiveBackupRun::REFUSED => __('Refused'),
            default => (string) $run->state,
        };
    }
}; ?>

<section
    class="w-full"
    x-on:open-back-up-now.window="$dispatch('modal-show', { name: 'back-up-now' })"
    x-on:close-back-up-now.window="$dispatch('modal-close', { name: 'back-up-now' })"
>
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

        {{--
            The live backup, and why a button finally exists.

            The banner above is the story of the launcher's own snapshot, which stops the install
            to prove the manga half is copied with nothing writing it. The live backup does not
            make that proof; it replaces the question with a different one: Suwayomi is asked to
            export its own fresh backup while it runs, the reader half is a `VACUUM INTO` against
            the live database, and each half is verified in staging before the pair gets a manifest.
            Nothing is stopped for any of it, which is what the run rows below carry stage by
            stage, written by the job as it goes.
        --}}
        <flux:card class="mb-6 space-y-4 p-4">
            <div @if ($this->shouldPoll()) wire:poll.3s @endif>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading level="3" class="text-base">{{ __('Take one now, without stopping anything') }}</flux:heading>

                    @php($pressing = $this->activeRun())

                    @if ($pressing !== null && $pressing->isRunning() && ! $this->runIsAbandoned($pressing))
                        <flux:badge size="sm" color="blue" icon="loading">
                            {{ __('A backup is being taken') }}
                        </flux:badge>
                    @endif
                </div>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('The pair is staged and verified while the app keeps running, then lands beside the launcher backups above with the token naming the moment. The app never stops, and closing this page while it runs changes nothing.') }}
                </p>

                <div class="mt-4">
                    <flux:button
                        variant="primary"
                        icon="camera"
                        wire:click="openDialog"
                        wire:target="openDialog"
                    >
                        {{ __('Back up now') }}
                    </flux:button>
                </div>

                {{-- The runs, newest first, the import page's rows. --}}
                <div class="mt-4 space-y-3">
                    @if ($this->liveRuns()->isEmpty())
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($this->polling)
                                {{ __('Queued. The pair will appear in a moment.') }}
                            @else
                                {{ __('No backups have been taken from here yet. Every pair above was made with the launcher.') }}
                            @endif
                        </p>
                    @else
                        @foreach ($this->liveRuns() as $run)
                            <div class="border-t border-zinc-200 pt-3 first:border-0 first:pt-0 dark:border-white/10">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium">
                                        {{ $run->token !== null ? $run->token : __('being staged now') }}
                                    </span>

                                    <flux:badge size="sm" :color="$this->badgeColour($run)">
                                        {{ $this->stateLabel($run) }}
                                    </flux:badge>
                                </div>

                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $this->runSummary($run) }}
                                </p>

                                <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                    {{ __('Started :when.', ['when' => $run->started_at->diffForHumans()]) }}
                                </p>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </flux:card>

        {{--
            The press dialog.

            Opened through openDialog rather than a trigger, because the size row the reader
            presses against is asked live while the dialog shows, and a trigger would have
            nothing to say on the server side. The size is named before the press and asked
            only while the dialog is open, so a reader reading the page costs Suwayomi nothing.
        --}}
        <flux:modal name="back-up-now" class="max-w-md" @close="dialogClosed">
            <div class="flex items-start gap-4">
                <span
                    class="mt-0.5 flex size-10 flex-none items-center justify-center rounded-xl bg-vermilion-600/10 text-vermilion-600 dark:bg-vermilion-500/15 dark:text-vermilion-400"
                    aria-hidden="true"
                >
                    <flux:icon name="camera" class="size-5" />
                </span>

                <div class="min-w-0">
                    <flux:heading size="lg">
                        {{ __('What the backup stops') }}
                    </flux:heading>

                    <flux:subheading class="mt-1">
                        {{ __('Nothing. Both halves are taken while the app keeps running.') }}
                    </flux:subheading>

                    <div class="mt-4 space-y-3">
                        <flux:checkbox
                            label="{{ __('Also zip the downloads folder') }}"
                            wire:model="includeDownloads"
                        />

                        <p class="-mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            @php($size = $this->downloadsSize())

                            @if ($size !== null)
                                {{ __('Your downloads folder holds :size right now, and zipping it makes the backup take as long as it takes to read those files.', ['size' => $this->humanBytes($size)]) }}
                            @else
                                {{ __('Papyrus cannot ask Suwayomi how big the downloads folder is right now, so leave the checkbox off unless you know it is small, or wait until the app can reach its server.') }}
                            @endif
                        </p>
                    </div>

                    <flux:subheading class="mt-4">
                        {{ __('Nothing is taken until this is confirmed, and cancelling leaves everything as it is.') }}
                    </flux:subheading>
                </div>
            </div>

            <div class="mt-7 flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Never mind') }}</flux:button>
                </flux:modal.close>

                <flux:button
                    variant="primary"
                    wire:click="start"
                    wire:target="start"
                    wire:loading.attr="disabled"
                >
                    {{ __('Take the backup') }}
                </flux:button>
            </div>
        </flux:modal>
    </x-pages::settings.layout>
</section>