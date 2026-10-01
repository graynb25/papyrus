<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A shelf in this reader's library.
 *
 * Many to many with series rather than one category per series, because the cases
 * that come up are all overlaps: something is both "Reading soon" and a webtoon,
 * and forcing a single choice loses one of those. Mihon and every reader derived
 * from it do the same.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int $order_column
 * @property array<string, mixed>|null $settings
 * @property string|null $note
 */
#[Fillable(['name', 'order_column', 'settings', 'note'])]
class Category extends OwnedModel
{
    protected function casts(): array
    {
        return [
            'order_column' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * Whether this shelf says anything about how its series read.
     *
     * A shelf with a null settings column contributes no layer, which is a different
     * state from an empty object and is what lets an unset key fall through to the
     * profile below. The reader's home shelf is what selects this layer, so an empty
     * shelf is a shelf that simply has no opinion.
     */
    public function hasSettings(): bool
    {
        return is_array($this->settings) && $this->settings !== [];
    }

    /**
     * Series filed under this shelf.
     *
     * @return BelongsToMany<SeriesState, $this, CategorySeries>
     */
    public function series(): BelongsToMany
    {
        return $this->belongsToMany(SeriesState::class, 'category_series')
            ->using(CategorySeries::class)
            ->withPivot(['added_at'])
            ->withTimestamps();
    }

    /**
     * Ordering that puts the reader's own order first and the name second.
     *
     * The name tiebreak is not decoration. Two shelves created in the same request
     * share an order, and without it the shelf list reshuffles itself on every
     * load, which is exactly the kind of thing a reader stops trusting.
     *
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order_column')->orderBy('name');
    }
}
