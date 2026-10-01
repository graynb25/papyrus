<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use App\Search\Deduplicator;
use App\Support\ImageUrl;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One search hit, from one source.
 *
 * A series available on five sources produces five rows. Grouping them is the
 * deduplicator's job - see {@see Deduplicator}.
 *
 * @property int $id
 * @property int $user_id
 * @property int $search_run_id
 * @property int $suwayomi_manga_id
 * @property int $suwayomi_source_id
 * @property string $title
 * @property string $normalized_title
 * @property string|null $url
 * @property string|null $thumbnail_url
 * @property string $source_name
 * @property string $content_warning
 * @property string|null $status
 * @property array<int, string>|null $genre
 */
#[Fillable([
    'search_run_id',
    'suwayomi_manga_id',
    'suwayomi_source_id',
    'title',
    'normalized_title',
    'url',
    'thumbnail_url',
    'source_name',
    'content_warning',
    'status',
    'genre',

    // Fetched for H6's `author:` to be checked against, and shown on results.
    'author',
])]
class SearchResult extends OwnedModel
{
    /**
     * @return BelongsTo<SearchRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SearchRun::class, 'search_run_id');
    }

    /**
     * Cover art, proxied through the app rather than fetched from the upstream
     * host directly. Sources hotlink-protect their images, and a browser
     * hitting them cross-origin would get a referer rejection - so the same
     * token-bearing proxy that serves page images serves covers.
     */
    public function coverUrl(): ?string
    {
        if ($this->thumbnail_url === null || $this->thumbnail_url === '') {
            return null;
        }

        // The path is rebuilt from the ids rather than echoed from the payload, so
        // a server that suddenly returns something outside the proxy allowlist
        // cannot get it into a template. ImageUrl returns null in that case.
        return ImageUrl::thumbnail($this->suwayomi_manga_id, ImageUrl::defaultFormat());
    }

    protected function casts(): array
    {
        return [
            'genre' => 'array',
        ];
    }
}
