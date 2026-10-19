<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\ReadingSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One reader's one sitting with one chapter.
 *
 * The reader half of `read_seconds` on chapter_states is a lifetime total this
 * table knows nothing about, and this table is the ledger that can be sliced by
 * date: chapter_states has no when, this table is nothing but whens.
 *
 * An open row (ended_at null) accrues only when the progress endpoint fires, so
 * nothing a reader stopped reading keeps growing. A resumed chapter makes a new
 * row: earlier rows are closed at their own last activation and stay truthful.
 *
 * @property int $id
 * @property int $user_id
 * @property int $suwayomi_manga_id
 * @property int $suwayomi_chapter_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $last_active_at
 * @property CarbonImmutable|null $ended_at
 * @property int $read_seconds
 * @property int $pages_turned
 * @property int|null $last_page_seen
 */
#[Fillable([
    'suwayomi_manga_id',
    'suwayomi_chapter_id',
    'started_at',
    'last_active_at',
    'ended_at',
    'read_seconds',
    'pages_turned',
    'last_page_seen',
])]
class ReadingSession extends OwnedModel
{
    /** @use HasFactory<ReadingSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_active_at' => 'datetime',
            'ended_at' => 'datetime',
            'read_seconds' => 'integer',
            'pages_turned' => 'integer',
            'last_page_seen' => 'integer',
        ];
    }
}
