<?php

use App\Jobs\RunBackupImport;
use App\Models\ImportRun;
use Flux\Flux;
use Illuminate\Support\Collection;
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
        return match ($run->state) {
            ImportRun::REFUSED, ImportRun::FAILED => (string) ($run->refusal_reason ?? __('No reason recorded.')),
            ImportRun::RUNNING => (string) ($run->progress_note ?? __('Starting.')),
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
        return match ($run->state) {
            ImportRun::COMPLETE => 'green',
            ImportRun::RUNNING => 'blue',
            ImportRun::FAILED => 'red',
            ImportRun::REFUSED => 'orange',
            default => 'zinc',
        };
    }

    public function stateLabel(ImportRun $run): string
    {
        return match ($run->state) {
            ImportRun::COMPLETE => __('Finished'),
            ImportRun::RUNNING => __('Running'),
            ImportRun::FAILED => __('Stopped'),
            ImportRun::REFUSED => __('Refused'),
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
                The queue warning.

                Only when a run is actually stuck, rather than always, because on a server with a
                worker running it would be a permanent banner about a problem nobody has. The
                text names the command because the reader looking at this is the one who has to
                decide how their server is run.
            --}}
            @if ($this->stuck)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <x-slot name="heading">{{ __('This import has not moved') }}</x-slot>

                    {{ __('It has sat without reporting anything for several minutes. That almost always means nothing is processing this server queue, so the import was queued and never started.') }}

                    {{--
                        A plain element rather than a component. There is no code component in
                        the set this app uses, and inventing one that renders in a test and
                        breaks on the next Flux release is worse than six lines of markup.
                    --}}
                    <code class="mt-2 block font-mono text-sm">php artisan queue:work</code>

                    {{ __('Start that in a terminal on the server, then press the button again. The stalled run imported nothing, and running again costs nothing for whatever it already did.') }}
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
            <flux:card class="space-y-4 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading level="3">{{ __('Your imports') }}</flux:heading>

                    @if ($this->activeRun()?->state === ImportRun::RUNNING)
                        <flux:badge size="sm" color="blue" icon="loading">
                            {{ __('Running') }}
                        </flux:badge>
                    @endif
                </div>

                @if ($this->runs()->isEmpty())
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('You have not imported anything yet.') }}
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
            </flux:card>
        </div>
    </x-pages::settings.layout>
</section>