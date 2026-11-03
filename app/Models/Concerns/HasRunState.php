<?php

namespace App\Models\Concerns;

use App\Live\Contracts\LiveRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The state every live run row shares, and the reader who pressed its button.
 *
 * The constants alias the contract's four strings so a model, the presenter and the jobs
 * cannot drift into two vocabularies. The columns keep their names: `state` stays the
 * database attribute, and `state()` is only the method the contract exposes beside it.
 */
trait HasRunState
{
    public const RUNNING = LiveRun::RUNNING;

    public const COMPLETE = LiveRun::COMPLETE;

    public const FAILED = LiveRun::FAILED;

    /** The run refused to take anything, with nothing changed. The sentence says why. */
    public const REFUSED = LiveRun::REFUSED;

    public function state(): string
    {
        return (string) $this->getAttribute('state');
    }

    /**
     * Whether the run is still going, so the page knows whether to poll.
     */
    public function isRunning(): bool
    {
        return $this->state() === self::RUNNING;
    }

    /** @return BelongsTo<User, *> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
