<?php

namespace App\Models\Concerns;

use App\Exceptions\MissingOwnerException;
use App\Models\Scopes\OwnedByUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Base class for every user-owned model.
 *
 * An abstract class rather than a trait, deliberately: PHPStan cannot reason
 * about the model instance inside a trait's `creating` closure, and tenancy
 * that static analysis cannot see is tenancy that quietly rots.
 *
 * user_id is never mass-assignable, so user-supplied input can never write a
 * row on someone else's behalf. To create a row for another user, call
 * {@see self::createFor()} - an explicit, greppable decision.
 *
 * @phpstan-consistent-constructor
 */
abstract class OwnedModel extends Model
{
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new OwnedByUser);

        static::creating(function (self $model): void {
            $column = $model->getUserIdColumn();

            if (! empty($model->{$column})) {
                return;
            }

            $userId = Auth::user()?->getAuthIdentifier();

            if ($userId === null) {
                throw MissingOwnerException::for($model::class);
            }

            $model->{$column} = $userId;
        });
    }

    /** The column holding the owning user's id. Override only if renamed. */
    public function getUserIdColumn(): string
    {
        return 'user_id';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a row owned by a specific user, bypassing the current auth
     * context. Required whenever a job, command, or admin action acts for
     * someone else.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createFor(User|int $user, array $attributes = []): static
    {
        $model = new static;

        $model->setAttribute(
            $model->getUserIdColumn(),
            $user instanceof User ? $user->getKey() : $user,
        );

        $model->fill($attributes);
        $model->save();

        return $model;
    }

    /**
     * Target a specific user without changing auth context.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query
            ->withoutGlobalScope(OwnedByUser::class)
            ->where(
                $this->qualifyColumn($this->getUserIdColumn()),
                $user instanceof User ? $user->getKey() : $user,
            );
    }

    /**
     * Remove the scope for a single query.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutUserScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OwnedByUser::class);
    }

    /**
     * Maintenance and diagnostics only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForAllUsers(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OwnedByUser::class);
    }
}
