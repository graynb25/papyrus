<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An extension store registered with the server.
 *
 * Global, mirroring Suwayomi's own server-level store list. The per-user trust
 * decision lives in {@see UserExtensionStore}.
 *
 * @property int $id
 * @property string $index_url
 * @property string $name
 * @property string|null $badge_label
 * @property string|null $signing_key
 * @property string|null $contact_website
 * @property string|null $contact_discord
 * @property bool $is_legacy
 * @property string|null $extension_list_url
 * @property Carbon|null $added_at
 * @property Carbon|null $last_synced_at
 */
#[Fillable([
    'index_url',
    'name',
    'badge_label',
    'signing_key',
    'contact_website',
    'contact_discord',
    'is_legacy',
    'extension_list_url',
    'added_at',
    'last_synced_at',
])]
class ExtensionStore extends Model
{
    /**
     * @return HasMany<UserExtensionStore, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(UserExtensionStore::class);
    }

    /**
     * @return BelongsToMany<Source, $this>
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'source_extension_store')
            ->withPivot('created_at');
    }

    protected function casts(): array
    {
        return [
            'is_legacy' => 'boolean',
            'added_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function isProtobuf(): bool
    {
        return ! $this->is_legacy;
    }
}
