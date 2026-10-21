<?php

use App\Backup\BackupLibrary;
use App\Backup\BackupSummary;
use App\Jobs\RunLiveExport;
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
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
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
    public function runIsAbandoned(LiveBackupRun|LiveExportRun|LiveRestoreRun $run): bool
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
            text: __('Restore started. The run below names each half as it lands, and the pair on disk is only ever read.'),
            heading: __('Started'),
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
            $this->runIsAbandoned($run) => __('This restore stopped without finishing. The pair on disk is untouched, because its files are only ever read.'),
            $run->state === LiveRestoreRun::REFUSED => (string) ($run->refusal_reason ?? __('No reason recorded.')),
            $run->state === LiveRestoreRun::FAILED => (string) ($run->progress_note ?? __('No reason recorded.')),
            $run->state === LiveRestoreRun::RUNNING => (string) ($run->progress_note ?? __('Starting.')),
            $run->state === LiveRestoreRun::COMPLETE => (string) ($run->progress_note ?? __('The pair is back.')),
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

    <x-pages::settings.layout :heading="__('Backups')" :subheading="__('What you can restore, and what the last one did')">
        {{--
            The last time, read in one place.

            Four doors write records now: the launcher's own run files, and the three run tables
            the cards below keep. This block asks each for its newest event and shows the one
            that happened last, rather than the page growing a second "last time" surface whose
            answer can drift from this one's. A launcher run keeps its whole stage walk beneath
            its verdict; a live run keeps its rows on its own card, where its polling lives,
            rather than a second stage table this block has no way to feed.
        --}}
        <flux:heading level="3" class="mb-3 text-base">{{ __('The last time') }}</flux:heading>

        @if (($latest = $this->latestEvent()) === null)
            <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Nothing has been recorded yet, which is the whole of what has happened: no pair taken or put back with the launcher, and no backup, restore or export run from the cards on this page.') }}
            </p>
        @else
            <div @class([
                'mb-6 rounded-lg border p-4 text-sm',
                $this->verdictTone($latest['tone']),
            ])>
                <p class="font-medium">{{ $latest['label'] }}</p>

                @if ($latest['summary'] !== '')
                    <p class="mt-1">{{ $latest['summary'] }}</p>
                @endif

                @if ($latest['outcome'] !== null && $latest['outcome']->retries() !== [])
                    <p class="mt-1">
                        {{ trans_choice(
                            'One step was tried more than once: :stages.|:count steps were tried more than once: :stages.',
                            count($latest['outcome']->retries()),
                            ['stages' => implode(', ', array_map($latest['outcome']->readable(...), $latest['outcome']->retries()))],
                        ) }}
                    </p>
                @endif

                <p class="mt-1 opacity-80">{{ __('Started :when.', ['when' => $latest['at']->diffForHumans()]) }}</p>
            </div>

            @if ($latest['outcome'] !== null)
                {{--
                    Every stage, in the order it happened.

                    Not a bar. A bar for something that either finished or did not tells a reader nothing
                    they can act on, and a bar frozen at 40% is exactly the picture this whole arrangement
                    exists to avoid. What a reader can act on is which step failed and why, and that is
                    what is written out. A live run's stages live on its own card instead, where the
                    polling that fills them is too.
                --}}
                <div class="mb-6 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                    <table class="w-full text-left text-sm">
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            @foreach ($latest['outcome']->stages() as $stage)
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
                                        {{ $latest['outcome']->readable((string) ($stage['stage'] ?? '')) }}
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
                                            {{ $latest['outcome']->readable($state) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        <flux:heading level="3" class="mb-3 text-base">{{ __('What you can restore') }}</flux:heading>

        @if ($this->pairs() === [])
            <p class="mb-6 text-sm text-gray-600 dark:text-gray-400">
                {{ __('No backups yet. Two doors take a pair from here: Back up now below, which stages and verifies it while everything keeps running, and papyrus snapshot in a terminal, which stops Papyrus to make the same pair.') }}
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
                            <th scope="col" class="px-3 py-2 font-medium">{{ __('Restore') }}</th>
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
                                    {{-- The Files cell beside this one names why the button is not here. --}}
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
            {{ __('To put one back, press the Restore button on its row above: the pair goes back while the app keeps running, and the confirm names what it replaces. The terminal way still exists: stop Papyrus and run papyrus restore with the path to its backup file, and both halves are put back together with whatever was there before kept rather than deleted.') }}
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
                    {{ __('A restore replaces what the app holds with what the pair holds. The manga half goes to Suwayomi through its own import while it keeps running, and the reader half is swapped in one brief pass on this machine. Nothing is stopped, nothing is sent anywhere, and closing this page while a restore runs changes nothing.') }}
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
                            {{ __('The server cannot be reached to say what its restore is doing. The run below owns the truth either way.') }}
                        @else
                            {{ __('The server has not said anything yet. It names each stage as it happens, and the run below carries the truth with it.') }}
                        @endif
                    </p>
                @elseif ($listening !== null)
                    @php($said = $listening['status'])
                    @php($held = $said['state'] === 'RESTORING_MANGA' && $said['totalManga'] > 0)
                    @php($pct = $held ? min(100, (int) round($said['mangaProgress'] * 100 / $said['totalManga'])) : null)

                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('The server says: :state', ['state' => $this->restoreStateWords($said['state'])]) }}

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
                                {{ __('Queued. The run below will appear in a moment.') }}
                            @else
                                {{ __('Nothing has been put back from here yet.') }}
                            @endif
                        </p>
                    @else
                        @foreach ($this->restoreRuns() as $run)
                            <div class="border-t border-zinc-200 pt-3 first:border-0 first:pt-0 dark:border-white/10">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="truncate text-sm font-medium">
                                        {{ $run->token !== null ? $run->token : __('being put back now') }}
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
                    <flux:heading level="3" class="text-base">{{ __('Sending one out, without stopping anything') }}</flux:heading>

                    @php($exporting = $this->activeExportRun())

                    @if ($exporting !== null && $exporting->isRunning() && ! $this->runIsAbandoned($exporting))
                        <flux:badge size="sm" color="blue" icon="loading">
                            {{ __('An export is running') }}
                        </flux:badge>
                    @endif
                </div>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('The file is a .tachibk, the format Mihon and its forks read: the library fresh from Suwayomi with your read marks and shelf memberships merged in. The merge only ever adds, so a chapter read anywhere stays read, and a series filed on a shelf stays filed.') }}
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
                                {{ __('Queued. The file will appear in a moment.') }}
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
                        {{ __('What goes over what is there now') }}
                    </flux:heading>

                    @if ($this->restoreDialogOpen && ($putting = $this->restoreDiff()) !== null)
                        <flux:subheading class="mt-1">
                            {{ __('The pair is put back over everything the app holds now, and whatever the app holds that this pair does not carry is gone from the reader side. The pair on disk is only ever read.') }}
                        </flux:subheading>

                        <div class="mt-4 space-y-1.5 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                            <p class="flex justify-between gap-4">
                                <span class="font-medium">{{ __('The pair') }}</span>

                                <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $this->restoreToken }}</span>
                            </p>

                            <p class="flex justify-between gap-4">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('The pair holds') }}</span>

                                <span>{{ __(':series series and :chapters chapters', ['series' => $putting['pair']['series'], 'chapters' => $putting['pair']['chapters']]) }}</span>
                            </p>

                            <p class="flex justify-between gap-4">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('The app holds now') }}</span>

                                <span>{{ __(':series series and :chapters chapters', ['series' => $putting['live']['series'], 'chapters' => $putting['live']['chapters']]) }}</span>
                            </p>

                            @if ($putting['mangaProblem'] !== null)
                                <p class="text-red-700 dark:text-red-300">
                                    {{ __('The manga half could not be read: :words', ['words' => $putting['mangaProblem']]) }}
                                </p>
                            @elseif (isset($putting['manga']['library_series']))
                                <p class="flex justify-between gap-4">
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('The manga file carries') }}</span>

                                    <span>{{ __(':series series, :chapters chapters, :read read', ['series' => $putting['manga']['library_series'], 'chapters' => $putting['manga']['chapters'], 'read' => $putting['manga']['chapters_read']]) }}</span>
                                </p>
                            @endif
                        </div>

                        <flux:subheading class="mt-4">
                            {{ __('Nothing is put back until this is confirmed, and Never mind leaves everything as it is.') }}
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
                                {{ __('Put it back') }}
                            </flux:button>
                        </div>
                    @elseif ($this->restoreDialogOpen)
                        <flux:subheading class="mt-1">
                            {{ __('The pair this confirm was opened on cannot be read anymore: its manifest is gone or disagrees with its own files. Nothing is being offered for this pair.') }}
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