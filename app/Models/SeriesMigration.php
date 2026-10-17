<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * One reader's record of moving a series from one source to another.
 *
 * Nothing upstream keeps this. Measured against the live server: GraphQL exposes no
 * migration field across 32 query, 78 mutation and 4 subscription fields, and there
 * is no REST v1 endpoint either. Suwayomi's own migration lives behind its WebUI, not
 * its API, and it would act on `Admin(1)`'s library, which in a multi-reader app is
 * the wrong owner. So the wizard composes the move and this is the only trace of it.
 *
 * @property int $id
 * @property int $user_id
 * @property int $from_manga_id
 * @property int $to_manga_id
 * @property string $from_source_id
 * @property string $to_source_id
 * @property string $from_source_name
 * @property string $to_source_name
 * @property int $chapters_total
 * @property int $chapters_carried
 * @property array<int, string>|null $unmatched_chapters
 * @property bool $carried_chapters
 * @property bool $carried_shelves
 * @property bool $tracking_requested
 * @property bool $remove_downloads
 * @property int $downloads_removed
 * @property int $downloads_cancelled
 * @property int $downloads_unremoved
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'from_manga_id',
    'to_manga_id',
    'from_source_id',
    'to_source_id',
    'from_source_name',
    'to_source_name',
    'chapters_total',
    'chapters_carried',
    'unmatched_chapters',
    'carried_chapters',
    'carried_shelves',
    'tracking_requested',
    'remove_downloads',
    'downloads_removed',
    'downloads_cancelled',
    'downloads_unremoved',
])]
class SeriesMigration extends OwnedModel
{
    /**
     * Chapters whose read state found no counterpart on the new source.
     *
     * Measured: `chapterNumber` placed 41 of 41 chapters uniquely on a well-behaved
     * series, so this is normally empty. It is kept because the reader is shown the
     * count, and a count with nothing behind it is not an answer.
     */
    protected function casts(): array
    {
        return [
            'from_manga_id' => 'integer',
            'to_manga_id' => 'integer',
            'chapters_total' => 'integer',
            'chapters_carried' => 'integer',
            'unmatched_chapters' => 'array',
            'carried_chapters' => 'boolean',
            'carried_shelves' => 'boolean',
            'tracking_requested' => 'boolean',
            'remove_downloads' => 'boolean',
            'downloads_removed' => 'integer',
            'downloads_cancelled' => 'integer',
            'downloads_unremoved' => 'integer',
        ];
    }

    /**
     * Chapters whose read state followed the series to its new source.
     */
    public function chaptersLost(): int
    {
        return max(0, (int) $this->chapters_total - (int) $this->chapters_carried);
    }

    /**
     * How much of the series' read state survived, 0.0 to 1.0.
     *
     * Null when there was nothing to carry, which is different from carrying nothing:
     * a series nobody had started reads as 1.0, not 0.0.
     */
    public function carriedRatio(): ?float
    {
        $total = (int) $this->chapters_total;

        if ($total === 0) {
            return null;
        }

        return round((int) $this->chapters_carried / $total, 3);
    }

    /**
     * The manga ids this reader has migrated away from, for one lookup.
     *
     * Used by the download centre to label stranded downloads without a query per
     * row. The same lesson as the library page: one call, not one per row.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, int> old manga id to the manga id that replaced it
     */
    public static function replacementsFor(int $userId, array $mangaIds): array
    {
        if ($mangaIds === []) {
            return [];
        }

        $map = [];

        foreach (self::forUser($userId)->whereIn('from_manga_id', $mangaIds)->get() as $row) {
            $map[(int) $row->from_manga_id] = (int) $row->to_manga_id;
        }

        return $map;
    }
}
