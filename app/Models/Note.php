<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A user's note on a series or a chapter.
 *
 * Mihon's per-chapter `memo` is an extension-author extension point with no
 * user interface, so a real user-facing note on either target is free
 * differentiation. Markdown is rendered client-side.
 *
 * @property int $id
 * @property int $user_id
 * @property string $noteable_type
 * @property int $noteable_id
 * @property string $body
 */
#[Fillable(['noteable_type', 'noteable_id', 'body'])]
class Note extends OwnedModel
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function noteable(): MorphTo
    {
        return $this->morphTo();
    }
}
