<?php

namespace App\Support;

use App\Models\User;
use InvalidArgumentException;

/**
 * Deployment mode is configuration, not schema.
 *
 * "single" is one user with registration switched off - not a different
 * database shape. Every user-owned table carries user_id regardless, so
 * promoting a self-hosted instance to shared or public is an env change plus
 * whatever the target mode adds (quotas, abuse controls), never a migration.
 */
final class Deployment
{
    public const SINGLE = 'single';

    public const SHARED = 'shared';

    public const PUBLIC = 'public';

    public function mode(): string
    {
        $mode = (string) config('papyrus.deployment_mode', self::SINGLE);

        if (! in_array($mode, [self::SINGLE, self::SHARED, self::PUBLIC], true)) {
            throw new InvalidArgumentException(
                "PAPYRUS_DEPLOYMENT_MODE must be one of single, shared, public. Got [{$mode}]."
            );
        }

        return $mode;
    }

    public function isSingle(): bool
    {
        return $this->mode() === self::SINGLE;
    }

    public function isShared(): bool
    {
        return $this->mode() === self::SHARED;
    }

    public function isPublic(): bool
    {
        return $this->mode() === self::PUBLIC;
    }

    /** Only "single" runs without registration. */
    public function allowsRegistration(): bool
    {
        return ! $this->isSingle();
    }

    /** Quotas are meaningless when there is exactly one user. */
    public function enforcesQuotas(): bool
    {
        return ! $this->isSingle();
    }

    public function quotaFor(User $user): ?int
    {
        return $this->enforcesQuotas() ? $user->storage_quota_bytes : null;
    }
}
