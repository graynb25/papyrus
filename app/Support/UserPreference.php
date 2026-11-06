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
 * ## What the value can be
 *
 * The column is JSON and the storage is generic. Most preferences are a yes or
 * a no; a locale is a short string. Both shapes go through the same reading and
 * writing here, so the SQL lives in one place rather than in each preference.
 * A preference that needs a date beside it can carry one in its own table, the
 * way a store's trust does.
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
        $value = $this->value($user, $key);

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 'true'], true);
    }

    /**
     * The stored string, or null when this reader has never set it.
     */
    public function readString(User|int $user, string $key): ?string
    {
        $value = $this->value($user, $key);

        return is_string($value) ? $value : null;
    }

    /**
     * Record a boolean preference for one reader.
     */
    public function write(User|int $user, string $key, bool $value): void
    {
        $this->writeValue($user, $key, $value);
    }

    /**
     * Record a string preference for one reader.
     */
    public function writeString(User|int $user, string $key, string $value): void
    {
        $this->writeValue($user, $key, $value);
    }

    /**
     * The stored JSON value, decoded, or null when the row is absent.
     */
    private function value(User|int $user, string $key): mixed
    {
        $userId = UserKey::of($user);

        $row = DB::connection()->table('user_settings')
            ->where('user_id', $userId)
            ->where('key', $key)
            ->value('value');

        if ($row === null) {
            return null;
        }

        return json_decode((string) $row, true);
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
    private function writeValue(User|int $user, string $key, bool|string $value): void
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
