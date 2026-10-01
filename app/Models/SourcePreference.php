<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Snapshot of a source's preference and filter lists.
 *
 * Suwayomi addresses both by ordinal position, not by key, so reordering a
 * source's filter list silently mis-targets writes. We snapshot the list and
 * resolve position -> key at read time, so a write always names the preference
 * the user actually saw in the UI.
 *
 * @property int $id
 * @property int $source_id
 * @property array<int, array<string, mixed>> $preferences
 * @property array<int, array<string, mixed>>|null $filters
 * @property Carbon|null $cached_at
 */
#[Fillable(['source_id', 'preferences', 'filters', 'cached_at'])]
class SourcePreference extends Model
{
    protected function casts(): array
    {
        return [
            'preferences' => 'array',
            'filters' => 'array',
            'cached_at' => 'datetime',
        ];
    }

    /**
     * Map each preference's ordinal position to its key, so callers can write
     * by identity rather than by whatever order the list happens to be in.
     *
     * @return array<int, string>
     */
    public function positionToKey(): array
    {
        $map = [];

        foreach ($this->preferences as $index => $preference) {
            $key = $preference['key'] ?? null;

            if (is_string($key)) {
                $map[$index] = $key;
            }
        }

        return $map;
    }

    /**
     * The inverse, for turning a remembered key back into a writable position.
     *
     * @return array<string, int>
     */
    public function keyToPosition(): array
    {
        return array_flip($this->positionToKey());
    }
}
