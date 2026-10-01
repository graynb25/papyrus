<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use App\Models\Scopes\OwnedByUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Support\Carbon;

/**
 * Per-user source preferences Suwayomi has nowhere to store.
 *
 * Suwayomi knows a source exists, not which of its N users care about it, so
 * pinning, disabling and migration policy all live here.
 *
 * @property int $id
 * @property int $user_id
 * @property int $source_id
 * @property Carbon|null $pinned_at
 * @property bool $disabled
 * @property bool $incognito
 * @property bool $migration_enabled
 * @property array<int, string>|null $migration_flags
 * @property bool $migration_deep_search
 */
#[Fillable([
    'source_id',
    'pinned_at',
    'disabled',
    'incognito',
    'migration_enabled',
    'migration_flags',
    'migration_deep_search',
])]
class UserSource extends OwnedModel
{
    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
            'disabled' => 'boolean',
            'incognito' => 'boolean',
            'migration_enabled' => 'boolean',
            'migration_flags' => 'array',
            'migration_deep_search' => 'boolean',
        ];
    }

    /**
     * Turn the skip flag on or off for a set of sources, creating rows as needed.
     *
     * An upsert rather than a mass update, because the rows usually do not exist
     * yet: a user who has never touched a source has no user_sources entry for
     * it, so `whereIn(...)->update()` quietly matches nothing and the source stays
     * in their searches while the interface claims it was skipped.
     *
     * @param  array<int, int>  $sourceIds
     */
    public static function setDisabledFor(User|int $user, array $sourceIds, bool $disabled): int
    {
        if ($sourceIds === []) {
            return 0;
        }

        $userId = $user instanceof User ? $user->getKey() : $user;
        $now = now();

        $rows = array_map(static fn (int $sourceId): array => [
            'user_id' => $userId,
            'source_id' => $sourceId,
            'disabled' => $disabled,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_unique($sourceIds)));

        // The tenant scope is dropped on purpose: the row's owner is written
        // explicitly, and letting the scope add its own filter here would make
        // the conflict target depend on who is currently logged in.
        return static::withoutGlobalScope(OwnedByUser::class)->upsert(
            $rows,
            ['user_id', 'source_id'],
            ['disabled', 'updated_at'],
        );
    }
}
