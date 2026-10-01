<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One table for both series notes and chapter notes. Mihon's per-chapter
         * `memo` field is an extension author extension point with no user
         * interface at all, so a real user-facing note is free differentiation.
         *
         * Note targets are our own models (SeriesState, ChapterState), never
         * Suwayomi rows.
         */
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('noteable');
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'noteable_type', 'noteable_id'], 'notes_user_noteable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
