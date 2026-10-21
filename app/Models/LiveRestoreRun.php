<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One press of a row's Restore action, from starting the run to whatever it ended as.
 *
 * ## Why this is the whole install's row
 *
 * The same shape {@see LiveBackupRun} carries and the class comment there owns: a restore
 * belongs to no one reader either, because the data it puts back is the install's own, and
 * erasing whoever pressed the button must not erase what the press did. Whoever pressed is
 * recorded in {@see $started_by_user_id} and can be erased by that account's own deletion.
 *
 * ## A refusal and a still-restored half are rows too
 *
 * {@see $refusal_reason} keeps the sentence the run was refused by. Two outcomes a reader
 * needs told apart land here: "nothing was restored, and here is why" and "the server's
 * side was restored, and the reader half is waiting for the run's own retry", the second
 * carried by the progress note while the row reads failed, until the next attempt owns it.
 *
 * @property int $id
 * @property int|null $started_by_user_id
 * @property string|null $token
 * @property string $state running|complete|failed|refused
 * @property string|null $progress_note
 * @property string|null $refusal_reason
 * @property string|null $suwayomi_version
 * @property int|null $series_states
 * @property int|null $chapter_states
 * @property array<string, int|string|null>|null $manga_summary
 * @property array{missingSources: list<string>, missingTrackers: list<string>, mangasMissingSources: list<string>}|null $restore_summary
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'started_by_user_id',
    'token',
    'state',
    'progress_note',
    'refusal_reason',
    'suwayomi_version',
    'series_states',
    'chapter_states',
    'manga_summary',
    'restore_summary',
    'started_at',
    'finished_at',
])]
class LiveRestoreRun extends Model
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
            'series_states' => 'integer',
            'chapter_states' => 'integer',
            'manga_summary' => 'array',
            'restore_summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public const RUNNING = 'running';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    /** The run refused to restore anything, with nothing changed. The sentence says why. */
    public const REFUSED = 'refused';

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /**
     * Whether the run is still going, so the page knows whether to poll it.
     */
    public function isRunning(): bool
    {
        return $this->state === self::RUNNING;
    }
}
