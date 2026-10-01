<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One reader's intent to download one chapter, and how it went.
 *
 * The ledger Suwayomi does not keep. Measured: a chapter leaves
 * `downloadStatus.queue` the moment it finishes, and the only surviving trace
 * upstream is `ChapterType.isDownloaded`. `DownloadState.ERROR` carries no
 * reason, so `error` here is whatever we could observe ourselves.
 *
 * @property int $id
 * @property int $user_id
 * @property int $suwayomi_chapter_id
 * @property int $suwayomi_manga_id
 * @property string $state
 * @property float $progress
 * @property CarbonImmutable|null $enqueued_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $page_count
 * @property string|null $error
 */
#[Fillable([
    'suwayomi_chapter_id',
    'suwayomi_manga_id',
    'state',
    'progress',
    'enqueued_at',
    'started_at',
    'finished_at',
    'page_count',
    'error',
])]
class Download extends OwnedModel
{
    /** Waiting for the shared downloader to reach it. */
    public const QUEUED = 'queued';

    /** The server has it and is fetching pages. */
    public const DOWNLOADING = 'downloading';

    /** Pages are on disk. The state the reader actually wanted. */
    public const FINISHED = 'finished';

    /** Left the queue without becoming downloaded. We do not know why. */
    public const FAILED = 'failed';

    /**
     * The reader removed it.
     *
     * Distinct from `failed` on purpose: a deliberate removal recorded as a
     * failure is a bug report waiting to happen.
     */
    public const CANCELLED = 'cancelled';

    /**
     * States that still expect the server to do something.
     *
     * The reconciler only looks at these, so a finished row is never polled again
     * and a chapter that has been downloaded is not re-checked on every sync.
     *
     * @var array<int, string>
     */
    public const PENDING = [self::QUEUED, self::DOWNLOADING];

    protected function casts(): array
    {
        return [
            'progress' => 'float',
            'enqueued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'page_count' => 'integer',
        ];
    }

    /**
     * Rows that still expect the server to act.
     *
     * @param  Builder<Download>  $query
     * @return Builder<Download>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('state', self::PENDING);
    }

    /**
     * Whether this download has reached a state where nothing more will happen.
     */
    public function isSettled(): bool
    {
        return ! in_array($this->state, self::PENDING, true);
    }
}
