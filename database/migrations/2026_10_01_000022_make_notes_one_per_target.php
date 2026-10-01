<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One note per target, as a database fact rather than a convention.
         *
         * Migration 5 gave `notes` a polymorphic target and no uniqueness, so nothing
         * stopped a second note landing on the same chapter. Whether that matters is a
         * design decision rather than a schema one, and the decision is that a
         * per-chapter note is a memo and not a journal: Mihon's per-chapter `memo` is a
         * single field, and a reader fixing a typo wants one save, not two entries.
         *
         * So the index does three things at once. It makes "one note per chapter"
         * true whatever the application code does, it lets the write path be a single
         * upsert instead of a read-then-write that races itself on a double submit, and
         * it turns two readers saving at once from two notes into one.
         *
         * Safe to add on live data: measured 0 rows, because nothing had ever written
         * a note. Had there been duplicates this would have needed a merge pass first,
         * which is worth knowing before adding the same index anywhere else.
         *
         * The three columns and not `(user_id, noteable_id)`: ids collide across types,
         * so without the type a note on chapter 7 and a note on series 7 would share a
         * key and one would overwrite the other.
         */
        Schema::table('notes', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'noteable_type', 'noteable_id'],
                'notes_user_noteable_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropUnique('notes_user_noteable_unique');
        });
    }
};
