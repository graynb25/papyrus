<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The join between a shelf and a series.
 *
 * Exists only to give `added_at` a sensible default, so that filing a series
 * records when it happened without every call site remembering to set it.
 *
 * `added_at` is a union because this pivot carries no casts and so is written
 * from both `now()` and an Eloquent timestamp, which are different classes
 * depending on whether the application has immutable dates on. Naming one of the
 * two makes the other a type error for no benefit.
 *
 * Only `added_at` is declared. The framework timestamps are on the table and come
 * back raw, and guessing at their type here would be a claim nothing checks.
 *
 * @property int $category_id
 * @property int $series_state_id
 * @property Carbon|CarbonImmutable|null $added_at
 */
class CategorySeries extends Pivot
{
    protected $table = 'category_series';

    public $incrementing = false;

    /**
     * `added_at` is set here rather than by the caller.
     *
     * Without this, a shelf filled from the library page would record the time
     * the pivot row was written, which is the same thing, and a shelf filled from
     * an import would record nothing at all. One default, one meaning.
     */
    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            if ($pivot->added_at === null) {
                $pivot->added_at = now();
            }
        });
    }
}
