<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Suwayomi addresses source preferences and browse filters by ordinal
         * position, not by key. Reordering a source's filter list silently
         * mis-targets writes (Suwayomi-Server#1110, and the server's own
         * unreleased fix for nested GroupFilter changes).
         *
         * We therefore snapshot the list and resolve position -> key at read
         * time, so a write always names the preference the user actually saw.
         *
         * Global, not user-scoped: the list itself is a property of the source.
         */
        Schema::create('source_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_id')->unique();
            $table->json('preferences');
            $table->json('filters')->nullable();
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_preferences');
    }
};
