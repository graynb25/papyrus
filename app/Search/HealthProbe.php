<?php

namespace App\Search;

use App\Suwayomi\Gateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Support\Facades\Cache;

/**
 * One short-lived answer to "is Suwayomi answering right now".
 *
 * The health page's counts are a ledger of past observations, so they stay
 * readable when the server is down. That is exactly when the difference
 * matters, and probing on every render would turn every click into a wait.
 * One cached boolean for a minute keeps the page honest without making the
 * cache a source of health: a miss asks the server, a hit says what the last
 * ask found.
 */
class HealthProbe
{
    public const CACHE_KEY = 'health.suwayomi.reachable';

    public const CACHE_SECONDS = 60;

    /**
     * Enough for a loopback count, short enough that one miss cannot hold a
     * click for the default 28 second budget.
     */
    public const TIMEOUT_SECONDS = 2;

    public function __construct(private readonly Gateway $gateway) {}

    public function reachable(): bool
    {
        return (bool) Cache::remember(
            self::CACHE_KEY,
            self::CACHE_SECONDS,
            function (): bool {
                try {
                    $this->gateway->execute(
                        'query HealthProbe { sources { totalCount } }',
                        [],
                        self::TIMEOUT_SECONDS,
                    );
                } catch (SuwayomiException) {
                    return false;
                }

                return true;
            },
        );
    }
}
