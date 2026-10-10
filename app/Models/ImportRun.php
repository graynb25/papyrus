<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One attempt to import another reader app's library backup.
 *
 * ## Why a refused run is still a row
 *
 * The two outcomes a reader most needs told apart are "it imported" and "it did nothing",
 * and the second includes the cases where the file was the wrong format, the version was
 * unknown, or every source is gone. Those are exactly the ones a log file loses, so a
 * refusal is kept with the reader's own sentence in {@see $refusal_reason} rather than
 * being discarded.
 *
 * ## The counters are the report
 *
 * A run is polled while it goes, so {@see $seriesWithNoChapterList()} and the rest exist to
 * be shown. They are deliberately more than one number: "1,082 imported" hides that 195
 * series sat on sources that no longer exist, and that is the difference between a library
 * that came across and one that quietly shrank.
 *
 * @property int $id
 * @property int $user_id
 * @property string $format
 * @property int|null $schema_version
 * @property string|null $file_name
 * @property string $state running|complete|failed|refused
 * @property string|null $refusal_reason
 * @property int|null $series_in_file
 * @property int|null $library_series
 * @property int|null $chapters
 * @property int $resolved
 * @property int $from_catalogue
 * @property int $from_source
 * @property int $sources_missing
 * @property int $not_found
 * @property int $ambiguous
 * @property int $series_on_missing_sources
 * @property int $applied
 * @property int $unchanged
 * @property int $read_chapters_applied
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'format',
    'schema_version',
    'file_name',
    'state',
    'refusal_reason',
    'series_in_file',
    'library_series',
    'chapters',
    'resolved',
    'from_catalogue',
    'from_source',
    'sources_missing',
    'not_found',
    'ambiguous',
    'series_on_missing_sources',
    'applied',
    'unchanged',
    'read_chapters_applied',
    'started_at',
    'finished_at',
])]
class ImportRun extends OwnedModel
{
    /**
     * Cast because every counter here is compared and summed, and SQLite hands them back as
     * strings without this. `$run->applied` arrived as `'1'`, so a report printed a string and
     * a check for "nothing was imported" was false when nothing had been.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'series_in_file' => 'integer',
            'library_series' => 'integer',
            'chapters' => 'integer',
            'resolved' => 'integer',
            'from_catalogue' => 'integer',
            'from_source' => 'integer',
            'sources_missing' => 'integer',
            'not_found' => 'integer',
            'ambiguous' => 'integer',
            'series_on_missing_sources' => 'integer',
            'applied' => 'integer',
            'unchanged' => 'integer',
            'read_chapters_applied' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public const RUNNING = 'running';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    /** The file was readable but not importable. The reader's sentence says why. */
    public const REFUSED = 'refused';

    /** @return HasMany<ImportedSeries, $this> */
    public function series(): HasMany
    {
        return $this->hasMany(ImportedSeries::class);
    }

    /** @return HasMany<ImportedSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(ImportedSource::class);
    }

    /**
     * Whether the run is still going, so a page knows whether to poll.
     */
    public function isRunning(): bool
    {
        return $this->state === self::RUNNING;
    }

    /**
     * The fraction of the library that came across, for a report that admits its size.
     *
     * Null when the file said nothing, which is different from zero and reads as "unknown"
     * rather than "nothing was imported".
     */
    public function completionPercent(): ?float
    {
        if (! $this->library_series) {
            return null;
        }

        return round($this->applied / $this->library_series * 100, 1);
    }

    /**
     * Series this run looked at and could not place, and why.
     *
     * Grouped so a report can say "195 series on 11 sources that no longer exist" instead
     * of listing 195 rows. A reader cannot act on the list and can act on the sentence.
     *
     * @return Collection<string, int>
     */
    public function reasonsGrouped(): Collection
    {
        return $this->series()
            ->where('state', '!=', ImportedSeries::RESOLVED)
            ->select('reason')
            ->selectRaw('count(*) as total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->pluck('total', 'reason');
    }
}
