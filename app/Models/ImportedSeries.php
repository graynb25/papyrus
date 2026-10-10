<?php

namespace App\Models;

use App\Import\ImportedSeries as SeriesFromBackup;
use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One series this reader has imported, remembered by the identity both apps agree on.
 *
 * ## This is the ledger a re-import joins on
 *
 * A reader will import again, and the second import has to be a delta rather than a repeat.
 * So each series is keyed by `(source_id, source_url)` and carries a
 * {@see $fingerprint} of everything this importer would write for it. A later run compares
 * fingerprints: equal means nothing to do, different means apply the difference, absent
 * means new. The unique index on those three columns is also what stops a second run filing
 * the same series twice.
 *
 * ## Why the fingerprint covers chapters and not titles
 *
 * Because it has to answer one question: would writing this again change anything? So it
 * covers the chapter rows this importer acts on, their read and bookmark flags, their last
 * page, the two dates and the resume point. It does **not** cover the title: a source
 * re-fetching a series and getting a slightly different title is not a reason to rewrite a
 * library, and treating it as one would make every re-import a full write.
 *
 * A null fingerprint means "never resolved", which is what lets a series whose source was
 * dead last month be picked up when it comes back, rather than being retried as if new.
 *
 * @property int $id
 * @property int $user_id
 * @property int $import_run_id
 * @property int $source_id
 * @property string $source_url
 * @property string|null $fingerprint
 * @property int|null $suwayomi_manga_id
 * @property int|null $series_state_id The local row, when the series was filed without a source.
 * @property int|null $backup_manga_id
 * @property string|null $title
 * @property string $state
 * @property string|null $reason
 * @property int $attempts
 * @property int $chapters_seen
 * @property int $chapters_applied
 * @property int|null $last_seen_run_id
 * @property CarbonImmutable|null $last_applied_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'import_run_id',
    'source_id',
    'source_url',
    'fingerprint',
    'suwayomi_manga_id',
    'series_state_id',
    'backup_manga_id',
    'title',
    'state',
    'reason',
    'attempts',
    'chapters_seen',
    'chapters_applied',
    'last_seen_run_id',
    'last_applied_at',
])]
class ImportedSeries extends OwnedModel
{
    /**
     * `source_id` and `backup_manga_id` are past 2^53, so they are cast to int rather than
     * left as whatever the driver returns. They only survive as exact integers on this
     * project's SQLite; a driver that widened to float would round 2848420679472350044 to
     * 2848420679472350208 and every join after that would find nothing.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'suwayomi_manga_id' => 'integer',
            'backup_manga_id' => 'integer',
            'attempts' => 'integer',
            'chapters_seen' => 'integer',
            'chapters_applied' => 'integer',
            'last_seen_run_id' => 'integer',
            'last_applied_at' => 'datetime',
        ];
    }

    public const RESOLVED = 'resolved';

    /** Asked and not found: the source answered, and the series is not at that url. */
    public const UNRESOLVED = 'unresolved';

    /** The source holds more than one series on the url, so which one is not answerable. */
    public const AMBIGUOUS = 'ambiguous';

    /** This server has no extension for the source, so it cannot be asked at all. */
    public const MISSING_SOURCE = 'missing_source';

    /** @return BelongsTo<ImportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }

    /**
     * The fingerprint of a series as a backup describes it.
     *
     * Static because it is a property of the *file*, not of any row here: the same backup
     * produces the same fingerprint on any machine, which is what makes it comparable across
     * runs rather than merely internally consistent.
     *
     * The chapters are sorted by {@see SeriesFromBackup::sourceOrder} before hashing, because
     * SQLite does not promise an order and a fingerprint that changed when the query plan
     * did would rewrite every series on every import.
     */
    public static function fingerprintFor(SeriesFromBackup $series): string
    {
        $chapters = $series->chapters
            ->map(fn ($chapter): array => [
                $chapter->sourceOrder,
                $chapter->number,
                $chapter->name,
                $chapter->isRead ? 1 : 0,
                $chapter->isBookmarked ? 1 : 0,
                $chapter->lastPageRead,
            ])
            // JSON_THROW_ON_ERROR makes this return a string rather than a string|false,
            // and the cast says so rather than letting the two disagree at the call site.
            ->sortBy(fn (array $c): string => (string) json_encode($c, JSON_THROW_ON_ERROR))
            ->values()
            ->all();

        return hash('sha256', json_encode([
            // The identity, so two series cannot collide even with identical chapter sets.
            'source' => $series->sourceId,
            'url' => $series->url,
            'added_at' => $series->addedAt?->format('Y-m-d H:i:s'),
            'last_read_at' => $series->lastReadAt?->format('Y-m-d H:i:s'),
            'resume' => $series->backupResumeChapterId,
            'chapters' => $chapters,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Whether a newer backup would change anything for this row.
     *
     * Two rows about the same series always change something, even at identical state,
     * because the second backup's resume point is the one the reader most recently used and
     * preferring the newer is the whole point of re-importing. A row that never resolved
     * also counts as changed, so a source that comes back is picked up.
     */
    public function wantsApplyingFor(string $fingerprint): bool
    {
        return $this->state !== self::RESOLVED
            || $this->fingerprint === null
            || ! hash_equals($this->fingerprint, $fingerprint);
    }
}
