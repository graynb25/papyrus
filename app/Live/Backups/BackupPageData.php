<?php

namespace App\Live\Backups;

use App\Backup\BackupLibrary;
use App\Backup\BackupSummary;
use App\Backup\DownloadsFolder;
use App\Backup\RunOutcome;
use App\Import\ImportRefused;
use App\Import\Tachibk\TachibkReader;
use App\Live\Contracts\LiveRun;
use App\Live\RunPresenter;
use App\Models\LiveBackupRun;
use App\Models\LiveExportRun;
use App\Models\LiveRestoreRun;
use App\Suwayomi\BackupGateway;
use App\Suwayomi\SuwayomiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Papyrus\Launcher\BackupPair;

/**
 * The backups page's reads, kept out of the Livewire component.
 *
 * The page performs these on every render and on a few dialog actions. Keeping them
 * here lets the query shapes and the pair and diff reads be exercised without mounting
 * a component, and leaves the component owning lifecycle, actions and dialog state.
 *
 * The reads keep the page's own order and limits: newest first by id, twenty rows per
 * live card, and the newest of the four doors in one event for the block that names
 * the last thing that happened.
 */
final class BackupPageData
{
    public function __construct(
        private readonly BackupLibrary $library,
        private readonly BackupGateway $gateway,
        private readonly DownloadsFolder $downloads,
        private readonly RunPresenter $presenter,
    ) {}

    public function backups(): BackupLibrary
    {
        return $this->library;
    }

    /** @return list<BackupSummary> */
    public function pairs(): array
    {
        return $this->backups()->pairs();
    }

    /** @return list<array{name: string, bytes: int}> */
    public function leftovers(): array
    {
        return $this->backups()->leftovers();
    }

    public function directory(): string
    {
        return $this->backups()->directory();
    }

    /**
     * The newest event on record, from every door that writes one down.
     *
     * The launcher's record is parsed through {@see RunOutcome::startedAt()}, and a
     * record whose time cannot be read back is left out rather than shown unsorted: its
     * own place carries it complete, and "sometime" in a block sorted by time would be
     * replaceable by any other entry on the next render, which is a lie by omission this
     * block does not need.
     *
     * @return array{at: CarbonImmutable, tone: string, label: string, summary: string, outcome: RunOutcome|null, row: LiveRun|null}|null
     */
    public function latestEvent(): ?array
    {
        /** @var list<array{at: CarbonImmutable, tone: string, label: string, summary: string, outcome: RunOutcome|null, row: LiveRun|null}> $events */
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
                'tone' => $this->presenter->badgeColour($row),
                'label' => $this->presenter->stateLabel($row),
                'summary' => $this->presenter->summary($row),
                'outcome' => null,
                'row' => $row,
            ];
        }

        usort($events, fn (array $left, array $right): int => $right['at'] <=> $left['at']);

        return $events[0] ?? null;
    }

    /**
     * The run this reader is watching, if any.
     *
     * The latest by id rather than a filtered one, because a reader who pressed the
     * button and watched nothing happen should be shown the run that is sitting there
     * rather than an empty state implying nothing was ever pressed.
     */
    public function activeRun(): ?LiveBackupRun
    {
        return LiveBackupRun::query()->latest('id')->first();
    }

    /** @return Collection<int, LiveBackupRun> */
    public function liveRuns(): Collection
    {
        return LiveBackupRun::query()->latest('id')->limit(20)->get();
    }

    /** The latest restore run, the shape {@see activeRun()} reads for the backup side. */
    public function activeRestoreRun(): ?LiveRestoreRun
    {
        return LiveRestoreRun::query()->latest('id')->first();
    }

    /** @return Collection<int, LiveRestoreRun> */
    public function restoreRuns(): Collection
    {
        return LiveRestoreRun::query()->latest('id')->limit(20)->get();
    }

    /** The latest export run, the shape {@see activeRun()} reads for the backup side. */
    public function activeExportRun(): ?LiveExportRun
    {
        return LiveExportRun::query()->latest('id')->first();
    }

    /** @return Collection<int, LiveExportRun> */
    public function exportRuns(): Collection
    {
        return LiveExportRun::query()->latest('id')->limit(20)->get();
    }

    /**
     * The pair a confirm is open on, its manifest read through the one reader of that format.
     *
     * A token with no readable manifest is null rather than an error: the page has a
     * sentence for a backup whose details are missing, and this is where it starts.
     */
    public function restorePair(?string $token): ?BackupPair
    {
        if ($token === null) {
            return null;
        }

        $directory = $this->directory();
        $manifest = $directory.DIRECTORY_SEPARATOR.'backup-'.$token.'.json';

        try {
            return BackupPair::read($manifest, $directory);
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
     * through the same reader the run verifies with, and the live half from the app's
     * own tables. The manga half is read only while the dialog is open, the same courtesy
     * the downloads size asks Suwayomi for, so reading this page costs nothing.
     *
     * @return array{pair: array{series: int, chapters: int}, live: array{series: int, chapters: int}, manga: array<string, int|string|null>, mangaProblem: string|null, takenAt: string, version: string}|null
     */
    public function restoreDiff(bool $dialogOpen, ?string $token): ?array
    {
        if (! $dialogOpen) {
            return null;
        }

        $pair = $this->restorePair($token);

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

    /**
     * What the server reports its own restore doing, while one is in flight.
     *
     * Answered only while a run row still claims to be running, so a finished run costs
     * the server nothing. Unreachable is named as unreachable rather than swallowed: the
     * row completes itself whatever the bar shows.
     *
     * @return array{unreachable: bool, status: array{state: string, mangaProgress: int, totalManga: int}|null}|null
     */
    public function restoreServerStatus(): ?array
    {
        $run = $this->activeRestoreRun();

        if ($run === null || ! $run->isRunning() || $this->presenter->isAbandoned($run)) {
            return null;
        }

        try {
            $status = $this->gateway->restoreStatus();
        } catch (SuwayomiException) {
            return ['unreachable' => true, 'status' => null];
        }

        return ['unreachable' => false, 'status' => $status];
    }

    /**
     * How big the downloads folder is, so the press is made against a number.
     *
     * Asked only while the dialog is open, so a reader reading the page costs Suwayomi
     * nothing. Unknown is received here as null and said plainly: the alternative to not
     * knowing is not an invented number.
     */
    public function downloadsSize(bool $dialogOpen): ?int
    {
        return $dialogOpen ? $this->downloads->bytes() : null;
    }
}
