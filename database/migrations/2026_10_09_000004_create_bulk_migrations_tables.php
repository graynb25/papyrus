<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt to move a reader's whole shelf from one source to another.
 *
 * ## Why this is not just a loop over the existing wizard
 *
 * The per series wizard at `/migrate/{manga}` moves one series after the reader chooses one
 * candidate. A reader whose source went away has the opposite problem: dozens of series that
 * all need the same decision made the same way. Running the wizard by hand across 80 series
 * is not a workflow, it is a chore nobody finishes.
 *
 * ## Why each series is a row and not a column
 *
 * Because the reader approves matches individually and each one can then fail on its own. A
 * run that moved 40 of 60 and refused 20, for 20 different reasons, is the normal outcome and
 * not an edge case. Keeping the per series verdict in its own row is what lets the page say
 * which 20 and why, rather than reporting one number and losing the detail.
 *
 * The comparison numbers are stored rather than recomputed on render, because they were
 * measured once at search time and the sources move under a reader's feet. Re-reading them at
 * render time would show a reader numbers for a different moment than the ones they approved.
 *
 * ## Approval is not stored as a flag
 *
 * There is no `approved` column. The reader ticks a box in their browser and the move happens
 * in the same request, so an approval that was written down and not acted on would be a claim
 * that something happened when it did not. The `status` column records what did happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /**
             * Suwayomi source ids, as strings because they exceed 2^53. The names are kept
             * alongside so a report can name a source rather than print a number, which is the
             * same reasoning as `imported_sources.name`.
             */
            $table->string('from_source_id', 64);
            $table->string('to_source_id', 64);
            $table->string('from_source_name')->nullable();
            $table->string('to_source_name')->nullable();

            /** running | ready | failed */
            $table->string('state', 16)->default('running');

            /**
             * Why it could not finish. A reader who asked for 80 matches and got none, and a
             * reader whose server was down, are different situations and must not read the
             * same.
             */
            $table->text('error')->nullable();

            /** How many of the reader's library sit on the source being left. */
            $table->unsignedInteger('series_total')->default(0);

            /** How many have been compared against the destination so far. */
            $table->unsignedInteger('series_checked')->default(0);

            /** How many ended with a candidate the reader could be offered. */
            $table->unsignedInteger('series_matched')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('bulk_migration_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_migration_id')->constrained('bulk_migrations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** The series being moved off the old source. */
            $table->unsignedInteger('from_manga_id');

            /** The series on the new source, or null when nothing matched. */
            $table->unsignedInteger('to_manga_id')->nullable();

            /**
             * What the reader calls this series here, so the row stands on its own in a report
             * after the migration has moved the library out from under it.
             */
            $table->string('title', 512);

            /** The destination's own title, which is how a reader tells two entries apart. */
            $table->string('to_title', 512)->nullable();

            /** The comparison, as measured at search time. See the migration docblock. */
            $table->unsignedInteger('chapters_here')->nullable();
            $table->unsignedInteger('chapters_there')->nullable();
            $table->decimal('title_similarity', 5, 4)->nullable();
            $table->integer('chapter_delta')->nullable();
            $table->decimal('author_overlap', 5, 4)->nullable();

            /**
             * pending  a candidate was found and the reader has not decided
             * moved    the reader approved it and it moved
             * skipped  no candidate was found, so there is nothing to approve
             * refused  the reader approved it and the migrator declined
             * failed   the search for this one series could not finish
             *
             * A reader who unticked everything still leaves rows, and they say `pending`. That
             * is different from `skipped`, which means the destination does not have the series
             * at all, and conflating them would report a decision as a fact about the source.
             */
            $table->string('status', 16)->default('pending');

            /** Why it is not moving. Always present for refused, failed and skipped. */
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->index(['bulk_migration_id', 'status']);
            $table->index(['user_id', 'from_manga_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_migration_items');
        Schema::dropIfExists('bulk_migrations');
    }
};
