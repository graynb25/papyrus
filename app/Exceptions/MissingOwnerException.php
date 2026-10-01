<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A user-owned model was created with no resolvable owner.
 *
 * Replaces what would otherwise be a bare "NOT NULL constraint failed:
 * chapter_states.user_id" from deep inside a query, which gives no clue that
 * the real problem is a missing auth context or a missing createFor() call.
 */
class MissingOwnerException extends RuntimeException
{
    public static function for(string $modelClass): self
    {
        return new self(sprintf(
            'Cannot create %s without an owner: no user_id was set and there is no authenticated user. '
            .'Call %s::createFor($user, $attributes) when acting on behalf of another user.',
            class_basename($modelClass),
            $modelClass,
        ));
    }
}
