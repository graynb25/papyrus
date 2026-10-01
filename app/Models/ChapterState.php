<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\ChapterStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Per-user read state for a chapter.
 *
 * Suwayomi persists is_read, is_bookmarked, last_page_read and last_read_at,
 * but it is single-tenant: every authenticated request resolves to Admin(1), so
 * its read state cannot be per-user. Ours is authoritative; Suwayomi is only
 * mirrored in "single" deployment mode, and even then never relied upon.
 *
 * @property int $id
 * @property int $user_id
 * @property int $suwayomi_chapter_id
 * @property int $suwayomi_manga_id
 * @property bool $is_read
 * @property bool $is_bookmarked
 * @property int $last_page_read
 * @property int $read_seconds
 * @property CarbonImmutable|null $last_read_at
 */
#[Fillable([
    'suwayomi_chapter_id',
    'suwayomi_manga_id',
    'is_read',
    'is_bookmarked',
    'last_page_read',
    'read_seconds',
    'last_read_at',
])]
class ChapterState extends OwnedModel
{
    /** @use HasFactory<ChapterStateFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<SeriesState, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(SeriesState::class, 'suwayomi_manga_id', 'suwayomi_manga_id');
    }

    /**
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable');
    }

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'is_bookmarked' => 'boolean',
            'last_page_read' => 'integer',
            'read_seconds' => 'integer',
            'last_read_at' => 'datetime',
        ];
    }
}
