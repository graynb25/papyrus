<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Two tables for one feature, because they answer two different questions.
         *
         * Measured first, because both were tempting to skip.
         *
         * ## Nothing upstream records a migration
         *
         * Checked live: GraphQL exposes no migration field at all (Query 32 fields,
         * Mutation 78, Subscription 4), and no REST v1 endpoint exists either. The
         * capability is in the server and not on its API. So the wizard is ours to
         * compose, which means the record of what it did has to be ours too, and
         * "where did this series come from" has no other answer.
         */
        Schema::create('series_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('from_manga_id');
            $table->unsignedInteger('to_manga_id');

            /*
             * Source ids are LongString and exceed 2^53, so they are strings here for
             * the same reason they never go through JavaScript. Keeping both ends
             * means the record reads on its own, without a join, months later.
             */
            $table->string('from_source_id');
            $table->string('to_source_id');
            $table->string('from_source_name');
            $table->string('to_source_name');

            /*
             * The alignment report, kept because the reader was shown it.
             *
             * Measured: on a well-behaved series `chapterNumber` placed 41 of 41
             * chapters uniquely and `name` placed none, because the two sources label
             * them "Chapter 1" and "Ch. 1". So a transfer rests on one field, and a
             * series with a prologue or an extra is where that breaks. Storing the
             * counts means the series page can still say what happened after the
             * moment of migration has passed.
             */
            $table->unsignedSmallInteger('chapters_total')->default(0);
            $table->unsignedSmallInteger('chapters_carried')->default(0);

            /*
             * The chapters that found no counterpart, by label, so a reader who lost
             * read state on "Volume 3 Extra" can see which one it was. Capped in
             * length by the mapper, which keeps the report readable.
             */
            $table->json('unmatched_chapters')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'series_migrations_user_created_index');
            $table->index(['user_id', 'from_manga_id'], 'series_migrations_user_from_index');

            /*
             * One migration per reader per source series. A second attempt after a
             * failed one updates the row rather than accumulating history, because
             * the intermediate state never existed for the reader to care about.
             */
            $table->unique(['user_id', 'from_manga_id'], 'series_migrations_user_from_unique');
        });

        /*
         * The candidate search, which is too slow for a request and too big to
         * recompute.
         *
         * Measured: 81 sources searched one term each takes 21.9 seconds, p50 143ms
         * and a worst source of 1459ms, with zero failures. That is comfortably
         * inside a queue worker and uncomfortably inside a page request, so it runs
         * behind a job and the page polls.
         *
         * One row per attempt rather than one per candidate. The candidate list is
         * read once, shown once, and either used or discarded, so a table of them
         * would be a second copy of something that is derivable and stale the moment
         * a source's chapter list moves. A JSON column keeps it in one place and
         * makes the whole search droppable by deleting a row.
         */
        Schema::create('migration_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('from_manga_id');

            /*
             * searching, ready, or failed. The page polls on `searching` and renders
             * on the other two, which is why the state is a column rather than the
             * absence of candidates: a search that found nothing is `ready` with an
             * empty list, and a search that broke is `failed`, and those are
             * different things to a reader.
             */
            $table->string('state', 16)->default('searching');

            /*
             * Progress. Two columns rather than a percentage, because the wizard can
             * then say "searched 40 of 82 sources", which is honest and specific,
             * where a bar at 50% implies a duration it cannot promise.
             */
            $table->unsignedInteger('sources_searched')->default(0);
            $table->unsignedInteger('sources_total')->default(0);

            /*
             * The ranked candidates, as JSON. Each holds the measured signals rather
             * than a single blended score, so the page can show a reader why a
             * candidate is ranked where it is instead of asking them to trust an
             * ordering whose weights are invisible.
             */
            $table->json('candidates')->nullable();

            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'from_manga_id'], 'migration_searches_user_from_index');
            $table->index(['user_id', 'state'], 'migration_searches_user_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_searches');
        Schema::dropIfExists('series_migrations');
    }
};
