<?php

namespace Tests\Feature;

use App\Models\AniListAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The upgrade path for an install that connected AniList before the encrypted cast.
 *
 * The cast only covers values written after it exists. A plaintext row written by the
 * previous release has to be encrypted before the app serves a request, or the first read
 * throws instead of decrypting. The migration is called directly here because the suite
 * has already run it against an empty table; inserting a plaintext row and calling it
 * again is the shape an existing install sees.
 */
class AniListTokenMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_encrypts_an_existing_plaintext_token_and_can_put_it_back(): void
    {
        $user = User::factory()->create();

        DB::table('anilist_accounts')->insert([
            'user_id' => $user->id,
            'access_token' => 'plaintext-token-from-before-the-cast',
            'anilist_user_id' => 4242,
            'anilist_user_name' => 'Reader',
            'token_expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_28_000000_encrypt_anilist_access_tokens.php');

        $migration->up();

        $stored = (string) DB::table('anilist_accounts')->where('user_id', $user->id)->value('access_token');

        $this->assertNotSame(
            'plaintext-token-from-before-the-cast',
            $stored,
            'the upgrade encrypts the row rather than leaving the bearer token in the column',
        );
        $this->assertSame(
            'plaintext-token-from-before-the-cast',
            Crypt::decryptString($stored),
            'the encrypted value is the token the old release wrote',
        );
        $this->assertSame(
            'plaintext-token-from-before-the-cast',
            AniListAccount::forUser($user->id)->first()->access_token,
            'the model hands callers the plaintext token after the upgrade',
        );

        /*
         * A failure partway through leaves the first row encrypted and a later one
         * plaintext. The retry has to finish the second without touching the first.
         */
        $second = User::factory()->create();

        DB::table('anilist_accounts')->insert([
            'user_id' => $second->id,
            'access_token' => 'plaintext-token-added-during-the-retry',
            'anilist_user_id' => 4343,
            'anilist_user_name' => 'Second reader',
            'token_expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame(
            $stored,
            (string) DB::table('anilist_accounts')->where('user_id', $user->id)->value('access_token'),
            'a retry leaves an already encrypted row alone rather than encrypting it twice',
        );
        $this->assertSame(
            'plaintext-token-from-before-the-cast',
            AniListAccount::forUser($user->id)->first()->access_token,
            'the retry still decrypts the first token exactly once',
        );

        $secondStored = (string) DB::table('anilist_accounts')->where('user_id', $second->id)->value('access_token');

        $this->assertNotSame(
            'plaintext-token-added-during-the-retry',
            $secondStored,
            'the retry encrypts the row the first pass did not reach',
        );
        $this->assertSame(
            'plaintext-token-added-during-the-retry',
            Crypt::decryptString($secondStored),
            'the second row is encrypted with the same key',
        );

        $migration->down();

        $this->assertSame(
            'plaintext-token-from-before-the-cast',
            (string) DB::table('anilist_accounts')->where('user_id', $user->id)->value('access_token'),
            'the rollback restores the first plaintext token',
        );
        $this->assertSame(
            'plaintext-token-added-during-the-retry',
            (string) DB::table('anilist_accounts')->where('user_id', $second->id)->value('access_token'),
            'the rollback restores the second plaintext token',
        );
    }
}
