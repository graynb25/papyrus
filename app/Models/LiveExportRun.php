<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One press of the export button, from starting the run to whatever it ended as.
 *
 * ## Why this is the whole install's row
 *
 * {@see LiveBackupRun} already argues the shape and the export takes it: the run rows belong
 * to no one reader, because the file on disk outlives whoever asked for it, and every reader
 * who opens the page afterwards has reasons to know it happened. So the row takes no
 * {@see OwnedModel} shape. Whoever pressed the button is recorded in
 * {@see $started_by_user_id} and can be erased by that account's own deletion.
 *
 * ## A refusal is a row too
 *
 * {@see $refusal_reason} keeps the sentence, because the outcomes a reader most needs told
 * apart are "there is a merged file" and "nothing was exported, and here is why": a server
 * that refused its own fresh export is the one a log file loses and the one this row is for.
 *
 * The polled fields are the note (what the run says it is doing) and the state. The rest is
 * the finished receipt: the product's name on disk, its byte size, the series counts the
 * run's own comparison produced (in file, planned from the reader's arrangement, matched by
 * the merger's report), the shelf and read mark totals, and the skipped reasons for the
 * planned series the file could not take.
 *
 * @property int $id
 * @property int|null $started_by_user_id
 * @property string|null $file_name
 * @property string $state running|complete|failed|refused
 * @property string|null $progress_note
 * @property string|null $refusal_reason
 * @property int|null $file_bytes
 * @property int|null $series_in_file
 * @property int|null $series_planned
 * @property int|null $series_matched
 * @property int|null $shelves_appended
 * @property int|null $read_marks
 * @property list<string>|null $skipped
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'started_by_user_id',
    'file_name',
    'state',
    'progress_note',
    'refusal_reason',
    'file_bytes',
    'series_in_file',
    'series_planned',
    'series_matched',
    'shelves_appended',
    'read_marks',
    'skipped',
    'started_at',
    'finished_at',
])]
class LiveExportRun extends Model
{
    /**
     * Cast because every number here is compared or shown, and SQLite hands them back as
     * strings without this. The same reason {@see LiveBackupRun} carries its casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'file_bytes' => 'integer',
            'series_in_file' => 'integer',
            'series_planned' => 'integer',
            'series_matched' => 'integer',
            'shelves_appended' => 'integer',
            'read_marks' => 'integer',
            'skipped' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public const RUNNING = 'running';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    /** The run refused to export anything, with no file written. The sentence says why. */
    public const REFUSED = 'refused';

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /**
     * Whether the run is still going, so the page knows whether to poll.
     */
    public function isRunning(): bool
    {
        return $this->state === self::RUNNING;
    }
}
