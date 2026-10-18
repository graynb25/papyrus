<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One destination a bulk migration is allowed to search.
 *
 * The names are stamped when the run is created, the way the run's own `from_source_name`
 * is, for the same reason: the run outlives the mirror row, and the card is the thing that
 * names a source which has since vanished from this server.
 *
 * @property int $id
 * @property int $bulk_migration_id
 * @property int $user_id
 * @property string $source_id
 * @property string|null $source_name
 * @property string $lang
 * @property int $seq
 */
#[Fillable([
    'bulk_migration_id',
    'source_id',
    'source_name',
    'lang',
    'seq',
])]
class BulkMigrationTarget extends OwnedModel
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
        ];
    }

    /** @return BelongsTo<BulkMigration, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BulkMigration::class);
    }
}
