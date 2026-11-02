<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The reader half's own per-reader preferences, one key at a time.
 *
 * ## Why this exists
 *
 * `user_settings` has been in the schema since the second migration, with a
 * unique `(user_id, key)` and, for a long time, nothing reading it. The first
 * thing to use it was AniList's matching switch, and that class says why it is
 * a key rather than a column: "the next preference belongs beside this one,
 * and adding a migration for every reader toggle is a worse answer than a
 * key."
 *
 * So this is that next preference's home, and the reading and the writing live
 * here rather than being written out a second time per preference. Two
 * preferences that each carry their own copy of the same four lines are two
 * copies that can drift, and the copy that drifts is the one nobody reads.
 *
 * ## Why the value is a boolean
 *
 * The column is JSON and the storage is generic, but every preference in it so
 * far is a yes or a no, and a value that means one thing is easier to read back
 * than a value that could mean several. A preference that needs a date beside
 * it can carry one in its own table, the way a store's trust does.
 */
final class UserPreference
{
    /**
     * The stored value, or null when this reader has never set it.
     *
     * Null is a real answer and not a false one: it says the row is absent,
     * which is a different thing from a row that says no. A caller that wants
     * the answer rather than the state says which of the two it needs.
     */
    public function read(User|int $user, string $key): ?bool
    {
        $userId = UserKey::of($user);

        $row = DB::connection()->table('user_settings')
            ->where('user_id', $userId)
            ->where('key', $key)
            ->value('value');

        if ($row === null) {
            return null;
        }

        // The column is JSON, so a stored `true` arrives as the string "1".
        $value = json_decode((string) $row, true);

        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 'true'], true);
    }

    /**
     * Record a preference for one reader.
     *
     * The insert carries the creation time and the update does not, because
     * `updateOrInsert` hands the same attribute list to both and a preference
     * that was first recorded yesterday should still say so today. Nothing
     * reads the column today; it is written honestly so that something that
     * does read it later is not reading a lie.
     */
    public function write(User|int $user, string $key, bool $value): void
    {
        $userId = UserKey::of($user);

        $attributes = [
            'value' => json_encode($value),
            'updated_at' => now(),
        ];

        $existing = DB::connection()->table('user_settings')
            ->where('user_id', $userId)
            ->where('key', $key)
            ->exists();

        if ($existing) {
            DB::connection()->table('user_settings')
                ->where('user_id', $userId)
                ->where('key', $key)
                ->update($attributes);

            return;
        }

        DB::connection()->table('user_settings')->insert($attributes + [
            'user_id' => $userId,
            'key' => $key,
            'created_at' => now(),
        ]);
    }
}
