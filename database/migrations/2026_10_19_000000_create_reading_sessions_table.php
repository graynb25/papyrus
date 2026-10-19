<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per chapter open: a session is the period between a reader
         * opening a chapter and the next open they make. Every open makes a new
         * row rather than extending the old one, because resume-over-reload must
         * not be able to weld two visits into one unbreakable row.
         *
         * The writes ride seams that already exist: chapter open (one INSERT per
         * navigation) and the debounced progress ping (one UPDATE only while a
         * scroll is moving the highest page). Nothing accrues on a timer, so a
         * tab left open in the background grows nothing.
         *
         * seconds and pages are server truth: elapsed since the last stamp and
         * the positive page deltas between stamps, both capped by the code that
         * accrues them, and the reader's own timestamps are never trusted.
         * Incognito sources write nothing anywhere in this table.
         */
        Schema::create('reading_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('suwayomi_manga_id');
            $table->unsignedInteger('suwayomi_chapter_id');

            $table->timestamp('started_at');
            $table->timestamp('last_active_at');
            $table->timestamp('ended_at')->nullable();

            $table->unsignedInteger('read_seconds')->default(0);
            $table->unsignedInteger('pages_turned')->default(0);
            $table->unsignedInteger('last_page_seen')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'ended_at']);
            $table->index(['user_id', 'suwayomi_manga_id', 'started_at']);
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_sessions');
    }
};
