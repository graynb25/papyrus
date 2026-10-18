<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One series in a bulk source migration, and what was found for it on the new source.
 *
 * ## Why the measurements are stored and not recomputed
 *
 * Because the reader approves a list and the approval has to mean something. Sources move
 * under a reader's feet: a site that listed forty-one chapters yesterday lists thirty-nine
 * today. Recomputing on render would show numbers from a later moment than the ones the reader
 * ticked, and a reader approving "39 chapters against 41" deserves to be looking at 39
 * against 41.
 *
 * ## Why there is no `approved` column
 *
 * An approval that was written down but not acted on is a claim that something happened when it
 * did not. The reader ticks a box and the move happens in the same request, so the only thing
 * worth recording is what did happen.
 *
 * @property int $id
 * @property int $bulk_migration_id
 * @property int $user_id
 * @property int $from_manga_id
 * @property int|null $to_manga_id
 * @property string $title
 * @property string|null $to_title
 * @property string|null $to_source_id
 * @property string|null $to_source_name
 * @property int|null $chapters_here
 * @property int|null $chapters_there
 * @property int|null $unread_here
 * @property float|null $title_similarity
 * @property int|null $chapter_delta
 * @property float|null $author_overlap
 * @property string $status
 * @property string|null $reason
 */
#[Fillable([
    'bulk_migration_id',
    'from_manga_id',
    'to_manga_id',
    'title',
    'to_title',
    'to_source_id',
    'to_source_name',
    'chapters_here',
    'chapters_there',
    'unread_here',
    'title_similarity',
    'chapter_delta',
    'author_overlap',
    'status',
    'reason',
])]
class BulkMigrationItem extends OwnedModel
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_manga_id' => 'integer',
            'to_manga_id' => 'integer',
            'chapters_here' => 'integer',
            'chapters_there' => 'integer',
            'unread_here' => 'integer',
            'title_similarity' => 'float',
            'chapter_delta' => 'integer',
            'author_overlap' => 'float',
        ];
    }

    /** A candidate was found and the reader has not decided yet. */
    public const PENDING = 'pending';

    /** Approved, and it moved. */
    public const MOVED = 'moved';

    /** The destination does not have this series, so there is nothing to approve. */
    public const SKIPPED = 'skipped';

    /** Approved, and the migrator declined. */
    public const REFUSED = 'refused';

    /** The comparison for this one series could not finish. */
    public const FAILED = 'failed';

    /** @return BelongsTo<BulkMigration, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BulkMigration::class, 'bulk_migration_id');
    }

    /**
     * Whether the reader could tick this one.
     *
     * Requires a destination and a status that still means "undecided". A row that already
     * moved cannot be moved again from the same run, and offering it would let a second press
     * re-run a migration that has already happened.
     */
    public function isOfferable(): bool
    {
        return $this->to_manga_id !== null && $this->status === self::PENDING;
    }

    /**
     * Whether every signal available agrees this is the same series.
     *
     * ## Why this is a pre-tick and not a decision
     *
     * It decides what is already ticked, not what happens. The move still needs the reader to
     * press the button, and the list shows the numbers beside every row so a reader can see
     * what they are agreeing to.
     *
     * The test is deliberately strict, because a wrong pre-tick is the one thing on this page
     * that happens without a reader choosing it. Title similarity is the weak signal and
     * chapters are the strong one: measured, five of six real candidates tied at a title
     * similarity of 1.000 while their chapter counts ran from 21 to 333. So a title alone is
     * not enough to tick anything, and a title plus an exact chapter match is.
     *
     * @param  float  $minimumTitle  How alike the titles have to be. Read from config so it can
     *                               be measured rather than guessed at read time.
     */
    public function isConfident(float $minimumTitle = 0.95): bool
    {
        if (! $this->isOfferable()) {
            return false;
        }

        if ($this->title_similarity === null || $this->title_similarity < $minimumTitle) {
            return false;
        }

        /*
         * An unknown author is not a disagreement.
         *
         * Measured: `author_overlap` is null whenever either side credits nobody, and a
         * self hosted install has many sources that credit nobody. Treating that as a mismatch
         * would leave almost nothing pre-ticked on exactly the installs least able to check.
         */
        if ($this->author_overlap !== null && $this->author_overlap < 1.0) {
            return false;
        }

        return $this->chapters_here !== null
            && $this->chapters_here > 0
            && (int) $this->chapter_delta === 0;
    }
}
