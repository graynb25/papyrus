<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Everything Suwayomi cannot store about a series, per user.
         *
         * UpdateMangaPatchInput exposes only `inLibrary`, so reader mode,
         * notes, covers, scanlator exclusions and fetch intervals have nowhere
         * to live server-side. suwayomi_manga_id mirrors MangaType.id, which is
         * a 32-bit Int in the GraphQL schema.
         */
        Schema::create('series_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('suwayomi_manga_id');

            $table->boolean('in_library')->default(false);
            $table->timestamp('added_at')->nullable();
            $table->timestamp('favourited_at')->nullable();
            $table->timestamp('read_later_at')->nullable();

            $table->decimal('rating', 3, 1)->nullable();

            $table->json('tags')->nullable();
            $table->json('excluded_scanlators')->nullable();

            /*
             * Per-series reader preferences. Resolution order is
             * global -> category -> series -> session override.
             */
            $table->json('reader_settings')->nullable();
            $table->string('reading_mode', 32)->nullable();

            $table->json('chapter_list_settings')->nullable();

            /*
             * Mihon uses a negative interval to mean "user set this explicitly",
             * which suppresses its release-window skipping.
             */
            $table->smallInteger('fetch_interval_days')->nullable();
            $table->string('update_strategy', 32)->nullable();

            $table->unsignedInteger('last_read_chapter_id')->nullable();
            $table->timestamp('last_read_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'suwayomi_manga_id']);
            $table->index(['user_id', 'in_library']);
            $table->index(['user_id', 'last_read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_states');
    }
};
