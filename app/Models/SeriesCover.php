<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own cover art for a series.
 *
 * Suwayomi has no custom-cover concept at all - ImageMutation exposes only
 * clearCachedImages. Files live on our disk; the app layer overrides
 * thumbnailUrl wherever it renders a cover for this user.
 *
 * @property int $id
 * @property int $user_id
 * @property int $suwayomi_manga_id
 * @property string $disk
 * @property string $path
 * @property string $origin
 * @property int|null $source_page
 */
#[Fillable(['suwayomi_manga_id', 'disk', 'path', 'origin', 'source_page'])]
class SeriesCover extends OwnedModel
{
    /**
     * @return BelongsTo<SeriesState, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(SeriesState::class, 'suwayomi_manga_id', 'suwayomi_manga_id');
    }
}
