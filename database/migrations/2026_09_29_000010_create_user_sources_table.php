<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Per-user source preferences Suwayomi has nowhere to store. Pinning
         * and disabling in particular are client concerns: Suwayomi only knows
         * that a source exists, not which of its N users care about it.
         */
        Schema::create('user_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('source_id');

            $table->timestamp('pinned_at')->nullable();
            $table->boolean('disabled')->default(false);
            $table->boolean('incognito')->default(false);

            $table->boolean('migration_enabled')->default(false);
            $table->json('migration_flags')->nullable();
            $table->boolean('migration_deep_search')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sources');
    }
};
