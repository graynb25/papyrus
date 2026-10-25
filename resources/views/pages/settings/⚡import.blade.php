<?php

use App\Jobs\RunBackupImport;
use App\Models\ImportRun;
use App\Support\Duration;
use App\Support\UploadLimit;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Import a backup')] class extends Component
{
    use WithFileUploads;

    /**
     * A backup dropped onto the page, or null.
     *
     * Whether an upload works depends on the server receiving it. The packaged install
     * ships 256M, which takes a measured 41MB Tachimanga backup, but a development PHP
     * can be much smaller and the reader cannot see an ini from here. {@see uploadCeiling()}
     * reports the real number, and {@see uploadCeilingSentence()} says what to do with a
     * file that passes it rather than letting the reader find out from a failed request.
     */
    public ?TemporaryUploadedFile $backup = null;

    /**
     * A file already sitting on the server, for when it is too large to send.
     *
     * A path and not a file picker. There is no way to hand a browser a server-side path, and
     * the alternative of streaming the file in chunks costs more than it is worth for
     * something a reader does once.
     */
    public string $path = '';

    /**
     * Stop after this many series, for a trial run.
     *
     * Zero means all of them. Offered because the whole run is about eleven minutes of
     * upstream calls and a reader should be able to see the shape of the result before
     * spending that.
     */
    public int $limit = 0;

    public bool $installExtensions = true;

    /**
     * Read the file and report, writing nothing.
     *
     * On by default, deliberately. The first thing a reader does with an unfamiliar app is
     * point it at their real library and hope, and the answer to "what is in this file and
     * what would happen" costs seconds. Turning it off is one click.
     */
    public bool $inspectOnly = true;

    /**
     * Re-apply everything, ignoring what the ledger remembers.
     *
     * A recovery, not a normal mode. The ledger records a fingerprint of what each series
     * would be written as, which describes the file rather than what a given version of
     * this importer managed to do with it. So after a fix to the importer, a plain re-run
     * sees the same fingerprints and skips everything, and the only way to pick up what the
     * old version missed is to say so.
     *
     * Every write is forward only, so this can add and cannot take anything away.
     */
    public bool $force = false;

    /**
     * Whether to keep watching after a dispatch.
     *
     * Separate from whether a run exists, because of the gap between the two. The button
     * presses, the job is queued, and the run row is only created once a worker picks the job
     * up. For that gap and, on a server with no worker, forever, there is nothing in
     * `import_runs` to look at. Measured: pressing Inspect showed the toast and then never
     * showed the result, because the page re-rendered on click with no run to render and
     * nothing asked it to look again.
     */
    public bool $polling = false;

    /**
     * How long a job may sit before the page says nobody is running the queue.
     *
     * Short enough that a reader watching a button that did nothing gets an answer, long
     * enough that a worker busy on an eleven minute import is not accused of being dead.
     */
    public const QUEUE_WAIT_SECONDS = 30;

    /**
     * How long a run may go without touching its row before the page says something.
     *
     * Generous, because a run legitimately goes quiet. Installing 31 extensions is minutes of
     * upstream work that reports once at the start and once at the end, and a threshold that
     * fired during it would train a reader to ignore the warning that matters.
     */
    public const STUCK_SECONDS = 300;

    public function start(): void
    {
        $path = $this->resolvePath();

        if ($path === null) {
            return;
        }

        RunBackupImport::dispatch(
            userId: (int) auth()->id(),
            path: $path,
            limit: max(0, $this->limit),
            installExtensions: $this->installExtensions,
            inspectOnly: $this->inspectOnly,
            force: $this->force,
        );

        Flux::toast(
            text: $this->inspectOnly
                ? __('Reading the file. The result is below in a moment.')
                : __('Import started. This takes about ten minutes for a large library.'),
            heading: $this->inspectOnly ? __('Inspecting') : __('Started'),
            variant: 'success',
        );

        /*
         * The upload is consumed by the job, so it cannot be left on the component or the next
         * render would try to keep a file that no longer exists. The path is cleared for the
         * same reason: dispatching the same file twice by accident is a reader pressing the
         * button twice, and the second run re-reads the whole backup for nothing.
         */
        $this->reset('backup', 'path');

        $this->polling = true;
    }

    /**
     * Whether the page should keep asking the server what is happening.
     *
     * True after a dispatch until a run reaches a finished state. False once one has, which is
     * how the poll stops rather than running forever, and false before any dispatch, so a page
     * a reader is only reading costs nothing.
     */
    public function shouldPoll(): bool
    {
        if (! $this->polling) {
            return false;
        }

        $run = $this->activeRun();

        // No run yet. The job is queued and has not been picked up, which is the gap that
        // hid the inspect result, and on a server with no worker it is permanent.
        if ($run === null) {
            return true;
        }

        return $run->state === ImportRun::RUNNING;
    }

    /**
     * Whether this server runs queued work inside the request that dispatched it.
     *
     * `sync` is what a fresh Laravel install ships with, and it is fine for a check that takes
     * 361ms and catastrophic for an import of 1,277 series that takes eleven minutes: the
     * browser request hangs until something upstream gives up, and the reader sees a page that
     * stopped responding rather than a page that told them anything.
     *
     * Said plainly, before anything is started, because the alternative is finding out with a
     * real library and an hour of waiting.
     */
    public function queueIsInline(): bool
    {
        return config('queue.default') === 'sync';
    }

    /**
     * Whether an import is sitting in the queue with nothing processing it.
     *
     * The direct version of the question {@see stuck()} answers indirectly. `stuck()` needs a
     * run row to have gone quiet, and the one case that matters most has no run row at all:
     * the job was queued and no worker ever started, so nothing was ever created to observe.
     * This looks in the queue instead, and only concludes that when no run is actually going,
     * so a worker busy on a long import is never accused of being dead.
     *
     * Null when the queue is not the database one, because there is no table to read and a
     * `sync` queue has already run the job by the time this is called anyway.
     */
    public function queueStalled(): bool
    {
        if (! $this->polling) {
            return false;
        }

        /*
         * A run that is going means a worker is demonstrably working.
         *
         * Without this the page would accuse a healthy worker of being dead every time it
         * looked: an import holds a worker for eleven minutes, and throughout that the app
         * keeps queueing other work behind it, so the `jobs` table is never empty and never
         * young. The check for "this run is alive and has been heard from recently" is the
         * only honest evidence that somebody is doing the work.
         */
        $run = $this->activeRun();

        if ($run !== null && $run->state === ImportRun::RUNNING && ! $this->abandoned($run)) {
            return false;
        }

        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return false;
        }

        /*
         * An integer, not a Carbon.
         *
         * `created_at` on the `jobs` table is an integer epoch column. Passing a Carbon here
         * makes the query builder bind it as a datetime *string*, and SQLite sorts by storage
         * class, so every integer compares less than every string. The condition was therefore
         * true for every row in the table, whatever its age. Measured: a test asserting that a
         * job queued a moment ago was not yet abandoned, which the condition said was true.
         *
         * The same reason `useDatabase` reads `$job->created_at` rather than trusting a date.
         */
        return DB::table('jobs')
            ->where('created_at', '<', now()->subSeconds(self::QUEUE_WAIT_SECONDS)->getTimestamp())
            ->exists();
    }

    /**
     * Where the file the reader chose actually is.
     *
     * Returns null after showing the reader why, rather than throwing: a form that reports its
     * own mistake in place is the whole point of a form.
     */
    private function resolvePath(): ?string
    {
        if ($this->backup !== null) {
            if ($this->exceedsUploadCeiling($this->backup->getSize())) {
                Flux::toast(
                    text: $this->uploadCeilingSentence(),
                    heading: __('That file is too large to send'),
                    variant: 'danger',
                );

                return null;
            }

            $stored = $this->backup->store('imports');

            if ($stored === false) {
                Flux::toast(
                    text: $this->uploadCeilingSentence(),
                    heading: __('The file was not stored'),
                    variant: 'danger',
                );

                return null;
            }

            /*
             * An absolute path, because the importer is handed one and the job holds it for
             * eleven minutes. A relative path would depend on the working directory of whatever
             * ran the job, which is not the web root and is not the same between a queue worker
             * and `queue:work` elsewhere.
             */
            return storage_path('app/'.$stored);
        }

        $given = trim($this->path);

        if ($given === '') {
            Flux::toast(
                text: __('Choose a backup file, or give the path of one on the server.'),
                heading: __('Nothing to import'),
                variant: 'danger',
            );

            return null;
        }

        if (! is_file($given) || ! is_readable($given)) {
            Flux::toast(
                text: __('There is no readable file at :path.', ['path' => $given]),
                heading: __('Nothing to import'),
                variant: 'danger',
            );

            return null;
        }

        return realpath($given) ?: $given;
    }

    /**
     * The run this reader is watching, if any.
     *
     * The most recent run rather than a filtered one, because a reader who pressed the button
     * and watched nothing happen should be shown the run that is sitting there rather than an
     * empty state implying there was never a run.
     */
    #[Computed]
    public function activeRun(): ?ImportRun
    {
        return $this->runs()->first();
    }

    /**
     * Whether the details modal is open.
     */
    public bool $modalOpen = false;

    /**
     * The run the open modal is showing, by id.
     */
    public ?int $modalRunId = null;

    public function openRunModal(int $runId): void
    {
        $this->modalRunId = $runId;
        $this->modalOpen = true;

        $this->dispatch('open-import-run');
    }

    public function openImportRunModal(int $runId): void
    {
        $this->openRunModal($runId);
    }

    public function closeRunModal(): void
    {
        $this->modalOpen = false;
        $this->modalRunId = null;
    }

    public function closeImportRunModal(): void
    {
        $this->closeRunModal();
    }

    #[Computed]
    public function modalRun(): ?ImportRun
    {
        if ($this->modalRunId === null) {
            return null;
        }

        return ImportRun::query()->forUser(auth()->id())->find($this->modalRunId);
    }

    public function modalRunIsRunning(): bool
    {
        $run = $this->modalRun();

        if ($run === null) {
            return false;
        }

        return $run->state === ImportRun::RUNNING && ! $this->abandoned($run);
    }

    /**
     * Every run this reader has made, newest first.
     */
    #[Computed]
    public function runs(): Collection
    {
        return ImportRun::query()->forUser(auth()->id())->latest('id')->limit(20)->get();
    }

    /**
     * Whether the newest run is going and has stopped visibly moving.
     *
     * The check the Check button needed and did not have. A run dispatched to a queue nobody
     * is serving never starts, so it sits in `running` forever and a page that only watched
     * state would show a spinner that means nothing. This is the one place a reader is told
     * the truth about it, and it names the command rather than just going red.
     */
    #[Computed]
    public function stuck(): bool
    {
        $run = $this->activeRun();

        if ($run === null || $run->state !== ImportRun::RUNNING) {
            return false;
        }

        return $run->updated_at->diffInSeconds(now()) > self::STUCK_SECONDS;
    }

    /**
     * Whether a run says it is running and has not been heard from in far too long.
     *
     * Not the same question as {@see stuck()}, and deliberately broader. `RunBackupImport`
     * marks its own run `failed` when it catches a throw, but a worker killed at its timeout,
     * an out of memory kill, or a machine that went down all leave the row reading `running`
     * with nothing behind it. Those are not recoverable from inside the process, because
     * there is no longer a process. So the page treats silence as the end of the run, whatever
     * caused it, rather than repeating a claim that is no longer true.
     *
     * This is also what stops the history from accumulating rows that claim to be working
     * forever. One was already there: an import that had been dispatched and died an hour
     * before, still shown as running with the note it had written when it started.
     */
    public function abandoned(ImportRun $run): bool
    {
        return $run->state === ImportRun::RUNNING
            && $run->updated_at->diffInSeconds(now()) > self::STUCK_SECONDS;
    }

    /**
     * How long the run took, or has taken so far.
     *
     * The line used to measure to `now()` on every render, so a finished run's
     * clock kept climbing for as long as the modal stayed open. A run that
     * finished in ten minutes read twenty-six an hour later, which is exactly
     * the kind of number a reader would report as the import being slow. A
     * finished run is measured to `finished_at` instead.
     */
    public function elapsed(ImportRun $run): string
    {
        if ($run->started_at === null) {
            return Duration::forHumans(0);
        }

        return Duration::forHumans((int) $run->started_at->diffInSeconds($run->finished_at ?? now()));
    }

    /**
     * The largest file this server will accept, in bytes, or null when it will not accept any.
     *
     * `post_max_size` binds before `upload_max_filesize`, so it is the one that decides whether
     * a request arrives at all. {@see UploadLimit} takes the smaller of the two, which is the
     * only answer that matches what a reader experiences: the error they get is about the
     * smaller one. The same class feeds Livewire's temporary upload rule, so the number this
     * page prints and the number that lets the file through cannot drift apart.
     */
    public function uploadCeiling(): ?int
    {
        $smallest = UploadLimit::bytes();

        return $smallest > 0 ? $smallest : null;
    }

    private function exceedsUploadCeiling(?int $bytes): bool
    {
        $ceiling = $this->uploadCeiling();

        return $ceiling !== null && $bytes !== null && $bytes > $ceiling;
    }

    private function uploadCeilingSentence(): string
    {
        return __(
            'This server accepts uploads up to :size. A larger backup can stay on the server and be given by its path below.',
            ['size' => $this->humanBytes($this->uploadCeiling())],
        );
    }

    private function humanBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return __('unknown');
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 && $unit > 0 ? 1 : 0).' '.$units[$unit];
    }

    /**
     * How far through a run is, as a percentage, or null when it cannot be known.
     *
     * Null rather than zero when the total is not known yet, because a bar at 0% and a bar at
     * an unknown position are different claims and only one of them is true.
     */
    public function percent(ImportRun $run): ?int
    {
        $total = $run->library_series;

        if ($total === null || $total === 0) {
            return null;
        }

        return (int) min(100, round($run->series_processed / $total * 100));
    }

    /**
 * Whether a run only read the file rather than importing it.
 *
 * `library_series` is the discriminator, and it is a good one because the importer writes it
 * the moment it has read the file, before anything is resolved. So a null total means the run
 * never got past reading, which is exactly what an inspect is, and the report is sitting in
 * `progress_note` instead of in the counters.
 *
 * The alternative was a column saying which it was. This needs no migration, and it cannot
 * disagree with itself the way a second flag can.
 */
public function wasInspection(ImportRun $run): bool
    {
        return $run->state === ImportRun::COMPLETE && $run->library_series === null;
    }

    /**
     * The shape of a run's result, in the same counts the command prints.
     *
     * One method for the live run and the ones already finished, because two sets of wording
     * for the same import is how a reader ends up believing the older one is wrong.
     */
    public function summary(ImportRun $run): string
    {
        return match (true) {
            $this->abandoned($run) => __('This run stopped without finishing. Nothing after its last line was applied, and running the import again costs nothing for what it already did.'),
            $run->state === ImportRun::REFUSED, $run->state === ImportRun::FAILED => (string) ($run->refusal_reason ?? __('No reason recorded.')),
            $run->state === ImportRun::RUNNING => (string) ($run->progress_note ?? __('Starting.')),
            /*
             * An inspect, not an import.
             *
             * Falling through to the counts said "0 series imported, 0 already up to date, 0
             * with no source" for a run that had just described a 1,277 series library in
             * full. Every number was true and the sentence was worthless, because a reader who
             * pressed Inspect asked what is in the file and that answer was sitting one field
             * away, unused.
             */
            $this->wasInspection($run) => (string) ($run->progress_note ?? __('The file was read and nothing needed reporting.')),
            default => trans_choice(
                ':count series imported, :unchanged already up to date, :filed with no source.|:count series imported, :unchanged already up to date, :filed with no source.',
                $run->applied,
                [
                    'count' => number_format($run->applied),
                    'unchanged' => number_format($run->unchanged),
                    'filed' => number_format($run->filed_without_source),
                ],
            ),
        };
    }

    public function badgeColour(ImportRun $run): string
    {
        return match (true) {
            $this->abandoned($run) => 'orange',
            $run->state === ImportRun::COMPLETE => 'green',
            $run->state === ImportRun::RUNNING => 'blue',
            $run->state === ImportRun::FAILED => 'red',
            $run->state === ImportRun::REFUSED => 'orange',
            default => 'zinc',
        };
    }

    public function stateLabel(ImportRun $run): string
    {
        return match (true) {
            $this->abandoned($run) => __('Stopped'),
            $run->state === ImportRun::COMPLETE => __('Finished'),
            $run->state === ImportRun::RUNNING => __('Running'),
            $run->state === ImportRun::FAILED => __('Stopped'),
            $run->state === ImportRun::REFUSED => __('Refused'),
            default => $run->state,
        };
    }
};
?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Import settings') }}</flux:heading>

    <x-pages::settings.layout
        :heading="__('Import')"
        :subheading="__('Bring your library across from another reader app')"
    >
        <div class="my-6 w-full space-y-6">
            {{--
                The inline queue warning.

                Always shown when the queue cannot do background work, because unlike the stall
                warning this one is a standing property of the server rather than something
                that happened, and pressing the button is what triggers it.
            --}}
            @if ($this->queueIsInline())
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <x-slot name="heading">{{ __('Imports need a worker on this server') }}</x-slot>

                    {{ __('A large import can take about ten minutes because it reads from other sites. Without a worker, the browser request will probably time out and make the import look broken, even if it keeps running out of sight.') }}

                    <code class="mt-2 block font-mono text-sm">QUEUE_CONNECTION=database</code>

                    {{ __('Set this in your .env, then run a worker alongside the site:') }}

                    <code class="mt-2 block font-mono text-sm">php artisan queue:work</code>
                </flux:callout>
            @endif

            {{--
                The queue warning.

                Only when a run is actually stalled, rather than always, because on a server with
                a worker running it would be a permanent banner about a problem nobody has. The
                text names the command because the reader looking at this is the one who has to
                decide how their server is run.

                Two ways to be stuck, and they are not the same. `queueStalled` is the import
                queued with no worker, so there is no run row to show at all. `stuck` is a run
                that started and went quiet.
            --}}
            @if ($this->queueStalled() || $this->stuck)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <x-slot name="heading">{{ __('This import is waiting') }}</x-slot>

                    @if ($this->queueStalled())
                        {{ __('It is waiting in the queue because no worker has picked it up yet.') }}
                    @else
                        {{ __('It started and then stopped reporting progress. The worker may have stopped, and nothing after its last update was applied.') }}
                    @endif

                    {{--
                        A plain element rather than a component. There is no code component in
                        the set this app uses, and inventing one that renders in a test and
                        breaks on the next Flux release is worse than a few lines of markup.
                    --}}
                    <code class="mt-2 block font-mono text-sm">php artisan queue:work</code>

                    {{ __('Run that command in a terminal on the server, then press the button again. Starting the import again will not repeat the work it already finished.') }}
                </flux:callout>
            @endif

            {{--
                How to make the file, before there is a form to give it to.

                The original ask was "how to create the backup on those apps and how to
                import them into our app", and this half was owed. It was held back until
                the importer existed, on the grounds that a link to a guide for a feature
                that does not work is worse than nothing. It works now.

                Two families are read, and they do not carry the same thing. Tachimanga's
                .tmb holds reading time, so the import keeps it. The .tachibk written by
                Mihon, Tachiyomi and Suwayomi holds series and read marks but no duration
                at all, so its copy has to say reading time does not cross.

                The Tachimanga steps are its own, not remembered: Settings -> Backup &
                Restore -> Create Backup, and the file lands in Files -> On My iPhone ->
                Tachimanga -> backups.

                That last line is the reason this section earns its place. Tachimanga is an
                iOS app, and `On My iPhone` is hidden from the Files browser until it is
                revealed, so a reader who did the right thing can still be told they could
                not store a backup, and believe it.
            --}}
            <flux:callout variant="secondary" icon="question-mark-circle">
                <x-slot name="heading">{{ __('Making the backup') }}</x-slot>

                <div class="space-y-3 text-sm">
                    <p>
                        {{ __('Papyrus reads a Tachimanga backup (.tmb), or one from Mihon, Tachiyomi or Suwayomi (.tachibk).') }}
                    </p>

                    <p>
                        {{ __('In Tachimanga, open Settings, then Backup & Restore, then Create Backup.') }}
                    </p>

                    <p>
                        {{ __('The file is written to your phone, not to Papyrus. On iOS it goes to') }}

                        <code class="font-mono text-xs">Files &#8250; On My iPhone &#8250; Tachimanga &#8250; backups</code>

                        {{ __('which iOS hides from the Files app until you tap') }}

                        <code class="font-mono text-xs">...</code>

                        {{ __('at the top of a folder and choose "Show Hidden Files". Look for a file ending in .tmb.') }}
                    </p>

                    <p>
                        {{ __('A .tachibk brings your library and the chapters you have read, but that file does not include how long you read, so reading time stays behind in the app that made it. A Tachimanga .tmb does carry reading time, and Papyrus keeps it beside the reading it records here.') }}
                    </p>

                    <p>
                        {{ __('Then send that file here: pick it above, or if it is too big to upload, copy it onto the server and give its path below.') }}
                    </p>

                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        <a
                            href="https://tachimanga.app/help/guides/backups.html"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline underline-offset-2 hover:text-vermilion-600 dark:hover:text-vermilion-400"
                        >{{ __('Tachimanga\'s own backup guide') }}</a>
                    </p>
                </div>
            </flux:callout>

            {{-- The form. Two routes in, because no single one works for every backup. --}}
            <flux:card class="space-y-4 p-4">
                <flux:input
                    label="{{ __('Backup file') }}"
                    type="file"
                    wire:model="backup"
                />

                <p class="-mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    @if ($this->uploadCeiling() !== null)
                        {{ __('This server accepts uploads up to :size. A larger backup can be left on the server and given by path below.', ['size' => $this->humanBytes($this->uploadCeiling())]) }}
                    @else
                        {{ __('This server does not limit upload size.') }}
                    @endif
                </p>

                <flux:separator />

                <flux:input
                    label="{{ __('Or a file already on this server') }}"
                    wire:model="path"
                    placeholder="{{ __('e.g., /home/you/backups/library.tmb') }}"
                />

                <flux:input
                    label="{{ __('Stop after this many series, 0 for all of them') }}"
                    type="number"
                    min="0"
                    wire:model="limit"
                    placeholder="0"
                />

                <flux:checkbox
                    label="{{ __('Install the extensions this backup needs') }}"
                    wire:model="installExtensions"
                />

                <flux:checkbox
                    label="{{ __('Read the file first and report, without importing') }}"
                    wire:model="inspectOnly"
                />

                <flux:checkbox
                    label="{{ __('Re-apply everything, even if the ledger says it is already here') }}"
                    wire:model="force"
                />

                <p class="-mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Only for after a bad import. A normal import skips series it has already written, and cannot tell "already written" from "written wrongly".') }}
                </p>

                <flux:button
                    variant="primary"
                    icon="arrow-up-tray"
                    wire:click="start"
                    wire:target="start"
                >
                    {{ $this->inspectOnly ? __('Inspect') : __('Import') }}
                </flux:button>
            </flux:card>

            {{-- The runs already done, as one line each. --}}
            <div class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading level="3">{{ __('Your imports') }}</flux:heading>

                    @if ($this->activeRun()?->state === ImportRun::RUNNING && ! $this->abandoned($this->activeRun()))
                        <flux:badge size="sm" color="blue" icon="loading">
                            {{ __('Running') }}
                        </flux:badge>
                    @endif
                </div>

                @if ($this->runs()->isEmpty())
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        @if ($this->polling)
                            {{ __('Queued. The file will be read in a moment.') }}
                        @else
                            {{ __('You have not imported anything yet.') }}
                        @endif
                    </p>
                @else
                    <ul class="space-y-2">
                        @foreach ($this->runs() as $run)
                            <li class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 pt-2 first:border-0 first:pt-0 dark:border-white/10">
                                <span class="truncate text-sm font-medium">
                                    {{ $run->file_name ?? __('unnamed file') }}
                                </span>

                                <div class="flex items-center gap-2">
                                    <flux:badge size="sm" :color="$this->badgeColour($run)">
                                        {{ $this->stateLabel($run) }}
                                    </flux:badge>

                                    <button
                                        type="button"
                                        class="text-sm font-medium text-vermilion-600 hover:text-vermilion-500 dark:text-vermilion-400 dark:hover:text-vermilion-300"
                                        wire:click="openRunModal({{ $run->id }})"
                                    >
                                        {{ __('Show details') }}
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{--
                One modal per run, opened from a single line above.

                It polls while it is open and the run is running, and stops the moment the run
                reaches a finished state. The page itself no longer polls: the list carries
                nothing that changes during a run, so nothing on it moves.
            --}}
            <div x-on:open-import-run.window="$dispatch('modal-show', { name: 'import-run' })">
                <flux:modal
                    name="import-run"
                    class="max-w-lg"
                    :dismissible="! $this->modalRunIsRunning()"
                    :escapable="! $this->modalRunIsRunning()"
                    @close="closeRunModal"
                >
                    @if ($run = $this->modalRun())
                        @php
                            $note = (string) ($run->progress_note ?? '');
                            $isAbandoned = $this->abandoned($run);
                            $isFinished = in_array($run->state, [ImportRun::COMPLETE, ImportRun::FAILED, ImportRun::REFUSED], true) || $isAbandoned;
                            $isRunning = $run->state === ImportRun::RUNNING && ! $isAbandoned;

                            if ($run->library_series === null) {
                                $phaseKey = 'reading';
                            } elseif (str_starts_with(trim($note), 'extensions:')) {
                                $phaseKey = 'extensions';
                            } elseif (preg_match('/^\s*\d+\s+of\s+\d+\s+series/i', $note)) {
                                $phaseKey = 'series';
                            } elseif (str_starts_with(trim($note), 'categories:')) {
                                $phaseKey = 'categories';
                            } else {
                                $phaseKey = 'finished';
                            }

                            $phaseOrder = ['reading', 'extensions', 'series', 'categories'];
                            $phaseIndex = array_search($phaseKey, $phaseOrder, true);
                            if ($phaseIndex === false) {
                                $phaseIndex = 4;
                            }

                            $phaseTitles = [
                                'reading' => __('Reading the file'),
                                'extensions' => __('Installing what it needs'),
                                'series' => __('Bringing your series across'),
                                'categories' => __('Filing your shelves'),
                            ];

                            $statuses = [];
                            foreach ($phaseOrder as $index => $key) {
                                if ($isFinished) {
                                    if ($isAbandoned || in_array($run->state, [ImportRun::FAILED, ImportRun::REFUSED], true)) {
                                        $statuses[$key] = $index < $phaseIndex ? 'done' : ($index === $phaseIndex ? 'failed' : 'pending');
                                    } else {
                                        $statuses[$key] = 'done';
                                    }
                                } elseif ($isRunning) {
                                    $statuses[$key] = $index < $phaseIndex ? 'done' : ($index === $phaseIndex ? 'running' : 'pending');
                                } else {
                                    $statuses[$key] = 'pending';
                                }
                            }
                        @endphp

                        @if ($this->modalOpen && $isRunning)
                            <div wire:poll.3s>
                        @else
                            <div>
                        @endif
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full {{ match (true) {
                                        $isAbandoned => 'bg-red-500',
                                        $run->state === ImportRun::COMPLETE => 'bg-green-500',
                                        $run->state === ImportRun::RUNNING => 'animate-pulse bg-zinc-400',
                                        $run->state === ImportRun::FAILED => 'bg-red-500',
                                        $run->state === ImportRun::REFUSED => 'bg-amber-500',
                                        default => 'bg-zinc-400',
                                    } }}"
                                    aria-hidden="true"
                                ></span>

                                <flux:heading size="lg">
                                    {{ $this->stateLabel($run) }}
                                </flux:heading>
                            </div>

                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $run->file_name ?? __('unnamed file') }}
                                @if ($run->format !== null || $run->schema_version !== null)
                                    , {{ $run->format ?? __('unknown') }} {{ __('format') }}
                                    @if ($run->schema_version !== null)
                                        {{ __('version') }} {{ number_format($run->schema_version) }}
                                    @endif
                                @endif
                            </p>

                            @if (! $this->wasInspection($run) && $run->library_series !== null)
                                @php($pct = $this->percent($run) ?? 0)

                                <div
                                    class="mt-4 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"
                                    role="progressbar"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    aria-valuenow="{{ $pct }}"
                                    aria-label="{{ __('Import progress') }}"
                                >
                                    <div
                                        class="h-full rounded-full bg-zinc-900 transition-all duration-300 dark:bg-zinc-100"
                                        style="width: {{ $pct }}%"
                                    ></div>
                                </div>

                                <div class="mt-1 flex justify-between text-xs text-zinc-400">
                                    <span>{{ number_format($run->series_processed ?? 0) }} {{ __('of') }} {{ number_format($run->library_series) }} {{ __('series') }}</span>
                                    <span>{{ $this->elapsed($run) }}@if ($isRunning) {{ __('so far') }}@endif</span>
                                </div>
                            @endif

                            <ol class="mt-4 space-y-2" role="list">
                                @foreach (['reading', 'extensions', 'series', 'categories'] as $key)
                                    @php($status = $statuses[$key] ?? 'pending')

                                    <li role="listitem" class="flex items-center gap-3 py-2">
                                        <span
                                            class="flex size-5 flex-none items-center justify-center rounded-full border {{ match ($status) {
                                                'done' => 'border-zinc-400 bg-zinc-200 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200',
                                                'running' => 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900',
                                                'failed' => 'border-red-500 bg-red-500 text-white',
                                                default => 'border-zinc-300 bg-transparent text-transparent dark:border-zinc-700',
                                            } }}"
                                            aria-hidden="true"
                                        >
                                            @if ($status === 'done')
                                                &#10003;
                                            @elseif ($status === 'failed')
                                                &#10005;
                                            @elseif ($status === 'running')
                                                <flux:icon name="loading" class="size-3" />
                                            @endif
                                        </span>

                                        <div class="min-w-0">
                                            <p class="text-sm font-medium">{{ $phaseTitles[$key] }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>

                            <p
                                class="mt-4 text-sm"
                                aria-live="polite"
                            >
                                {{ $this->summary($run) }}
                            </p>

                            <div class="mt-6 flex justify-end gap-2">
                                @if ($isFinished)
                                    @if ($run->state === ImportRun::COMPLETE)
                                        <flux:modal.close>
                                            <flux:button variant="primary">{{ __('Done') }}</flux:button>
                                        </flux:modal.close>
                                    @elseif ($run->state === ImportRun::REFUSED)
                                        <flux:modal.close>
                                            <flux:button variant="primary">{{ __('Try again') }}</flux:button>
                                        </flux:modal.close>
                                    @else
                                        <flux:modal.close>
                                            <flux:button variant="primary">{{ __('Show details') }}</flux:button>
                                        </flux:modal.close>
                                    @endif
                                @else
                                    <flux:modal.close>
                                        <flux:button variant="filled" :disabled="true">{{ __('Close') }}</flux:button>
                                    </flux:modal.close>
                                @endif
                            </div>
                        </div>
                    @endif
                </flux:modal>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
