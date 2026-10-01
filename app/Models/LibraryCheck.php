<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What this reader's last check of one series found.
 *
 * ## One row, not a history
 *
 * A check is a thing that happens every day forever, so a row per check would make
 * a table that grows without bound to answer a question the most recent row already
 * answers. The unique index on `(user_id, suwayomi_manga_id)` is what makes a
 * re-check an update.
 *
 * ## Owned, because the schedule is a reader's decision
 *
 * Whether a series is checked daily or never is per reader, and so is the history
 * of what that reader was told. The underlying fetch is shared, like everything in
 * Suwayomi, but the record of it is not.
 *
 * @property int $id
 * @property int $user_id
 * @property int $suwayomi_manga_id
 * @property string $state
 * @property int|null $chapter_count
 * @property int|null $new_chapters
 * @property string|null $error
 * @property CarbonImmutable $checked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 */
class LibraryCheck extends OwnedModel
{
    /**
     * The source re-read and the chapter list came back.
     */
    public const CHECKED = 'checked';

    /**
     * The source answered and had no chapters.
     *
     * Not a failure. Measured: `fetchMangaAndChapters` on such a series throws a
     * bare `java.lang.Exception: No chapters found`, delivered as a GraphQL error on
     * HTTP 200, so the distinction is ours to draw and worth drawing. A source with
     * no chapters today is a fact about the source, and reporting it as an error
     * would send a reader looking for a fault that is not there.
     */
    public const NO_CHAPTERS = 'no_chapters';

    /**
     * The call itself failed. Our error text is all we have, because Suwayomi
     * reports a bare exception with no detail.
     */
    public const ERROR = 'error';

    /**
     * The floor kept this series out of the pass.
     *
     * Recorded so the page can say "6 of 20 due" rather than leaving a reader to
     * assume the other fourteen were visited and found current.
     */
    public const SKIPPED = 'skipped';

    protected $fillable = [
        'suwayomi_manga_id',
        'state',
        'chapter_count',
        'new_chapters',
        'error',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'suwayomi_manga_id' => 'integer',
            'chapter_count' => 'integer',
            'new_chapters' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<LibraryCheck>  $query
     * @return Builder<LibraryCheck>
     */
    public function scopeFoundChapters(Builder $query): Builder
    {
        return $query->where('state', self::CHECKED);
    }

    /**
     * @param  Builder<LibraryCheck>  $query
     * @return Builder<LibraryCheck>
     */
    public function scopeWithNewChapters(Builder $query): Builder
    {
        return $query->whereNotNull('new_chapters')->where('new_chapters', '>', 0);
    }

    /**
     * @param  Builder<LibraryCheck>  $query
     * @return Builder<LibraryCheck>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('state', self::ERROR);
    }

    /**
     * Whether the last check turned up chapters the reader had not seen.
     *
     * Null new_chapters means a first check, which is not the same as nothing new,
     * so it is excluded rather than treated as a zero.
     */
    public function hasNewChapters(): bool
    {
        return $this->new_chapters !== null && $this->new_chapters > 0;
    }
}
