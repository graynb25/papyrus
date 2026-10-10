<?php

use App\Jobs\RunBackupImport;
use App\Models\ImportRun;
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
     * An upload only works when the file fits the server, and a real backup does not:
     * `upload_max_filesize` is 2M against a measured 39.36MB file. The upload field is still
     * here because a small library produces a small backup and 2M is enough for that, and
     * {@see uploadCeiling()} tells the reader which side of the line their file is on rather
     * than letting them find out by watching a request fail.
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
     * The largest file this server will accept, in bytes, or null when it will not accept any.
     *
     * `post_max_size` binds before `upload_max_filesize`, so it is the one that decides whether
     * a request arrives at all. Reading both and taking the smaller is the only answer that
     * matches what a reader experiences: the error they get is about the smaller one.
     */
    public function uploadCeiling(): ?int
    {
        $smallest = min(array_filter([$this->iniBytes('post_max_size'), $this->iniBytes('upload_max_filesize')]));

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
            'This server accepts uploads up to :size. A library backup is usually larger than that, so leave the file on the server and give its path below instead.',
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
     * An ini size such as `8M` or `2G` into bytes.
     *
     * A bare `0` means unlimited, which is why this returns 0 rather than 0 bytes: `0M` in a
     * config file is not a file too small to exist, it is no limit, and treating it as zero
     * would make the page tell a reader their upload cannot work on a server that allows it.
     */
    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));

        if ($value === '') {
            return 0;
        }

        $multiply = match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) $value * $multiply;
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
                    <x-slot name="heading">{{ __('This server runs its queue inside the request') }}</x-slot>

                    {{ __('An import of a large library takes about ten minutes of requests to other sites. With this setting the whole thing happens inside the browser request, which will very likely time out and leave you thinking it failed. It will probably still finish, but you will not be able to watch it.') }}

                    <code class="mt-2 block font-mono text-sm">QUEUE_CONNECTION=database</code>

                    {{ __('Set that in your .env, then run a worker alongside the site:') }}

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
                    <x-slot name="heading">{{ __('This import has not moved') }}</x-slot>

                    @if ($this->queueStalled())
                        {{ __('It is sitting in the queue and nothing is processing it, so the import was queued and never started.') }}
                    @else
                        {{ __('It started and then went quiet, which usually means the worker was killed part way through. Nothing after its last line was applied.') }}
                    @endif

                    {{--
                        A plain element rather than a component. There is no code component in
                        the set this app uses, and inventing one that renders in a test and
                        breaks on the next Flux release is worse than a few lines of markup.
                    --}}
                    <code class="mt-2 block font-mono text-sm">php artisan queue:work</code>

                    {{ __('Start that in a terminal on the server, then press the button again. Running an import again costs nothing for whatever it already did.') }}
                </flux:callout>
            @endif

            {{-- The form. Two routes in, because no single one works for every backup. --}}
            <flux:card class="space-y-4 p-4">
                <flux:input
                    label="{{ __('Backup file') }}"
                    type="file"
                    wire:model="backup"
                />

                <p class="-mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    @if ($this->uploadCeiling() !== null)
                        {{ __('This server accepts uploads up to :size. Most library backups are larger than that.', ['size' => $this->humanBytes($this->uploadCeiling())]) }}
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

                <flux:button
                    variant="primary"
                    icon="arrow-up-tray"
                    wire:click="start"
                    wire:target="start"
                >
                    {{ $this->inspectOnly ? __('Inspect') : __('Import') }}
                </flux:button>
            </flux:card>

            {{-- The run in progress, and the runs already done. --}}
            {{--
                    The run list, polling itself while there is something to wait for.

                    The attribute is bound rather than left on, because a page left polling at
                    three second intervals forever is a page that costs a request every three
                    seconds for as long as it stays open. `shouldPoll()` stops it the moment a
                    run finishes, and starts it the moment a button is pressed.
                --}}
                <div class="space-y-4 p-4" @if ($this->shouldPoll()) wire:poll.3s @endif>
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
                    <div class="space-y-3">
                        @foreach ($this->runs() as $run)
                            <div class="border-t border-zinc-200 pt-3 first:border-0 first:pt-0 dark:border-white/10">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium">
                                        {{ $run->file_name ?? __('unnamed file') }}
                                    </span>

                                    <flux:badge size="sm" :color="$this->badgeColour($run)">
                                        {{ $this->stateLabel($run) }}
                                    </flux:badge>
                                </div>

                                {{--
                                    The bar.

                                    Only while the series loop is what the run is doing. Before it,
                                    `library_series` is null and there is nothing for a bar to be a
                                    fraction of, so showing one would be claiming a position the
                                    page cannot know. The note below carries the run through that
                                    part, which is where the extension install happens.
                                --}}
                                @if ($run->state === ImportRun::RUNNING && $this->percent($run) !== null)
                                    @php($pct = $this->percent($run))

                                    <div
                                        class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10"
                                        role="progressbar"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                        aria-valuenow="{{ $pct }}"
                                    >
                                        <div
                                            class="h-full rounded-full bg-blue-500 transition-all"
                                            style="width: {{ $pct }}%"
                                        ></div>
                                    </div>
                                @endif

                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $this->summary($run) }}
                                </p>

                                @if ($run->started_at !== null)
                                    <p class="mt-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                        {{ $run->started_at->diffForHumans() }}
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
                </div>
        </div>
    </x-pages::settings.layout>
</section>