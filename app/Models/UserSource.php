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

    /**
     * Pin the named sources for one reader, or clear their pins.
     *
     * The two halves are deliberately different shapes. Pinning is an
     * {@see self::setDisabledFor()} shaped upsert, for the identical reason: a
     * source the reader never touched has no row, `update` would match nothing,
     * and the pin would vanish behind a control that reported it set. Unpinning
     * is an update alone: there is nothing to create, a reader cannot remove a
     * pin from a row that does not exist, and `pinned_at = null` on a missing row
     * would write a preference row whose every column says "untouched".
     *
     * @param  array<int, int>  $sourceIds
     */
    public static function setPinnedFor(User|int $user, array $sourceIds, bool $pinned): int
    {
        if ($sourceIds === []) {
            return 0;
        }

        $userId = $user instanceof User ? $user->getKey() : $user;
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));

        if ($pinned) {
            $now = now();

            $rows = array_map(static fn (int $sourceId): array => [
                'user_id' => $userId,
                'source_id' => $sourceId,
                'pinned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $sourceIds);

            // The scope is dropped for the same reason setDisabledFor() drops it:
            // the owner is written here and must not double as a filter.
            return static::withoutGlobalScope(OwnedByUser::class)->upsert(
                $rows,
                ['user_id', 'source_id'],
                ['pinned_at', 'updated_at'],
            );
        }

        $cleared = 0;

        foreach (array_chunk($sourceIds, 500) as $chunk) {
            $cleared += static::withoutGlobalScope(OwnedByUser::class)
                ->where('user_id', $userId)
                ->whereIn('source_id', $chunk)
                ->whereNotNull('pinned_at')
                ->update(['pinned_at' => null, 'updated_at' => now()]);
        }

        return $cleared;
    }

    /**
     * The sources this reader has pinned, as Suwayomi ids.
     *
     * One read of the pin column, in one place, so that the fanout's scope rule
     * and whatever renders a reader's pins end up talking about the same rows
     * rather than two copies of the query. `forUser()` rather than the ambient
     * scope, for the same reason the dashboard's disabled read uses it: the call
     * must answer for the reader named, not for whoever is logged in.
     *
     * @return array<int, int>
     */
    public static function pinnedSourceIdsFor(User|int $user): array
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return static::forUser($userId)->whereNotNull('pinned_at')->pluck('source_id')->all();
    }
}
