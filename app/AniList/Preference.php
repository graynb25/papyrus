<?php

namespace App\AniList;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Whether this reader wants their searches sent to a third party.
 *
 * ## Off by default, and that is the decision
 *
 * AniList's API is public and free, and every lookup sends a title the reader typed
 * to somebody else's server. For a hosted service that is a policy question. For a
 * self-hosted app it is closer to a privacy setting, because the person who deployed
 * the box is usually the person reading on it and may not expect their reading habits
 * to leave the machine.
 *
 * So the switch is per reader and starts off. A reader who turns it on gets the
 * cross-language dedupe; a reader who does not gets exactly the behaviour that shipped
 * before AniList existed, with no lookup attempted at all.
 *
 * ## Stored in `user_settings`
 *
 * That table has existed since the second migration with a unique
 * `(user_id, key)` and nothing reading it, so this is the first thing to use it. A
 * key rather than a column, because the next preference belongs beside this one and
 * adding a migration for every reader toggle is a worse answer than a key.
 *
 * The value is JSON in the column, and a plain boolean in the interface, because the
 * storage is generic and the meaning is not.
 */
class Preference
{
    public const KEY = 'anilist_matching';

    /**
     * Whether this reader has opted in.
     */
    public function enabled(User|int $user): bool
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        $row = DB::connection()->table('user_settings')
            ->where('user_id', $userId)
            ->where('key', self::KEY)
            ->value('value');

        if ($row === null) {
            return false;
        }

        // The column is JSON, so a stored `true` arrives as the string "1".
        $value = json_decode((string) $row, true);

        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 'true'], true);
    }

    /**
     * Turn it on or off for this reader.
     */
    public function set(User|int $user, bool $enabled): void
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        DB::connection()->table('user_settings')->updateOrInsert(
            ['user_id' => $userId, 'key' => self::KEY],
            [
                'value' => json_encode($enabled),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * What a reader should be told before turning it on.
     *
     * The honest version, because the alternative is a switch whose consequence is
     * discovered later: the titles they search are sent to a third party.
     */
    public function explanation(): string
    {
        return __('AniList is a public database of manga and anime with a free, keyless API. '
            .'Turning this on sends the titles you search to them, so that a series listed '
            .'under three different names in three languages is recognised as one. '
            .'Nothing else is sent, and the answers are cached so a repeated search is free.');
    }
}
