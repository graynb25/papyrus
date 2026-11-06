<?php

namespace App\Models;

use App\Support\LocalePreference;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $role
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int $tour_step
 * @property Carbon|null $tour_completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The role that owns the install. Kept as `admin` because `users:create --admin` already
     * writes it and the column predates this ability.
     */
    public const ROLE_INSTALL_OWNER = 'admin';

    /**
     * The role every other account carries.
     */
    public const ROLE_READER = 'user';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'tour_step' => 'integer',
            'tour_completed_at' => 'datetime',
        ];
    }

    /**
     * The language this reader chose, for notifications built outside their request.
     *
     * A password reset starts while the reader is signed out, so the request
     * locale is the guest's, not the account's. Laravel's notification sender
     * reads this contract and localizes the verification and reset messages
     * for the recipient.
     */
    public function preferredLocale(): ?string
    {
        return app(LocalePreference::class)->read($this);
    }

    /**
     * Whether this account may manage the install itself.
     *
     * Reads the existing role column rather than a second owner flag, so there is one fact
     * about who owns the install and no way for two flags to disagree.
     */
    public function isInstallOwner(): bool
    {
        return $this->role === self::ROLE_INSTALL_OWNER;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
