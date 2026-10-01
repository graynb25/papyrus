<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's relationship with an extension store, chiefly whether they trust it.
 *
 * Trust is per-user because it is a decision about what *this person* is willing
 * to execute on their machine. In "single" mode there is one user and this is
 * effectively global; in "shared" mode it genuinely differs.
 *
 * @property int $id
 * @property int $user_id
 * @property int $extension_store_id
 * @property CarbonImmutable|null $trusted_at
 * @property bool $is_pinned
 */
#[Fillable(['extension_store_id', 'trusted_at', 'is_pinned'])]
class UserExtensionStore extends OwnedModel
{
    /**
     * @return BelongsTo<ExtensionStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(ExtensionStore::class, 'extension_store_id');
    }

    protected function casts(): array
    {
        return [
            'trusted_at' => 'datetime',
            'is_pinned' => 'boolean',
        ];
    }

    public function isTrusted(): bool
    {
        return $this->trusted_at !== null;
    }
}
