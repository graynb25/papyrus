<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One press of the Back up now button, from starting the run to whatever it ended as.
 *
 * ## Why this is the whole install's row
 *
 * The other run rows in this app, {@see ImportRun} and {@see BulkMigration}, belong to the
 * reader whose data moved, and their ownership is what makes a run row theirs. A live
 * backup is the reverse: its product is the install's pair, taken so the data survives,
 * and every reader who opens the page afterwards has reasons to know it happened. So the
 * row takes no {@see OwnedModel} shape. Whoever pressed the button is
 * recorded in {@see $started_by_user_id} and can be erased by that account's own deletion;
 * nobody else's is ever made to be.
 *
 * ## A refusal is a row too
 *
 * {@see $refusal_reason} keeps the sentence, because the outcomes a reader most needs
 * told apart are "there is a new pair" and "nothing was taken, and here is why": a run
 * that answered nothing on Suwayomi, or hit a half verified against the wrong moment, is
 * the one a log file loses and the one this row is for.
 *
 * The polled fields are the note (what the run says it is doing) and the state. The rest
 * is the finished receipt: the token, the two state counts, the manga summary, the Suwayomi
 * version the manifest binds to, and the downloads zip size only when the press asked for
 * the downloads folder.
 *
 * @property int $id
 * @property int|null $started_by_user_id
 * @property string|null $token
 * @property string $state running|complete|failed|refused
 * @property bool $include_downloads
 * @property string|null $progress_note
 * @property string|null $refusal_reason
 * @property string|null $suwayomi_version
 * @property int|null $downloads_bytes
 * @property int|null $series_states
 * @property int|null $chapter_states
 * @property array<string, int|string|null>|null $manga_summary
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'started_by_user_id',
    'token',
    'state',
    'include_downloads',
    'progress_note',
    'refusal_reason',
    'suwayomi_version',
    'downloads_bytes',
    'series_states',
    'chapter_states',
    'manga_summary',
    'started_at',
    'finished_at',
])]
class LiveBackupRun extends Model
{
    /**
     * Cast because every number here is compared or shown, and SQLite hands them back as
     * strings without this. The same reason {@see ImportRun} carries its casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'include_downloads' => 'boolean',
            'downloads_bytes' => 'integer',
            'series_states' => 'integer',
            'chapter_states' => 'integer',
            'manga_summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public const RUNNING = 'running';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    /** The run refused to take anything, with nothing changed. The sentence says why. */
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
