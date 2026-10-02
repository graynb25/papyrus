<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One AniList connection per reader. The access token is the whole
         * credential: AniList's OAuth issues no refresh token, and the token it
         * does issue lives for about a year, so there is nothing to rotate and
         * nowhere to refresh from. When it dies, the reader connects again.
         */
        Schema::create('anilist_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('access_token');
            $table->unsignedBigInteger('anilist_user_id')->nullable();
            $table->string('anilist_user_name')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anilist_accounts');
    }
};
