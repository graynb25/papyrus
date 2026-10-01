<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What AniList says about one title, cached.
 *
 * ## Why this exists at all, given Suwayomi has a tracker
 *
 * Suwayomi does expose AniList and MAL, through `MangaType.trackRecords`. Measured:
 * it is `totalCount: 0`, because it is only populated once a reader has logged into
 * a tracker, and a self-hosted install has nobody who has. So the door through
 * Suwayomi is a door nobody opens, and the question has to be asked of AniList
 * directly. Its API is public and needs no key.
 *
 * ## One row per title, not per media
 *
 * The question a search asks is "what does AniList call *this* title", and the answer
 * is the same every time. Keying on the normalised title makes a repeat search free,
 * which matters because the rate limit is 30 requests a minute and a single fan-out
 * produces 91 distinct titles.
 *
 * ## What the title forms are for
 *
 * The display names are not the names sources use. Measured: searching
 * "The Berserker's Second Playthrough" returns media 205085 whose romaji is
 * "Game Sok 2-hoecha Gwangjeonsa" and whose native is "게임 속 2회차 광전사". The
 * English title appears only in `synonyms`. So all three forms and the synonyms are
 * kept: the id is what dedupe uses, and the rest is what lets a human look at the
 * answer and judge whether it is plausible.
 *
 * Not owned by a user. This is public reference data about a title, and two readers
 * searching the same title should not each pay for the same lookup, which is why this
 * is a plain model rather than an {@see OwnedModel}.
 *
 * @property int $id
 * @property int $anilist_id
 * @property string $normalized_title
 * @property string|null $title_romaji
 * @property string|null $title_english
 * @property string|null $title_native
 * @property array<int, string> $synonyms
 * @property bool $is_adult
 * @property string|null $format
 * @property string|null $country_of_origin
 * @property string|null $status
 * @property int|null $chapters
 * @property array<int, array<string, string>> $staff
 * @property CarbonImmutable $looked_up_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'anilist_id',
    'normalized_title',
    'title_romaji',
    'title_english',
    'title_native',
    'synonyms',
    'is_adult',
    'format',
    'country_of_origin',
    'status',
    'chapters',
    'staff',
    'looked_up_at',
])]
class AniListMedia extends Model
{
    /**
     * Pinned, not derived.
     *
     * Laravel's snake case would make this `ani_list_media`, because it breaks on the
     * capital L in `AniList`. The migration says `anilist_media`, and a model that
     * guesses the other name fails at runtime with "no such table" rather than at
     * boot, so the name is stated here rather than left to inference.
     */
    protected $table = 'anilist_media';

    protected function casts(): array
    {
        return [
            'anilist_id' => 'integer',
            'synonyms' => 'array',
            'is_adult' => 'boolean',
            'chapters' => 'integer',
            'staff' => 'array',
            'looked_up_at' => 'datetime',
        ];
    }

    /**
     * The row that answers for a normalised title, if it has been looked up.
     *
     * @param  Builder<AniListMedia>  $query
     * @return Builder<AniListMedia>
     */
    public function scopeForTitle(Builder $query, string $normalizedTitle): Builder
    {
        return $query->where('normalized_title', $normalizedTitle);
    }

    /**
     * The best name to show a reader for this media.
     *
     * English, then romaji, then native. Measured: the English title is often null
     * for a series that has no official translation, and the native form is the only
     * one left.
     */
    public function displayTitle(): string
    {
        return (string) ($this->title_english ?? $this->title_romaji ?? $this->title_native ?? '');
    }

    /**
     * Every name this media claims, which is the set a title check should accept.
     *
     * @return array<int, string>
     */
    public function allNames(): array
    {
        return array_values(array_filter(array_merge(
            [$this->title_romaji, $this->title_english, $this->title_native],
            $this->synonyms,
        ), static fn (?string $name): bool => $name !== null && trim($name) !== ''));
    }
}
