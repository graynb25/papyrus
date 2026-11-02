<?php

namespace App\Support;

use App\Models\User;
use InvalidArgumentException;

/**
 * One spelling for "the user id this value names".
 *
 * The tree carried three: a bare `$user->getKey()`, an `(int) $user->getKey()`,
 * and `$user->id`. They are not equivalent. The cast normalises a string key,
 * the bare form leaves it a string, and `->id` reads an attribute rather than
 * the model key. A numeric string that reaches a query through the bare form is
 * bound as a string, which SQLite tolerates in a comparison and a stricter
 * database would not, so the difference is a correctness hazard rather than a
 * style preference.
 *
 * The key is always positive. Ids come from an auto-incrementing primary key
 * that starts at 1, so a zero can only arrive from an unsaved model or a value
 * that was never an id. Letting it through would turn that mistake into a query
 * which silently matches no rows instead of naming the bug.
 */
final class UserKey
{
    public static function of(User|int|string $user): int
    {
        if ($user instanceof User) {
            $id = $user->getKey();

            if ($id === null) {
                throw new InvalidArgumentException('An unsaved user has no key to resolve.');
            }
        } else {
            $id = $user;
        }

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw new InvalidArgumentException('A user key must be an integer or a numeric string.');
        }

        $id = (int) $id;

        if ($id <= 0) {
            throw new InvalidArgumentException('A user key must be positive.');
        }

        return $id;
    }
}
