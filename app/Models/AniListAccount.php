<?php

namespace App\Models;

use App\Models\Concerns\OwnedModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * One reader's AniList connection.
 *
 * The token in this row is what lets the app write reading progress to AniList
 * on that reader's behalf, which is a bigger grant than the keyless lookups the
 * search page makes: those only read public data, this one acts as the reader.
 * It is why the row exists at all and why the token is never rendered anywhere.
 *
 * The column holds ciphertext through Laravel's `encrypted` cast, so a database
 * copy or a backup does not yield a usable bearer token without the install's
 * `APP_KEY`. The model decrypts it when a caller reads the attribute, and
 * `$hidden` keeps it out of serialized output.
 *
 * Owned by a user, unlike {@see AniListMedia}, because a token is not reference
 * data. It is one person's authorisation.
 *
 * @property int $id
 * @property int $user_id
 * @property string $access_token
 * @property int|null $anilist_user_id
 * @property string|null $anilist_user_name
 * @property CarbonImmutable|null $token_expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'access_token',
    'anilist_user_id',
    'anilist_user_name',
    'token_expires_at',
])]
class AniListAccount extends OwnedModel
{
    protected $table = 'anilist_accounts';

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'anilist_user_id' => 'integer',
            'token_expires_at' => 'datetime',
        ];
    }
}
