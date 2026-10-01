<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the last library check found, per reader.
         *
         * One row per reader per series, holding the outcome of the most recent
         * check rather than a history of them. That is a deliberate departure from
         * the downloads ledger, which has one row per reader per chapter because a
         * chapter's download is a thing that happens once. A check happens every day
         * forever, so keeping a history would mean a table that grows without bound
         * to answer a question the last answer already answers.
         *
         * This exists because Suwayomi keeps nothing about a check. Measured:
         * `fetchMangaAndChapters` re-reads the source and updates the server's own
         * chapter rows, and the only record that it happened is the `fetchedAt` on
         * those rows. Nothing says how many chapters arrived, and nothing says the
         * source had none, which is a different outcome from a source that failed.
         */
        Schema::create('library_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('suwayomi_manga_id');

            /*
             * Our own states, and `no_chapters` is the one that needs saying.
             *
             * Measured: `fetchMangaAndChapters` on a series whose source has nothing
             * throws a bare `java.lang.Exception: No chapters found`, delivered as a
             * GraphQL error on HTTP 200. That is not a fault, it is a source with
             * no chapters today, and folding it into `error` would make a working
             * source look broken and invite a reader to go looking for a problem.
             *
             * `skipped` is for a series the floor kept out of the pass, so the page
             * can say "6 of 20 due" rather than leaving the reader to wonder
             * whether the other 14 were visited and found fine.
             */
            $table->string('state', 16)->default('checked');

            /*
             * How many chapters the series has now, as the source counts them.
             *
             * This is the number that replaces the "Chapters never fetched" line,
             * and it is a count of the source's own rows rather than a sum of ours.
             */
            $table->unsignedSmallInteger('chapter_count')->nullable();

            /*
             * How many arrived since the previous check.
             *
             * The number a reader actually opened the page for. A series going from
             * 40 to 41 is "1 new"; a series that gained four is worth interrupting
             * someone for. Null on a reader's first check, because there is no
             * previous count to subtract and a zero would read as "nothing new",
             * which is a different claim.
             */
            $table->unsignedSmallInteger('new_chapters')->nullable();

            /*
             * Our error text, for the `error` state. There is no upstream error
             * detail to record, the same reason the downloads table has one: a
             * timeout or a rejected mutation is all we ever learn.
             */
            $table->text('error')->nullable();

            $table->timestamp('checked_at');

            $table->timestamps();

            /*
             * One row per reader per series, so a re-check updates rather than
             * accumulates. Leads with user_id because every read is scoped to a
             * reader: the page header asks how many are due, and the pass asks what
             * is left.
             */
            $table->unique(['user_id', 'suwayomi_manga_id'], 'library_checks_user_manga_unique');
            $table->index(['user_id', 'checked_at'], 'library_checks_user_checked_index');
            $table->index(['user_id', 'state'], 'library_checks_user_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_checks');
    }
};
