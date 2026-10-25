<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Reading time that arrived inside another app's backup.
         *
         * ## Why this is not reading_sessions
         *
         * A sitting here has a chapter, a start and an end, and was recorded by this
         * server. A day in a Tachimanga backup has none of those: `Stats` is a daily
         * total per series, `History.read_duration` is a lifetime total with one date.
         * Writing either as a session would invent a chapter and a start time the
         * reader never had, and it would mix a total the reader did not produce here
         * into the table that exists to be truthful about what this server saw.
         *
         * So imported time is its own ledger with the coarsest grain the file offers:
         * one series, one day, a number of seconds. Insights adds the two ledgers for
         * its total and still says which part came from a backup.
         *
         * ## Why the backup's hash is part of the key
         *
         * The same backup imported twice is the same reading time, so a re-import must
         * replace that file's rows rather than add a second copy. The hash answers
         * "which file"; the file name does not, because two exports can share one.
         *
         * Two exports of the same phone overlap: February's file contains January's
         * days. The rows are kept per file so neither import destroys the other, and
         * {@see \App\Support\ReadingInsights} takes the largest value for a series and
         * day across files, so an overlapping day is counted once. That is also why
         * `day` is in the unique key rather than the seconds being accumulated.
         *
         * `seconds` is unsigned because a negative duration is not a reading fact; the
         * reader drops those rows before they reach here.
         */
        Schema::create('imported_reading_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('backup_key', 64);
            $table->unsignedInteger('suwayomi_manga_id');
            $table->date('day');
            $table->unsignedInteger('seconds');

            $table->timestamps();

            $table->unique(
                ['user_id', 'backup_key', 'suwayomi_manga_id', 'day'],
                'imported_reading_days_identity',
            );
            $table->index(['user_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_reading_days');
    }
};
