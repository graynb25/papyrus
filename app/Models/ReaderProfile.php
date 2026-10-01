<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * A named set of reader settings a user can switch between.
 *
 * Resolution order is global -> category -> series -> profile -> session
 * override. Mihon models the first three tiers with bitfields on the manga row;
 * a normalised table per tier is easier to query and to migrate.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property bool $is_default
 * @property array<string, mixed> $settings
 */
#[Fillable(['name', 'is_default', 'settings'])]
class ReaderProfile extends OwnedModel
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'settings' => 'array',
        ];
    }
}
