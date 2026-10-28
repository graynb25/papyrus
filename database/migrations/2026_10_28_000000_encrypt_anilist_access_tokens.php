<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts the AniList bearer tokens that were stored in the clear.
 *
 * ## Why this is a data migration and not only a cast
 *
 * The model's `encrypted` cast covers values written after it exists. Every install that
 * connected AniList before this release already has a plaintext token in the column, and
 * the cast would throw on the first read rather than migrate it. The rows are read raw,
 * encrypted, and written back before the app serves a request.
 *
 * ## Why the up step is idempotent
 *
 * A failure partway through leaves earlier rows encrypted. Running the migration again
 * must finish the plaintext rows without encrypting the finished ones a second time, so a
 * value that already decrypts under this key is left as it is.
 *
 * ## Why the down step decrypts
 *
 * The inverse exists whenever the same `APP_KEY` is present, which is the only condition
 * under which the ciphertext was readable at all. If the key changed, the rollback fails
 * loudly rather than writing unreadable ciphertext into a column the previous release
 * expects to read as plaintext.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(fn (string $token): string => $this->isEncrypted($token)
            ? $token
            : Crypt::encryptString($token));
    }

    public function down(): void
    {
        $this->rewrite(fn (string $token): string => Crypt::decryptString($token));
    }

    /** Whether the value is a ciphertext this install can read. */
    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /**
     * Rewrite every token in place, one row at a time.
     *
     * @param  callable(string): string  $convert
     */
    private function rewrite(callable $convert): void
    {
        foreach (DB::table('anilist_accounts')->select('id', 'access_token')->orderBy('id')->get() as $row) {
            $token = (string) $row->access_token;
            $converted = $convert($token);

            if ($converted === $token) {
                continue;
            }

            DB::table('anilist_accounts')
                ->where('id', $row->id)
                ->update(['access_token' => $converted]);
        }
    }
};
