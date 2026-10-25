<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One library check, from the press or the hourly schedule to whatever it ended as.
 *
 * ## Why this is a row rather than page state
 *
 * The progress a reader watches used to live in three Livewire properties. A
 * reload lost the fraction, lost the Stop control, and lost any record of what
 * the run had found, even though the job kept checking. A run outlives the page
 * that started it, so its state belongs in the database.
 *
 * ## Why a stopped run keeps its numbers
 *
 * Stopping is a reader saying "enough for now", not a request to undo what was
 * already fetched. Every completed series has already written its own
 * {@see LibraryCheck} row and moved its clock, so a cancelled run leaves those
 * untouched and simply stops asking. The counters here record how far it got,
 * which is what the page says when it reports the stop.
 *
 * ## Why a superseded run is failed rather than deleted
 *
 * Pressing Check again while an old row still says running means the old chain
 * is either dead or about to be. The new run marks it failed and finishes it,
 * so there is exactly one running row per reader and the library page never has
 * to choose between two.
 *
 * @property int $id
 * @property int $user_id
 * @property string $state running|complete|cancelled|failed
 * @property int $total
 * @property int $checked
 * @property int $new_chapters
 * @property int $failed
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $last_progress_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'state',
    'total',
    'checked',
    'new_chapters',
    'failed',
    'started_at',
    'last_progress_at',
    'finished_at',
    'cancelled_at',
])]
class CheckRun extends OwnedModel
{
    public const RUNNING = 'running';

    public const COMPLETE = 'complete';

    /** The reader pressed Stop. What was checked before the press is kept. */
    public const CANCELLED = 'cancelled';

    /** The chain ended without finishing, usually because no worker was serving it. */
    public const FAILED = 'failed';

    /**
     * How long a finished run stays on the page.
     *
     * Long enough that a reader who reloads during or just after a run still
     * sees what it found, short enough that yesterday's run is not presented as
     * today's news. The schedule summary already says whether anything is due,
     * so the box is only about this run.
     */
    public const VISIBLE_MINUTES = 60;

    /**
     * Cast because every counter is compared and summed, and SQLite hands them
     * back as strings without this.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'checked' => 'integer',
            'new_chapters' => 'integer',
            'failed' => 'integer',
            'started_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Start a run, ending any older one that still claims to be running.
     *
     * The update is one query rather than a loop because a reader can only have
     * one live chain, and anything else saying running is a row from a worker
     * that is gone. Marking it failed is the honest state: no worker will ever
     * move it again.
     */
    public static function begin(User|int $user, int $total): self
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        self::query()
            ->forUser($userId)
            ->where('state', self::RUNNING)
            ->update([
                'state' => self::FAILED,
                'finished_at' => now(),
            ]);

        return self::createFor($user, [
            'state' => self::RUNNING,
            'total' => max(0, $total),
            'checked' => 0,
            'new_chapters' => 0,
            'failed' => 0,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);
    }

    /**
     * The run the library page should report, if there is one worth showing.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('state', self::RUNNING)
                ->orWhere('finished_at', '>=', now()->subMinutes(self::VISIBLE_MINUTES));
        });
    }

    public function isRunning(): bool
    {
        return $this->state === self::RUNNING;
    }

    public function isCancelled(): bool
    {
        return $this->state === self::CANCELLED;
    }

    public function isComplete(): bool
    {
        return $this->state === self::COMPLETE;
    }

    public function isFailed(): bool
    {
        return $this->state === self::FAILED;
    }

    /**
     * How far the run got, as a whole percentage.
     *
     * Zero rather than a division error on a run that was started with nothing
     * due, which begin() refuses but a hand written row could still hold.
     */
    public function percent(): int
    {
        if ($this->total <= 0) {
            return 0;
        }

        return min(100, (int) round($this->checked / $this->total * 100));
    }
}
