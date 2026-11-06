<?php

namespace App\Support;

use App\Models\User;
use InvalidArgumentException;

/**
 * The reader's chosen interface language.
 *
 * A key in `user_settings`, not a column on `users`. The table already exists,
 * its value column is JSON, and the preference is one short string. Adding a
 * migration for the next preference is the shape the table was created to
 * avoid.
 */
final class LocalePreference
{
    public const KEY = 'locale';

    public function __construct(
        private readonly UserPreference $preferences,
    ) {}

    /**
     * The stored locale, or null when the reader has never chosen one or the
     * stored value has no catalog.
     */
    public function read(User|int $user): ?string
    {
        $value = $this->preferences->readString($user, self::KEY);
        $normalized = Locale::normalize($value);

        if ($normalized === null || ! in_array($normalized, Locale::catalogued(), true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Record a locale for one reader.
     */
    public function write(User|int $user, string $locale): void
    {
        $normalized = Locale::normalize($locale);

        if ($normalized === null || ! in_array($normalized, Locale::catalogued(), true)) {
            throw new InvalidArgumentException("The locale [{$locale}] is not catalogued.");
        }

        $this->preferences->writeString($user, self::KEY, $normalized);
    }

    /**
     * Adopt a catalogued session locale when the account has no usable preference.
     *
     * A stored uncatalogued value reads as absent, so a current catalogued
     * choice can replace it. An existing catalogued preference is never
     * overwritten.
     */
    public function adopt(User|int $user, ?string $locale): void
    {
        $normalized = Locale::normalize($locale);

        if ($normalized === null || ! in_array($normalized, Locale::catalogued(), true)) {
            return;
        }

        if ($this->read($user) !== null) {
            return;
        }

        $this->write($user, $normalized);
    }
}
