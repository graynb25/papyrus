<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One source a backup named, as this server saw it.
 *
 * ## Why a missing source is a row rather than a log line
 *
 * Measured on a real backup: 1,277 series across 56 sources, of which 11 no longer exist.
 * Those 195 series cannot be recovered by anything, and the reader needs that stated rather
 * than inferred from a library that came back short.
 *
 * The row persists so the *second* run can say "still gone" without re-checking a repo for a
 * fact that has probably not changed, and so that the sentence names the source the reader
 * will recognise rather than a 19-digit id.
 *
 * ## obsolete and missing are different facts
 *
 * {@see $obsolete} means the backup itself marked the extension obsolete, which is how a
 * reader's own app knew the source had gone. A source that is merely not installed here is
 * fixable in one click. Conflating them would tell a reader to give up on something they
 * could have installed.
 *
 * @property int $id
 * @property int $user_id
 * @property int $import_run_id
 * @property int $source_id
 * @property string|null $name
 * @property string|null $pkg_name
 * @property int $series_count
 * @property bool $obsolete
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['import_run_id', 'source_id', 'name', 'pkg_name', 'series_count', 'obsolete'])]
class ImportedSource extends OwnedModel
{
    /** @return BelongsTo<ImportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }

    /**
     * The sentence a report shows for this source.
     *
     * Three cases, because a reader can do something different about each:
     *
     * - obsolete: the reader's own app said the source is gone. Nothing to do.
     * - not installed, not obsolete: installable, and installing it brings these series.
     * - installed: working, and named only so a reader can tell which of their sources
     *   came across.
     */
    public function summary(): string
    {
        if ($this->obsolete) {
            return sprintf(
                '%s no longer exists. %s were on it and cannot be brought across.',
                $this->name === null ? 'This source' : $this->name,
                number_format($this->series_count),
            );
        }

        return sprintf(
            '%s: %s of your series.',
            $this->name ?? 'This source',
            number_format($this->series_count),
        );
    }
}
