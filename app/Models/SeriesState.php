<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Database\Factories\SeriesStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Per-user state for a series that Suwayomi cannot hold for us.
 *
 * Suwayomi's UpdateMangaPatchInput exposes only `inLibrary`, so everything else
 * about a series - reader mode, tags, rating, notes, cover, scanlator
 * exclusions, fetch interval - lives here, keyed by MangaType.id.
 *
 * @property int $id
 * @property int $user_id
 * @property int $suwayomi_manga_id
 * @property bool $in_library
 * @property int|null $default_category_id
 * @property string|null $chapter_list_fingerprint
 * @property CarbonImmutable|null $chapter_list_checked_at
 * @property CarbonImmutable|null $added_at
 * @property CarbonImmutable|null $favourited_at
 * @property CarbonImmutable|null $read_later_at
 * @property string|null $rating
 * @property array<int, string>|null $tags
 * @property array<int, string>|null $excluded_scanlators
 * @property array<string, mixed>|null $reader_settings
 * @property string|null $reading_mode
 * @property array<string, mixed>|null $chapter_list_settings
 * @property int|null $fetch_interval_days
 * @property string|null $update_strategy
 * @property int|null $last_read_chapter_id
 * @property CarbonImmutable|null $last_read_at
 */
#[Fillable([
    'suwayomi_manga_id',
    'in_library',
    'default_category_id',
    'chapter_list_fingerprint',
    'chapter_list_checked_at',
    'added_at',
    'favourited_at',
    'read_later_at',
    'rating',
    'tags',
    'excluded_scanlators',
    'reader_settings',
    'reading_mode',
    'chapter_list_settings',
    'fetch_interval_days',
    'update_strategy',
    'last_read_chapter_id',
    'last_read_at',
])]
class SeriesState extends OwnedModel
{
    /** @use HasFactory<SeriesStateFactory> */
    use HasFactory;

    /**
     * Every shelf this series is filed in.
     *
     * The pivot class is named in the return type because `using()` returns a
     * different generic instantiation than the declared one, and leaving it off
     * is a type error under PHPStan for no runtime reason.
     *
     * @return BelongsToMany<Category, $this, CategorySeries>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_series')
            ->using(CategorySeries::class)
            ->withPivot(['added_at'])
            ->withTimestamps();
    }

    /**
     * The shelf this series lives in when none has been chosen.
     *
     * @return BelongsTo<Category, $this>
     */
    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'default_category_id');
    }

    /**
     * @return HasMany<ChapterState, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(ChapterState::class, 'suwayomi_manga_id', 'suwayomi_manga_id');
    }

    /**
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable');
    }

    /**
     * @return HasMany<SeriesCover, $this>
     */
    public function cover(): HasMany
    {
        return $this->hasMany(SeriesCover::class, 'suwayomi_manga_id', 'suwayomi_manga_id');
    }

    protected function casts(): array
    {
        return [
            'in_library' => 'boolean',
            'default_category_id' => 'integer',
            'chapter_list_fingerprint' => 'string',
            'chapter_list_checked_at' => 'datetime',
            'added_at' => 'datetime',
            'favourited_at' => 'datetime',
            'read_later_at' => 'datetime',
            'last_read_at' => 'datetime',
            'rating' => 'decimal:1',
            'tags' => 'array',
            'excluded_scanlators' => 'array',
            'reader_settings' => 'array',
            'chapter_list_settings' => 'array',
            'fetch_interval_days' => 'integer',
        ];
    }

    public function isFavourite(): bool
    {
        return $this->favourited_at !== null;
    }

    public function isQueuedForLater(): bool
    {
        return $this->read_later_at !== null;
    }
}
