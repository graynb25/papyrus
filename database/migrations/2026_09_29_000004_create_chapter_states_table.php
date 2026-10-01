<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Read state lives here rather than in Suwayomi because Suwayomi is
         * single-tenant: every authenticated request resolves to Admin(1), so
         * its read state cannot be per-user even if we asked for it.
         *
         * last_page_read is the only page-level granularity Suwayomi persists;
         * read_seconds is ours alone.
         */
        Schema::create('chapter_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('suwayomi_chapter_id');
            $table->unsignedInteger('suwayomi_manga_id');

            $table->boolean('is_read')->default(false);
            $table->boolean('is_bookmarked')->default(false);
            $table->unsignedInteger('last_page_read')->default(0);
            $table->unsignedInteger('read_seconds')->default(0);
            $table->timestamp('last_read_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'suwayomi_chapter_id']);
            $table->index(['user_id', 'suwayomi_manga_id']);
            $table->index(['user_id', 'last_read_at']);
            $table->index(['user_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapter_states');
    }
};
