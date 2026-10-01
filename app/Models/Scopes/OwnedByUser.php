<?php

namespace App\Models\Scopes;

use App\Models\Concerns\OwnedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Constrains a model to the currently authenticated user.
 *
 * Fails closed. With no authenticated user - a console command, a queued job,
 * a test - the scope matches nothing rather than everything, so a missing auth
 * context can never leak one user's rows into another's view.
 *
 * Bypasses are always explicit:
 *
 *   Model::forUser($id)->get()      target one user explicitly
 *   Model::createFor($user, [...])  create for another user explicitly
 *   Model::forAllUsers()->get()     maintenance / diagnostics only
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class OwnedByUser implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model instanceof OwnedModel
            ? $model->getUserIdColumn()
            : 'user_id';

        $qualified = $model->getTable().'.'.$column;
        $userId = Auth::user()?->getAuthIdentifier();

        if ($userId === null) {
            $builder->whereNull($qualified);

            return;
        }

        $builder->where($qualified, $userId);
    }
}
