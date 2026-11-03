<?php

namespace App\Live\Contracts;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The state vocabulary a live backup, restore and export row shares.
 *
 * The three tables stay separate because their receipts carry different columns. This
 * contract is the part presentation reads, so a fourth live run can be shown without
 * widening a union type at every call site.
 *
 * @phpstan-require-extends Model
 *
 * @property string $state
 * @property string|null $progress_note
 * @property string|null $refusal_reason
 * @property CarbonInterface $updated_at
 */
interface LiveRun
{
    /** The run is still working. */
    public const RUNNING = 'running';

    /** The run produced its receipt. */
    public const COMPLETE = 'complete';

    /** The run stopped on an error, and the trace belongs in failed_jobs. */
    public const FAILED = 'failed';

    /** The run changed nothing, and refusal_reason says why. */
    public const REFUSED = 'refused';

    /** The state column, read through one method rather than three attribute reads. */
    public function state(): string;

    /** Whether the run is still going, so the page knows whether to poll. */
    public function isRunning(): bool;

    /** @return BelongsTo<User, *> */
    public function startedBy(): BelongsTo;
}
