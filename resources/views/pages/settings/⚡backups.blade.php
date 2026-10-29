<?php

use App\Backup\BackupLibrary;
use App\Backup\BackupSummary;
use App\Jobs\RunLiveExport;
use App\Support\Duration;
use Papyrus\Launcher\BackupPair;
use App\Import\ImportRefused;
use App\Import\Tachibk\TachibkReader;
use App\Jobs\RunLiveBackup;
use App\Jobs\RunLiveRestore;
use App\Models\LiveBackupRun;
use App\Models\LiveExportRun;
use App\Models\LiveRestoreRun;
use App\Suwayomi\BackupGateway;
use App\Suwayomi\SuwayomiException;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Backups')] class extends Component {
    /*
     * A list of what is on disk, and a truthful account of the last run, from every door.
     *
     * ## Two doors take a pair from here now
     *
     * The button below it and the launcher differ in one thing: what is stopped while it happens.
     * A launcher snapshot stops Papyrus, which is the proof that the manga half was copied with
     * nothing writing it, and it restarts everything afterwards. The live backup asks Suwayomi
     * to hand over its own export while it runs and takes the reader half with `VACUUM INTO`,
     * which is a different bargain, honestly named on its own card, and the reason the old
     * refusal banner is gone rather than merely worded around: a sentence at the door saying
     * backups cannot be taken here stopped being true.
     *
     * ## Why the launcher's runs still have no progress bar on this page
     *
     * Because Papyrus is the thing that would be serving it. `wire:poll` and `EventSource` both run
     * inside this process, so a bar climbing through a launcher backup dies at the exact moment the
     * reader most wants to see it, and a bar that stopped climbing is indistinguishable from one that
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

    public function mount(): void
    {
        Gate::authorize('manage-install');
    }

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
     * ## The last time, in one place
     *
     * The launcher door and the three live doors each keep their own record, and the page's
     * "The last time" block reads the newest of the four rather than a surface per door. These
     * methods are that reading: one computed merge, the tone the block paints with, and the
     * per-row sentences and colours reused from each door's own card so the two readings of a
     * run can never drift.
     */

    /**
     * The newest event on record, from every door that writes one down.
     *
     * The launcher's record is parsed through {@see \Papyrus\Launcher\RunOutcome::startedAt()},
     * and a record whose time cannot be read back is left out rather than shown unsorted: its
     * own place carries it complete, and "sometime" in a block sorted by time would be
     * replaceable by any other entry on the next render, which is a lie by omission this block
     * does not need.
     */
    #[Computed]
    public function latestEvent(): ?array
    {
        /** @var list<array{at: CarbonImmutable, tone: string, label: string, summary: string, outcome: \Papyrus\Launcher\RunOutcome|null, row: LiveBackupRun|LiveExportRun|LiveRestoreRun|null}> $events */
        $events = [];

        $launcher = $this->backups()->lastRun();

        if ($launcher !== null) {
            try {
                $at = CarbonImmutable::parse($launcher->startedAt());
            } catch (\Throwable) {
                $at = null;
            }

            if ($at !== null) {
                $events[] = [
                    'at' => $at,
                    'tone' => $launcher->failed() || $launcher->stalled() ? 'red' : 'green',
                    'label' => $launcher->verdict(),
                    'summary' => '',
                    'outcome' => $launcher,
                    'row' => null,
                ];
            }
        }

        $liveNewest = [$this->liveRuns()->first(), $this->restoreRuns()->first(), $this->exportRuns()->first()];

        foreach ($liveNewest as $row) {
            if ($row === null) {
                continue;
            }

            $events[] = [
                'at' => $row->started_at,
                'tone' => $this->runToneOfAny($row),
                'label' => $this->runLabelOfAny($row),
                'summary' => $this->runSummaryOfAny($row),
                'outcome' => null,
                'row' => $row,
            ];
        }

        usort($events, fn (array $left, array $right): int => $right['at'] <=> $left['at']);

        return $events[0] ?? null;
    }

    /**
     * The border, background and text classes a verdict is read in, the palette the block has
     * always carried: a run that did not go through cannot be styled like one that did,
     * however it stopped.
     */
    public function verdictTone(string $tone): string
    {
        return match ($tone) {
            'red' => 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
            'green' => 'border-green-200 bg-green-50 text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-200',
            'amber' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
            'blue' => 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200',
            default => 'border-gray-200 bg-gray-50 text-gray-900 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100',
        };
    }

    /** The badge colour a row of any of the three live doors reads in, its own card's mapping. */
    public function runToneOfAny(LiveBackupRun|LiveExportRun|LiveRestoreRun $run): string
    {
        return match (true) {
            $run instanceof LiveBackupRun => $this->badgeColour($run),
            $run instanceof LiveRestoreRun => $this->restoreBadgeColour($run),
            default => $this->exportBadgeColour($run),
        };
    }

    /** The one word a row of any of the three live doors is read as, its own card's words. */
    public function runLabelOfAny(LiveBackupRun|LiveExportRun|LiveRestoreRun $run): string
    {
        return match (true) {
            $run instanceof LiveBackupRun => $this->stateLabel($run),
            $run instanceof LiveRestoreRun => $this->restoreStateLabel($run),
            default => $this->exportStateLabel($run),
        };
    }

    /** What a row of any of the three live doors is saying, in its own card's sentences. */
    public function runSummaryOfAny(LiveBackupRun|LiveExportRun|LiveRestoreRun $run): string
    {
        return match (true) {
            $run instanceof LiveBackupRun => $this->runSummary($run),
            $run instanceof LiveRestoreRun => $this->restoreRunSummary($run),
            default => $this->exportRunSummary($run),
        };
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

    /** Whether the "last run" modal is open. */
    public bool $runModalOpen = false;

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

    public function openRunModal(): void
    {
        $this->runModalOpen = true;

        $this->dispatch('open-backup-run');
    }

    public function closeRunModal(): void
    {
        $this->runModalOpen = false;
    }

    /** Whether the run shown in the modal is still going, so Escape and the close button are locked. */
    public function runModalIsRunning(): bool
    {
        $latest = $this->latestEvent();

        if ($latest === null) {
            return false;
        }

        if ($latest['outcome'] !== null) {
            $outcome = $latest['outcome'];

            return ! $outcome->finished() && ! $outcome->failed() && ! $outcome->stalled();
        }

        return $latest['row'] !== null
            && $latest['row']->isRunning()
            && ! $this->runIsAbandoned($latest['row']);
    }

    /**
     * The amount of time a run has been going, in the reader's words.
     *
     * Carbon returns fractional seconds here. A fraction says more about the render than the
     * backup, and changes every poll, so the page keeps whole elapsed seconds instead.
     */
    public function elapsedTime(CarbonInterface $startedAt): string
    {
        $seconds = (int) floor(max(0, $startedAt->diffInSeconds(now())));

        return Duration::forHumans($seconds).' '.__('so far');
    }

    /**
     * The live backup's actual work, rather than the launcher's stop-and-restart stages.
     *
     * `LiveBackup` reports these keys as it reaches them. Keeping the key alongside the reader
     * sentence means the modal can mark completed work without parsing prose, and an optional
     * downloads zip adds only the work this press asked for.
     *
     * @return list<array{stage: string, title: string, state: 'pending'|'running'|'done'|'failed'}>
     */
    public function liveBackupTimeline(LiveBackupRun $run): array
    {
        $steps = [
            ['stage' => 'version', 'title' => __('Checking the server')],
            ['stage' => 'reader_half', 'title' => __('Saving your library')],
        ];

        if ($run->include_downloads) {
            $steps[] = ['stage' => 'downloads', 'title' => __('Saving your downloads')];
        }

        array_push($steps,
            ['stage' => 'manga_half', 'title' => __('Saving your manga data')],
            ['stage' => 'manga_checked', 'title' => __('Checking your manga data')],
            ['stage' => 'manifest', 'title' => __('Checking the backup details')],
            ['stage' => 'renamed', 'title' => __('Finishing the backup')],
        );

        if ($run->state === LiveBackupRun::COMPLETE) {
            return array_map(static fn (array $step): array => [...$step, 'state' => 'done'], $steps);
        }

        $current = array_search($run->progress_stage, array_column($steps, 'stage'), true);

        return array_map(function (array $step, int $index) use ($current, $run): array {
            $state = 'pending';

            if (is_int($current) && $index < $current) {
                $state = 'done';
            }

            if (is_int($current) && $index === $current) {
                $state = $run->isRunning() && ! $this->runIsAbandoned($run)
                    ? 'running'
                    : 'failed';
            }

            return [...$step, 'state' => $state];
        }, $steps, array_keys($steps));
    }

    /** The restore checkpoints the last-run modal can show without reading prose. */
    public function liveRestoreTimeline(LiveRestoreRun $run): array
    {
        $steps = [
            ['stage' => 'starting', 'title' => __('Starting the restore')],
            ['stage' => 'reader_half', 'title' => __('Checking your library')],
            ['stage' => 'manga_checked', 'title' => __('Checking your manga data')],
            ['stage' => 'manga', 'title' => __('Restoring your manga data')],
            ['stage' => 'reader_swap', 'title' => __('Restoring your library')],
            ['stage' => 'done', 'title' => __('Finishing the restore')],
        ];

        if ($run->state === LiveRestoreRun::COMPLETE) {
            return array_map(static fn (array $step): array => [...$step, 'state' => 'done'], $steps);
        }

        $current = array_search($run->progress_stage, array_column($steps, 'stage'), true);

        return array_map(function (array $step, int $index) use ($current, $run): array {
            $state = 'pending';

            if (is_int($current) && $index < $current) {
                $state = 'done';
            }

            if (is_int($current) && $index === $current) {
                $state = $run->isRunning() && ! $this->runIsAbandoned($run)
                    ? 'running'
                    : 'failed';
            }

            return [...$step, 'state' => $state];
        }, $steps, array_keys($steps));
    }

    /** The one word the "Last run" line and the modal header read a run by. */
    public function lastRunVerdictWord(): string
    {
        $latest = $this->latestEvent();

        if ($latest === null) {
            return '';
        }

        if ($latest['outcome'] !== null) {
            $outcome = $latest['outcome'];

            if ($outcome->stalled() || $outcome->failed()) {
                return __('Failed');
            }

            if (! $outcome->finished()) {
                return __('Running');
            }

            return __('Finished');
        }

        if ($latest['row'] !== null) {
            return $this->runLabelOfAny($latest['row']);
        }

        return '';
    }

    public function start(): void
    {
        /*
         * Every action repeats the gate because a Livewire action is its own request: the
         * route middleware does not cover a crafted action call, and mount runs only on the
         * first render.
         */
        Gate::authorize('manage-install');

        $pressing = $this->activeRun();

        if ($pressing !== null && $pressing->isRunning() && ! $this->runIsAbandoned($pressing)) {
            Flux::toast(
                text: __('A backup is already running. Wait for it to finish before starting another one.'),
                heading: __('Backup in progress'),
                variant: 'danger',
            );

            return;
        }

        RunLiveBackup::dispatch(
            userId: (int) auth()->id(),
            includeDownloads: $this->includeDownloads,
        );

        Flux::toast(
            text: __('Backup started. Your library and manga data are being saved while Papyrus stays open.'),
            heading: __('Backup started'),
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
    public function runIsAbandoned(LiveBackupRun|LiveExportRun|LiveRestoreRun $run): bool
    {
        return $run->isRunning()
            && $run->updated_at->diffInSeconds(now()) > self::STUCK_SECONDS;
    }

    /**
     * Whether a refusal landed before the run reached its first checkpoint.
     *
     * The Refused badge reads the run's own REFUSED state, and `progress_stage` is the row's
     * own record of the stage it reached. A refusal with no stage behind it has no timeline to
     * draw; one that did reach a stage keeps the progress it earned.
     */
    public function runWasRefusedBeforeStarting(LiveBackupRun|LiveRestoreRun $run): bool
    {
        return ($run->state === LiveBackupRun::REFUSED || $run->state === LiveRestoreRun::REFUSED)
            && $run->progress_stage === null;
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
        $summary = match (true) {
            $this->runIsAbandoned($run) => __('This backup stopped before it finished. Nothing from the unfinished copy was kept.'),
            $run->state === LiveBackupRun::REFUSED => (string) ($run->refusal_reason ?? __('No reason recorded.')),
            $run->state === LiveBackupRun::FAILED => (string) ($run->progress_note ?? __('No reason recorded.')),
            $run->state === LiveBackupRun::RUNNING => (string) ($run->progress_note ?? __('Starting.')),
            $run->state === LiveBackupRun::COMPLETE => (string) ($run->progress_note ?? __('Your backup is ready to restore.')),
            default => (string) $run->state,
        };

        return $this->humanizeBackupSummary($summary);
    }

    /** Translate stored notes from before the reader-facing backup vocabulary existed. */
    public function humanizeBackupSummary(string $summary): string
    {
        if (str_contains($summary, 'downloaded manga half is a real backup of a library that holds nothing')) {
            return __('The manga server answered, but it has no series to save. The backup was refused and nothing was changed.');
        }

        if (preg_match('/^([\d,]+) series states and ([\d,]+) chapter states against Suwayomi (.+), taken without stopping anything$/', trim($summary), $matches) === 1) {
            $chapterWord = (int) str_replace(',', '', $matches[2]) === 1 ? __('chapter') : __('chapters');

            return __('Backup complete: :series series and :chapters :chapter_word saved while Papyrus stayed open. Manga server :version.', [
                'series' => $matches[1],
                'chapters' => $matches[2],
                'chapter_word' => $chapterWord,
                'version' => $matches[3],
            ]);
        }

        return $summary;
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

    /*
     * ## The restore side
     *
     * A pair row gains a Restore action once both halves are there and match their
     * manifest, and a press runs the way a live backup does: a queued job writing the row
     * a reader polls, both halves through their own doors, nothing stopped. The confirm
     * quotes the pair's own manifest and its own files, and never the server's own
     * counters: those count what the server's library cache carries, which is a different
     * number from what the pair holds, and the difference is the lie the confirm exists
     * not to tell.
     */

    /** The pair token a restore confirm is open on, null until one is. */
    public ?string $restoreToken = null;

    public bool $restoreDialogOpen = false;

    /**
     * Whether to keep watching after a Restore press, the live backup press's own shape.
     */
    public bool $restorePolling = false;

    public function openRestoreDialog(string $token): void
    {
        $this->restoreToken = $token;

        $this->restoreDialogOpen = true;

        $this->dispatch('open-put-back-now');
    }

    public function restoreDialogClosed(): void
    {
        $this->restoreDialogOpen = false;
    }

    /** The pair a confirm is open on, its manifest read through the one reader of that format. */
    #[Computed]
    public function restorePair(): ?BackupPair
    {
        if ($this->restoreToken === null) {
            return null;
        }

        $library = $this->backups();
        $manifest = $library->directory().DIRECTORY_SEPARATOR.'backup-'.$this->restoreToken.'.json';

        try {
            return BackupPair::read($manifest, $library->directory());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The destructive diff the confirm is pressed against: what the pair holds against
     * what the app holds now.
     *
     * Every number here names its own source: the pair's reader half from the manifest
     * both halves were written under, the pair's manga half from the file itself read
     * through the same reader the run verifies with, and the live half from the
     * app's own tables. The manga half is read only while the dialog is open, the same
     * courtesy the downloads size asks Suwayomi for, so reading this page costs nothing.
     *
     * @return array{pair: array{series: int, chapters: int}, live: array{series: int, chapters: int}, manga: array<string, int|string|null>, mangaProblem: string|null, takenAt: string, version: string}|null
     */
    #[Computed]
    public function restoreDiff(): ?array
    {
        if (! $this->restoreDialogOpen) {
            return null;
        }

        $pair = $this->restorePair();

        if ($pair === null) {
            return null;
        }

        $manga = null;
        $mangaProblem = null;

        try {
            $manga = (new TachibkReader)->read($pair->pathOf('suwayomi'))->summary();
        } catch (ImportRefused $refusal) {
            /*
             * Its message, not ours, the shape the pair listing already carries: the
             * format reader owns what a file that is not a backup is, and a second
             * sentence about it would be a second opinion.
             */
            $mangaProblem = $refusal->getMessage();
        }

        return [
            'pair' => ['series' => $pair->seriesStates, 'chapters' => $pair->chapterStates],
            'live' => [
                'series' => (int) DB::table('series_states')->count(),
                'chapters' => (int) DB::table('chapter_states')->count(),
            ],
            'manga' => $manga ?? [],
            'mangaProblem' => $mangaProblem,
            'takenAt' => $pair->takenAt,
            'version' => $pair->suwayomiVersion,
        ];
    }

    public function restore(): void
    {
        Gate::authorize('manage-install');

        $run = $this->activeRestoreRun();

        if ($run !== null && $run->isRunning() && ! $this->runIsAbandoned($run)) {
            Flux::toast(
                text: __('A restore is already going. Wait for it to finish: two runs swapping the same live database is the one thing this feature must never do.'),
                heading: __('Already running'),
                variant: 'danger',
            );

            return;
        }

        if ($this->restoreToken === null) {
            return;
        }

        RunLiveRestore::dispatch(
            userId: (int) auth()->id(),
            token: $this->restoreToken,
        );

        Flux::toast(
            text: __('Restore started. The run below will show what happens, and the backup on disk will not be changed.'),
            heading: __('Restore started'),
            variant: 'success',
        );

        $this->reset('restoreToken', 'restoreDialogOpen');

        $this->restorePolling = true;

        $this->dispatch('close-put-back-now');
    }

    /**
     * The latest restore run, the shape {@see activeRun()} reads for the backup side.
     */
    #[Computed]
    public function activeRestoreRun(): ?LiveRestoreRun
    {
        return LiveRestoreRun::query()->latest('id')->first();
    }

    /** @return \Illuminate\Support\Collection<int, LiveRestoreRun> */
    #[Computed]
    public function restoreRuns(): \Illuminate\Support\Collection
    {
        return LiveRestoreRun::query()->latest('id')->limit(20)->get();
    }

    /** The most recent completed restore for this backup, if it has been used. */
    public function lastRestoredAt(string $token): ?CarbonImmutable
    {
        $run = LiveRestoreRun::query()
            ->where('token', $token)
            ->where('state', LiveRestoreRun::COMPLETE)
            ->latest('finished_at')
            ->first();

        return $run?->finished_at?->toImmutable();
    }

    /** Whether the restore side should be asked what is happening, the same shape as shouldPoll(). */
    public function shouldPollRestore(): bool
    {
        if (! $this->restorePolling) {
            return false;
        }

        $run = $this->activeRestoreRun();

        if ($run === null) {
            return true;
        }

        return $run->isRunning() && ! $this->runIsAbandoned($run);
    }

    /**
     * What the server reports its own restore doing, while one is in flight.
     *
     * Answered only while a run row still claims to be running, so a finished run costs
     * the server nothing. Unreachable is named as unreachable rather than swallowed: the
     * row completes itself whatever the bar shows.
     *
     * @return array{unreachable: bool, status: array{state: string, mangaProgress: int, totalManga: int}|null}|null
     */
    #[Computed]
    public function restoreServerStatus(): ?array
    {
        $run = $this->activeRestoreRun();

        if ($run === null || ! $run->isRunning() || $this->runIsAbandoned($run)) {
            return null;
        }

        try {
            $status = app(BackupGateway::class)->restoreStatus();
        } catch (SuwayomiException) {
            return ['unreachable' => true, 'status' => null];
        }

        return ['unreachable' => false, 'status' => $status];
    }

    /**
     * The server's own restore states, in this app's words, with an unknown name carried
     * as the name it is rather than a meaning invented for it.
     */
    public function restoreStateWords(string $state): string
    {
        return match ($state) {
            'IDLE' => __('waiting to start'),
            'RESTORING_META' => __('reading the backup'),
            'RESTORING_CATEGORIES' => __('restoring categories'),
            'RESTORING_MANGA' => __('restoring series'),
            'RESTORING_SETTINGS' => __('restoring settings'),
            'SUCCESS' => __('finished'),
            'FAILURE' => __('failed'),
            default => $state,
        };
    }

    /**
     * What a restore run is saying, in its own sentences.
     */
    public function restoreRunSummary(LiveRestoreRun $run): string
    {
        return match (true) {
            $this->runIsAbandoned($run) => __('This restore stopped before it finished. The backup on disk was left unchanged.'),
            $run->state === LiveRestoreRun::REFUSED => (string) ($run->refusal_reason ?? __('No reason recorded.')),
            $run->state === LiveRestoreRun::FAILED => (string) ($run->progress_note ?? __('No reason recorded.')),
            $run->state === LiveRestoreRun::RUNNING => (string) ($run->progress_note ?? __('Starting.')),
            $run->state === LiveRestoreRun::COMPLETE => (string) ($run->progress_note ?? __('Your library is back.')),
            default => (string) $run->state,
        };
    }

    /** The badge colours, the backup side's own mapping in the restore's words. */
    public function restoreBadgeColour(LiveRestoreRun $run): string
    {
        return match (true) {
            $this->runIsAbandoned($run) => 'orange',
            $run->state === LiveRestoreRun::COMPLETE => 'green',
            $run->state === LiveRestoreRun::RUNNING => 'blue',
            $run->state === LiveRestoreRun::FAILED => 'red',
            $run->state === LiveRestoreRun::REFUSED => 'orange',
            default => 'zinc',
        };
    }

    /** What a restore run's state says to a reader, one word, the backup listing's words. */
    public function restoreStateLabel(LiveRestoreRun $run): string
    {
        return match (true) {
            $this->runIsAbandoned($run) => __('Stopped'),
            $run->state === LiveRestoreRun::COMPLETE => __('Finished'),
            $run->state === LiveRestoreRun::RUNNING => __('Running'),
            $run->state === LiveRestoreRun::FAILED => __('Stopped'),
            $run->state === LiveRestoreRun::REFUSED => __('Refused'),
            default => (string) $run->state,
        };
    }

    /*
     * ## The export side
     *
     * The outbound half of the round trip: Suwayomi is asked for its own fresh export while
     * it runs, and this reader's read marks and shelf memberships are merged onto the records
     * in that file, so a reader in Mihon's format receives the arrangement and not one pile
     * of unmarked series. {@see \App\Export\TachibkExport} does the reading and the
     * {@see \App\Export\TachibkMerger} keeps the merge's only forward rule at the byte level:
     * read stays read, a shelf never leaves, nothing already in the file is rewritten.
     *
     * ## Why there is no dialog before this press
     *
     * Because the backup dialog asks the one question this side has no answer to the reader's
     * taste in: the export takes no options. What is merged, and that records are appended to
     * and never rewritten, is the format's whole decision, made once in the merger and tested
     * there rather than offered as toggles that are not choices.
     */

    /** Whether to keep watching after an export press, the restore press's own shape. */
    public bool $exportPolling = false;

    public function startExport(): void
    {
        Gate::authorize('manage-install');

        $pressing = $this->activeExportRun();

        if ($pressing !== null && $pressing->isRunning() && ! $this->runIsAbandoned($pressing)) {
            Flux::toast(
                text: __('An export is already running. Wait for it to finish: two runs sweep the same staging folder, and a file staged under one is a product the other cannot verify.'),
                heading: __('Already running'),
                variant: 'danger',
            );

            return;
        }

        RunLiveExport::dispatch(userId: (int) auth()->id());

        Flux::toast(
            text: __('Export started. The file is a fresh export of the library with your read marks and shelves merged in, and the download link appears here when it is done.'),
            heading: __('Started'),
            variant: 'success',
        );

        $this->exportPolling = true;
    }

    /** Whether the export side should be asked what is happening, the restore's own shape. */
    public function shouldPollExport(): bool
    {
        if (! $this->exportPolling) {
            return false;
        }

        $run = $this->activeExportRun();

        if ($run === null) {
            return true;
        }

        return $run->isRunning() && ! $this->runIsAbandoned($run);
    }

    /** The latest export run, the shape {@see activeRun()} reads for the backup side. */
    #[Computed]
    public function activeExportRun(): ?LiveExportRun
    {
        return LiveExportRun::query()->latest('id')->first();
    }

    /** @return \Illuminate\Support\Collection<int, LiveExportRun> */
    #[Computed]
    public function exportRuns(): \Illuminate\Support\Collection
    {
        return LiveExportRun::query()->latest('id')->limit(20)->get();
    }

    /** What an export run is saying, in its own sentences. */
    public function exportRunSummary(LiveExportRun $run): string
    {
        return match (true) {
            $this->runIsAbandoned($run) => __('This run stopped without finishing. Its staging was swept, and nothing half written was left where a reader looks for a product.'),
            $run->state === LiveExportRun::REFUSED => (string) ($run->refusal_reason ?? __('No reason recorded.')),
            $run->state === LiveExportRun::FAILED => (string) ($run->progress_note ?? __('No reason recorded.')),
            $run->state === LiveExportRun::RUNNING => (string) ($run->progress_note ?? __('Starting.')),
            $run->state === LiveExportRun::COMPLETE => (string) ($run->progress_note ?? __('The file is in the exports folder.')),
            default => (string) $run->state,
        };
    }

    /** The badge colours, the backup side's own mapping in the export's words. */
    public function exportBadgeColour(LiveExportRun $run): string
    {
        return match (true) {
            $this->runIsAbandoned($run) => 'orange',
            $run->state === LiveExportRun::COMPLETE => 'green',
            $run->state === LiveExportRun::RUNNING => 'blue',
            $run->state === LiveExportRun::FAILED => 'red',
            $run->state === LiveExportRun::REFUSED => 'orange',
            default => 'zinc',
        };
    }

    /** What an export run's state says to a reader, one word, the backup listing's words. */
    public function exportStateLabel(LiveExportRun $run): string
    {
        return match (true) {
            $this->runIsAbandoned($run) => __('Stopped'),
            $run->state === LiveExportRun::COMPLETE => __('Finished'),
            $run->state === LiveExportRun::RUNNING => __('Running'),
            $run->state === LiveExportRun::FAILED => __('Stopped'),
            $run->state === LiveExportRun::REFUSED => __('Refused'),
            default => (string) $run->state,
        };
    }
}; ?>

<section
    class="w-full"
    x-on:open-back-up-now.window="$dispatch('modal-show', { name: 'back-up-now' })"
    x-on:close-back-up-now.window="$dispatch('modal-close', { name: 'back-up-now' })"
    x-on:open-put-back-now.window="$dispatch('modal-show', { name: 'put-back-now' })"
    x-on:close-put-back-now.window="$dispatch('modal-close', { name: 'put-back-now' })"
>
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Backups') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Backups')" :subheading="__('Keep a copy of your library, or put one back')">
        <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
            {{ __('These actions manage the whole install rather than one account: a backup copies the live database, a restore replaces it, and an export writes the library out. Only the install owner can take them.') }}
        </p>

        {{--
            The last time, read in one place.

            Four doors write records now: the launcher's own run files, and the three run tables
            the cards below keep. This block asks each for its newest event and shows the one
            that happened last, rather than the page growing a second "last time" surface whose
            answer can drift from this one's. A launcher run keeps its whole stage walk beneath
            its verdict; a live run keeps its rows on its own card, where its polling lives,
            rather than a second stage table this block has no way to feed.
        --}}
        <flux:heading level="3" class="mb-3 text-base">{{ __('Most recent activity') }}</flux:heading>

        @if (($latest = $this->latestEvent()) === null)
            <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Nothing has happened here yet. Take a backup, restore one, or export your library to see it here.') }}
            </p>
        @else
            <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Last run was :verdict :when.', ['verdict' => strtolower($this->lastRunVerdictWord()), 'when' => $latest['at']->diffForHumans()]) }}

                <button
                    type="button"
                    class="ml-2 font-medium text-vermilion-600 hover:text-vermilion-500 dark:text-vermilion-400 dark:hover:text-vermilion-300"
                    wire:click="openRunModal"
                    id="back-up-now-show-details"
                >
                    {{ __('Show details') }}
                </button>
            </p>
        @endif

        {{--
            The "last run" modal.

            One modal for the run the single line above names. It polls while it is open and the
            run is still going, and stops the moment the run reaches a finished state, so the
            page itself no longer carries the inline stage table that used to jump on every poll.
        --}}
        <div x-on:open-backup-run.window="$dispatch('modal-show', { name: 'backup-run' })">
            <flux:modal
                name="backup-run"
                class="max-w-lg"
                :dismissible="! $this->runModalIsRunning()"
                :escapable="! $this->runModalIsRunning()"
                :closable="! $this->runModalIsRunning()"
                @close="closeRunModal"
            >
                @if (($latest = $this->latestEvent()) !== null && $latest['outcome'] !== null)
                    @php
                        $outcome = $latest['outcome'];
                        $stages = $outcome->stages();
                        $titles = [
                            'checking_target' => __('Checking the server'),
                            'stopping' => __('Stopping Papyrus'),
                            'copying_manga_half' => __('Saving your manga data'),
                            'copying_reader_half' => __('Saving your library'),
                            'writing_manifest' => __('Checking the backup details'),
                            'restarting' => __('Starting Papyrus again'),
                        ];
                        $doneCount = 0;
                        foreach ($stages as $stage) {
                            if (in_array($stage['state'] ?? '', ['done', 'skipped'], true)) {
                                $doneCount++;
                            }
                        }
                        $totalStages = count($stages) ?: 6;
                        $pct = $totalStages > 0 ? (int) round($doneCount * 100 / $totalStages) : 0;
                        $isRunning = ! $outcome->finished() && ! $outcome->failed() && ! $outcome->stalled();
                        $isFinished = $outcome->finished() || $outcome->failed() || $outcome->stalled();
                        $verdictWord = match (true) {
                            $outcome->stalled() || $outcome->failed() => __('Failed'),
                            ! $outcome->finished() => __('Running'),
                            default => __('Finished'),
                        };
                        $dotClass = match (true) {
                            $outcome->stalled() || $outcome->failed() => 'bg-red-500',
                            $outcome->finished() => 'bg-green-500',
                            default => 'animate-pulse bg-zinc-400',
                        };
                        $nextAction = match (true) {
                            $outcome->stalled() || $outcome->failed() => __('Try again'),
                            ! $outcome->finished() => __('Show details'),
                            default => __('Done'),
                        };
                    @endphp

                    @if ($this->runModalOpen && $isRunning)
                        <div wire:poll.3s>
                    @else
                        <div>
                    @endif
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full {{ $dotClass }}" aria-hidden="true"></span>

                            <flux:heading size="lg">{{ $verdictWord }}</flux:heading>
                        </div>

                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            @if ($latest['outcome'] !== null)
                                {{ $latest['outcome']->token }}
                            @elseif ($latest['row'] !== null)
                                {{ $latest['row']->token ?? $latest['row']->file_name ?? '' }}
                            @endif
                        </p>

                        <div
                            class="mt-4 h-1 w-full overflow-hidden rounded-full bg-zinc-800"
                            role="progressbar"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="{{ $pct }}"
                            aria-label="{{ __('Backup progress') }}"
                        >
                            <div
                                class="h-full rounded-full bg-accent transition-all duration-300"
                                style="width: {{ $pct }}%"
                            ></div>
                        </div>

                        <div class="mt-1 flex justify-between text-xs text-zinc-400">
                            <span>{{ $doneCount }} of {{ $totalStages }}</span>

                            @if ($isRunning)
                                <span>{{ $this->elapsedTime($latest['at']) }}</span>
                            @endif
                        </div>

                        <ol class="mt-4 space-y-2" role="list">
                            @foreach ($stages as $stage)
                                @php
                                    $key = (string) ($stage['stage'] ?? '');
                                    $state = (string) ($stage['state'] ?? 'pending');
                                    $status = match ($state) {
                                        'done', 'skipped' => 'done',
                                        'running' => 'running',
                                        'failed' => 'failed',
                                        default => 'pending',
                                    };
                                    $title = $titles[$key] ?? str_replace('_', ' ', $key);
                                    $message = is_string($stage['message'] ?? null) ? (string) $stage['message'] : '';
                                    $attempts = (int) ($stage['attempts'] ?? 1);
                                @endphp

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
                                        <p class="text-sm font-medium">
                                            {{ $title }}
                                            @if ($attempts > 1)
                                                <span class="ml-1 text-xs font-normal text-amber-700 dark:text-amber-400">
                                                    {{ __('tried :count times', ['count' => $attempts]) }}
                                                </span>
                                            @endif
                                        </p>

                                        @if ($status === 'failed' && $message !== '')
                                            <p class="text-xs text-red-700 dark:text-red-300">{{ $message }}</p>
                                        @elseif ($status === 'skipped')
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Not needed for this backup.') }}</p>
                                        @elseif ($message !== '')
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $message }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>

                        @if ($latest['outcome']->retries() !== [])
                            <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ trans_choice(
                                    'One step was tried more than once: :stages.|:count steps were tried more than once: :stages.',
                                    count($latest['outcome']->retries()),
                                    ['stages' => implode(', ', array_map($latest['outcome']->readable(...), $latest['outcome']->retries()))],
                                ) }}
                            </p>
                        @endif

                        @if ($latest['label'] !== '')
                            <p class="mt-4 text-sm" aria-live="polite">{{ $latest['label'] }}</p>
                        @elseif ($latest['summary'] !== '')
                            <p class="mt-4 text-sm" aria-live="polite">{{ $latest['summary'] }}</p>
                        @endif

                        <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">
                            {{ __('Started :when.', ['when' => $latest['at']->diffForHumans()]) }}
                        </p>

                        <div class="mt-6 flex justify-end gap-2">
                            @if ($isFinished)
                                <flux:modal.close>
                                    <flux:button variant="primary">{{ $nextAction }}</flux:button>
                                </flux:modal.close>
                            @else
                                <flux:modal.close>
                                    <flux:button variant="filled" :disabled="true">{{ __('Close') }}</flux:button>
                                </flux:modal.close>
                            @endif
                        </div>
                    </div>
                @elseif (($latest = $this->latestEvent()) !== null && $latest['row'] !== null)
                    @php
                        $row = $latest['row'];
                        $liveLabel = $this->runLabelOfAny($row);
                        $liveSummary = $this->runSummaryOfAny($row);
                        $liveTone = $this->runToneOfAny($row);
                        $liveIsRunning = $this->runModalIsRunning();
                        $liveIsBackup = $row instanceof LiveBackupRun;
                        $liveIsRestore = $row instanceof LiveRestoreRun;
                        $liveStages = $liveIsBackup
                            ? $this->liveBackupTimeline($row)
                            : ($liveIsRestore ? $this->liveRestoreTimeline($row) : []);
                        $liveDoneCount = count(array_filter($liveStages, static fn (array $stage): bool => $stage['state'] === 'done'));
                        $liveTotalStages = count($liveStages);
                        $livePct = $liveTotalStages > 0 ? (int) round($liveDoneCount * 100 / $liveTotalStages) : 0;
                        $liveDotClass = match ($liveTone) {
                            'red' => 'bg-red-500',
                            'orange' => 'bg-orange-500',
                            'amber' => 'animate-pulse bg-amber-400',
                            'blue' => 'animate-pulse bg-blue-500',
                            'green' => 'bg-green-500',
                            default => 'bg-zinc-400',
                        };
                        $liveShowsCheckpoints = ($liveIsBackup || $liveIsRestore)
                            && ! $this->runWasRefusedBeforeStarting($row);
                    @endphp

                    @if ($this->runModalOpen && $liveIsRunning)
                        <div wire:poll.3s>
                    @else
                        <div>
                    @endif
                        <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full {{ $liveDotClass }}" aria-hidden="true"></span>

                        <flux:heading size="lg">{{ $liveLabel }}</flux:heading>
                    </div>

                    @if ($liveIsBackup || $liveIsRestore)
                        <div
                            class="mt-4 h-1 w-full overflow-hidden rounded-full bg-zinc-800"
                            role="progressbar"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="{{ $livePct }}"
                            aria-label="{{ $liveIsBackup ? __('Backup progress') : __('Restore progress') }}"
                        >
                            <div
                                class="h-full rounded-full bg-accent transition-all duration-300"
                                style="width: {{ $livePct }}%"
                            ></div>
                        </div>

                        @if ($liveShowsCheckpoints)
                            <div class="mt-1 flex justify-between text-xs text-zinc-400">
                                <span>{{ $liveDoneCount }} of {{ $liveTotalStages }}</span>

                                @if ($liveIsRunning)
                                    <span>{{ $this->elapsedTime($latest['at']) }}</span>
                                @endif
                            </div>

                            <ol class="mt-4 space-y-2" role="list">
                                @foreach ($liveStages as $stage)
                                    <li role="listitem" class="flex items-center gap-3 py-2">
                                        <span
                                            class="flex size-5 flex-none items-center justify-center rounded-full border {{ match ($stage['state']) {
                                                'done' => 'border-zinc-400 bg-zinc-200 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200',
                                                'running' => 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900',
                                                'failed' => 'border-red-500 bg-red-500 text-white',
                                                default => 'border-zinc-300 bg-transparent text-transparent dark:border-zinc-700',
                                            } }}"
                                            aria-hidden="true"
                                        >
                                            @if ($stage['state'] === 'done')
                                                &#10003;
                                            @elseif ($stage['state'] === 'failed')
                                                &#10005;
                                            @elseif ($stage['state'] === 'running')
                                                <flux:icon name="loading" class="size-3" />
                                            @endif
                                        </span>

                                        <p class="text-sm font-medium">{{ $stage['title'] }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    @endif

                    @if ($liveSummary !== '')
                        <p class="mt-4 text-sm" aria-live="polite">{{ $liveSummary }}</p>
                    @endif

                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        @if ($latest['outcome'] !== null)
                            {{ $latest['outcome']->token }}
                        @elseif ($latest['row'] !== null)
                            {{ $latest['row']->token ?? $latest['row']->file_name ?? '' }}
                        @endif
                    </p>

                    <p class="mt-2 text-xs text-zinc-400 dark:text-zinc-500">
                        {{ __('Started :when.', ['when' => $latest['at']->diffForHumans()]) }}
                    </p>

                    <div class="mt-6 flex justify-end gap-2">
                        @if ($liveIsRunning)
                            <flux:modal.close>
                                <flux:button variant="filled" :disabled="true">{{ __('Close') }}</flux:button>
                            </flux:modal.close>
                        @else
                            <flux:modal.close>
                                <flux:button variant="primary">{{ __('Done') }}</flux:button>
                            </flux:modal.close>
                        @endif
                    </div>
                    </div>
                @endif
            </flux:modal>
        </div>

        <flux:heading level="3" class="mb-3 text-base">{{ __('Saved backups') }}</flux:heading>

        @if ($this->pairs() === [])
            <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('No backups yet. Use “Back up now” below to save your library while Papyrus stays open, or run papyrus snapshot in a terminal when Papyrus is closed.') }}
            </p>
        @else
            <div class="mb-6 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Saved') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('What it contains') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Size') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Health') }}</th>
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Restore') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($this->pairs() as $pair)
                            <tr>
                                <td class="px-3 py-2">
                                    <span class="font-medium">{{ $pair->ageForHumans() }}</span>
                                    @if (($savedAt = $pair->takenAtForHumans()) !== null)
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $savedAt }}</span>
                                    @endif

                                    @if (($restoredAt = $this->lastRestoredAt($pair->token)) !== null)
                                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('Last restored :when.', ['when' => $restoredAt->diffForHumans()]) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">
                                    {{ $pair->describe() }}
                                    @if ($pair->serverVersion)
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('Manga server :version', ['version' => $pair->serverVersion]) }}
                                        </span>
                                    @endif
                                    @if ($pair->problem)
                                        <span class="mt-1 block text-xs text-red-700 dark:text-red-300">{{ $pair->problem }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $pair->sizeForHumans() }}</td>
                                <td class="px-3 py-2">
                                    @if ($pair->intact)
                                        <span class="text-green-700 dark:text-green-300">{{ __('Backup is complete') }}</span>
                                    @elseif ($pair->complete)
                                        <span class="text-amber-700 dark:text-amber-300">{{ __('One file has changed') }}</span>
                                    @else
                                        <span class="text-red-700 dark:text-red-300">{{ __('Backup is incomplete') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if ($pair->complete && $pair->intact)
                                        <flux:button
                                            size="sm"
                                            icon="arrow-path"
                                            wire:click="openRestoreDialog('{{ $pair->token }}')"
                                            wire:target="openRestoreDialog('{{ $pair->token }}')"
                                        >
                                            {{ __('Restore') }}
                                        </flux:button>
                                    @endif
                                    {{-- The Health cell beside this one names why the button is not here. --}}
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

        {{-- The folder's name, not its absolute path: the path is machine detail a reader
             does not need, and the name plus its neighbour is enough to find it. --}}
        <p class="mb-2 text-sm text-gray-700 dark:text-gray-300">
            {{ __('Papyrus keeps its backups in a folder named') }}
            <code class="font-mono">backups</code>
            {{ __('beside its database.') }}
        </p>

        <p class="mb-2 text-sm text-amber-700 dark:text-amber-300">
            {{ __('A backup pair contains your library, an encrypted AniList token and password hashes. Live session rows and the manga server\'s cached tokens are left out. Treat this folder as a secret.') }}
        </p>

        <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
            {{ __('To use one, press Restore on its row. Papyrus keeps running, and the confirmation shows what will be replaced. You can also close Papyrus and run papyrus restore in a terminal with the backup file path.') }}
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
            The live backup, the app's own door.

            The launcher's snapshot stops the install to prove the manga half is copied with nothing
            writing it, and that proof is worth having: it is the reason `papyrus snapshot` works the
            way it does. The live backup makes a different proof instead: Suwayomi is asked to
            export its own fresh backup while it runs, the reader half is a `VACUUM INTO` against
            the live database, and each half is verified in staging before the pair gets a manifest.
            Nothing is stopped for any of it, which is what the run rows below carry stage by
            stage, written by the job as it goes.
        --}}
        <flux:card class="mb-6 space-y-4 p-4">
            <div @if ($this->shouldPoll()) wire:poll.3s @endif>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading level="3" class="text-base">{{ __('Save a backup now') }}</flux:heading>

                    @php($pressing = $this->activeRun())

                    @if ($pressing !== null && $pressing->isRunning() && ! $this->runIsAbandoned($pressing))
                        <flux:badge size="sm" color="blue" icon="loading">
                            {{ __('A backup is being taken') }}
                        </flux:badge>
                    @endif
                </div>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Save your library and manga data while Papyrus stays open. The backup is checked before it appears above, and you can leave this page while it runs.') }}
                </p>

                <div class="mt-4">
                    <flux:button
                        variant="primary"
                        icon="camera"
                        wire:click="openDialog"
                        wire:target="openDialog"
                        id="back-up-now"
                    >
                        {{ __('Back up now') }}
                    </flux:button>
                </div>

                {{-- The runs, newest first, the import page's rows. --}}
                <div class="mt-4 space-y-3">
                    @if ($this->liveRuns()->isEmpty())
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($this->polling)
                                {{ __('Queued. The backup will appear in a moment.') }}
                            @else
                                {{ __('No backup has been saved from this page yet. The backups above were made another way.') }}
                            @endif
                        </p>
                    @else
                        @foreach ($this->liveRuns() as $run)
                            <div class="border-t border-zinc-200 pt-3 first:border-0 first:pt-0 dark:border-white/10">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium">
                                        {{ $run->token !== null ? $run->token : ($run->state === LiveBackupRun::RUNNING ? __('Saving now') : __('Backup attempt')) }}
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
            The restore card.

            The launcher's restore does its work on a stopped install, which is why its button
            lives in a terminal. The in-app restore takes the other bargain: both halves go back
            while the app keeps running, which makes a restore something a reader can press
            casually. So the button sits only on the rows whose pair is whole, the confirm is the
            heaviest on this page, and this card keeps the run rows and the server's own reading
            of its restore, the state vocabulary the manga server itself reports while the pair
            goes back: IDLE, RESTORING_META, RESTORING_CATEGORIES, RESTORING_MANGA,
            RESTORING_SETTINGS, then SUCCESS or FAILURE.
        --}}
        <flux:card class="mb-6 space-y-4 p-4">
            <div @if ($this->shouldPollRestore()) wire:poll.3s @endif>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading level="3" class="text-base">{{ __('Putting one back, without stopping anything') }}</flux:heading>

                    @php($restoring = $this->activeRestoreRun())

                    @if ($restoring !== null && $restoring->isRunning() && ! $this->runIsAbandoned($restoring))
                        <flux:badge size="sm" color="blue" icon="loading">
                            {{ __('A restore is running') }}
                        </flux:badge>
                    @endif
                </div>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Restore replaces your current library and manga data with the contents of this backup. Papyrus stays open while it works, nothing is sent anywhere, and leaving this page does not stop it.') }}
                </p>

                {{--
                    What the server itself says, while a run row still claims to be running.

                    A null status is not a lie the page has to cover for: on a server process that
                    has never restored, the answer is null until its first restore names itself,
                    so this reads as waiting rather than as failure. The counts are the server's
                    own vocabulary, the manga entries its file carries, which is a wider count
                    than the pair's series, so the bar is the server's reading of its work and
                    never passed off as the pair's.
                --}}
                @php($listening = $this->restoreServerStatus())

                @if ($listening !== null && ($listening['unreachable'] || $listening['status'] === null))
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        @if ($listening['unreachable'])
                            {{ __('The manga server cannot report its progress right now. The restore record below is still the source of truth.') }}
                        @else
                            {{ __('The manga server has not reported a stage yet. The restore record below will update as it works.') }}
                        @endif
                    </p>
                @elseif ($listening !== null)
                    @php($said = $listening['status'])
                    @php($held = $said['state'] === 'RESTORING_MANGA' && $said['totalManga'] > 0)
                    @php($pct = $held ? min(100, (int) round($said['mangaProgress'] * 100 / $said['totalManga'])) : null)

                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Manga server: :state', ['state' => $this->restoreStateWords($said['state'])]) }}

                        @if ($held)
                            <span class="ml-1">{{ $said['mangaProgress'] }} of {{ $said['totalManga'] }}</span>
                        @endif
                    </p>

                    @if ($pct !== null)
                        <div
                            class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10"
                            role="progressbar"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="{{ $pct }}"
                        >
                            <div class="h-full rounded-full bg-blue-500 transition-all" style="width: {{ $pct }}%"></div>
                        </div>
                    @endif
                @endif

                {{-- The runs, newest first, the backup card's rows in the restore's words. --}}
                <div class="mt-4 space-y-3">
                    @if ($this->restoreRuns()->isEmpty())
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($this->restorePolling)
                                {{ __('Queued. The restore will appear in a moment.') }}
                            @else
                                {{ __('Nothing has been restored from this page yet.') }}
                            @endif
                        </p>
                    @else
                        @foreach ($this->restoreRuns() as $run)
                            <div class="border-t border-zinc-200 pt-3 first:border-0 first:pt-0 dark:border-white/10">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium">
                                        {{ $run->token !== null ? $run->token : __('Restoring now') }}
                                    </span>

                                    <flux:badge size="sm" :color="$this->restoreBadgeColour($run)">
                                        {{ $this->restoreStateLabel($run) }}
                                    </flux:badge>
                                </div>

                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $this->restoreRunSummary($run) }}
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
            The export card.

            The outbound half of the round trip: Suwayomi is asked for its own fresh export
            while it runs, and this reader's read marks and shelf memberships are merged onto
            the records in that file, so a reader in Mihon's format receives the arrangement,
            the shelves and the read state, not one pile of unmarked series. No dialog asks
            anything before the press, because the export takes no options: what is merged,
            and that records are appended to and never rewritten, is the format's whole
            decision, made once in the merger and tested there rather than offered as
            toggles that are not choices.
        --}}
        <flux:card class="mb-6 space-y-4 p-4">
            <div @if ($this->shouldPollExport()) wire:poll.3s @endif>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading level="3" class="text-base">{{ __('Export for another reader app') }}</flux:heading>

                    @php($exporting = $this->activeExportRun())

                    @if ($exporting !== null && $exporting->isRunning() && ! $this->runIsAbandoned($exporting))
                        <flux:badge size="sm" color="blue" icon="loading">
                            {{ __('An export is running') }}
                        </flux:badge>
                    @endif
                </div>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Make a copy for Mihon or one of its forks. The .tachibk file includes your manga data, read progress and shelves. Export only adds your progress and shelves, so it never marks a read chapter unread or removes a shelf.') }}
                </p>

                <div class="mt-4">
                    <flux:button
                        variant="primary"
                        icon="arrow-down-tray"
                        wire:click="startExport"
                        wire:target="startExport"
                    >
                        {{ __('Export for another reader app') }}
                    </flux:button>
                </div>

                {{-- The runs, newest first, the backup card's rows carrying the run's receipt. --}}
                <div class="mt-4 space-y-3">
                    @if ($this->exportRuns()->isEmpty())
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($this->exportPolling)
                                {{ __('Queued. The export will appear in a moment.') }}
                            @else
                                {{ __('Nothing has been sent out from here yet.') }}
                            @endif
                        </p>
                    @else
                        @foreach ($this->exportRuns() as $run)
                            <div class="border-t border-zinc-200 pt-3 first:border-0 first:pt-0 dark:border-white/10">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium">
                                        {{ $run->file_name !== null ? $run->file_name : __('being written now') }}
                                    </span>

                                    <flux:badge size="sm" :color="$this->exportBadgeColour($run)">
                                        {{ $this->exportStateLabel($run) }}
                                    </flux:badge>
                                </div>

                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $this->exportRunSummary($run) }}
                                </p>

                                @if ($run->state === LiveExportRun::COMPLETE && $run->file_name !== null)
                                    {{--
                                        The receipt, read from the row the job wrote: the size named
                                        from the bytes it recorded, the counts from the run's own
                                        comparison, the skipped reasons from the run itself. The
                                        download is the route keyed on the run, which serves only
                                        the file that row's receipt still answers for.
                                    --}}
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ __(':matched of :planned series merged, :shelves shelves written and :marks read marks added (:bytes).', [
                                            'matched' => $run->series_matched ?? 0,
                                            'planned' => $run->series_planned ?? 0,
                                            'shelves' => $run->shelves_appended ?? 0,
                                            'marks' => $run->read_marks ?? 0,
                                            'bytes' => $this->humanBytes((int) $run->file_bytes),
                                        ]) }}
                                    </p>

                                    @if (($run->skipped ?? []) !== [])
                                        <ul class="mt-1 space-y-0.5 text-xs text-amber-700 dark:text-amber-400">
                                            @foreach ($run->skipped as $skipped)
                                                <li>{{ $skipped }}</li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <p class="mt-2">
                                        <a
                                            href="{{ route('backups.export', ['run' => $run]) }}"
                                            class="inline-flex items-center gap-1 text-sm font-medium text-vermilion-600 hover:text-vermilion-500 dark:text-vermilion-400 dark:hover:text-vermilion-300"
                                        >
                                            {{ __('Download the file') }}
                                        </a>
                                    </p>
                                @endif

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
                        {{ __('What happens while it runs') }}
                    </flux:heading>

                    <flux:subheading class="mt-1">
                        {{ __('Nothing stops. Papyrus saves your library and manga data while it stays open.') }}
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

        {{--
            The restore confirm, and the two claims it must never make.

            The heaviest confirm on this page, because the launcher's own restore asks the same
            question with a stopped install between the reader's press and the work, while this
            one asks it over what the app holds right now. Two claims it must never carry: that
            it "restarts" anything, a word for processes, and nothing here stops, and that what
            was there before is kept, because this restore replaces what the app holds with what
            the pair holds. Every number is read from the pair's own reader shape and its
            manifest, never from the server's totalManga, which counts the manga entries a
            backup file gathers, a wider set than the pair names. The manga file is read only
            while the dialog is open, the same courtesy the backup dialog's size asks Suwayomi for.
        --}}
        <flux:modal name="put-back-now" class="max-w-md" @close="restoreDialogClosed">
            <div class="flex items-start gap-4">
                <span
                    class="mt-0.5 flex size-10 flex-none items-center justify-center rounded-xl bg-vermilion-600/10 text-vermilion-600 dark:bg-vermilion-500/15 dark:text-vermilion-400"
                    aria-hidden="true"
                >
                    <flux:icon name="exclamation-triangle" class="size-5" />
                </span>

                <div class="min-w-0">
                    <flux:heading size="lg">
                        {{ __('Restore this backup?') }}
                    </flux:heading>

                    @if ($this->restoreDialogOpen && ($putting = $this->restoreDiff()) !== null)
                        <flux:subheading class="mt-1">
                            {{ __('This replaces your current library and manga data with this backup. Anything currently in your library that this backup does not contain will be removed. The backup itself is not changed.') }}
                        </flux:subheading>

                        <div class="mt-4 space-y-1.5 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                            <p class="flex justify-between gap-4">
                                <span class="font-medium">{{ __('Backup') }}</span>

                                <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $this->restoreToken }}</span>
                            </p>

                            <p class="flex justify-between gap-4">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('This backup contains') }}</span>

                                <span>{{ __(':series series and :chapters chapters', ['series' => $putting['pair']['series'], 'chapters' => $putting['pair']['chapters']]) }}</span>
                            </p>

                            <p class="flex justify-between gap-4">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Your library contains now') }}</span>

                                <span>{{ __(':series series and :chapters chapters', ['series' => $putting['live']['series'], 'chapters' => $putting['live']['chapters']]) }}</span>
                            </p>

                            @if ($putting['mangaProblem'] !== null)
                                <p class="text-red-700 dark:text-red-300">
                                    {{ __('The manga data could not be read: :words', ['words' => $putting['mangaProblem']]) }}
                                </p>
                            @elseif (isset($putting['manga']['library_series']))
                                <p class="flex justify-between gap-4">
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Manga data contains') }}</span>

                                    <span>{{ __(':series series, :chapters chapters, :read read', ['series' => $putting['manga']['library_series'], 'chapters' => $putting['manga']['chapters'], 'read' => $putting['manga']['chapters_read']]) }}</span>
                                </p>
                            @endif
                        </div>

                        <flux:subheading class="mt-4">
                            {{ __('Nothing changes until you confirm. Choose Never mind to leave your library as it is.') }}
                        </flux:subheading>

                        <div class="mt-7 flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="filled">{{ __('Never mind') }}</flux:button>
                            </flux:modal.close>

                            <flux:button
                                variant="primary"
                                wire:click="restore"
                                wire:target="restore"
                                wire:loading.attr="disabled"
                            >
                                {{ __('Restore this backup') }}
                            </flux:button>
                        </div>
                    @elseif ($this->restoreDialogOpen)
                        <flux:subheading class="mt-1">
                            {{ __('This backup cannot be read anymore because its details are missing or no longer match its files. Nothing has been changed.') }}
                        </flux:subheading>

                        <div class="mt-7 flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="filled">{{ __('Never mind') }}</flux:button>
                            </flux:modal.close>
                        </div>
                    @endif
                </div>
            </div>
        </flux:modal>
    </x-pages::settings.layout>
</section>
