<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Shelves for the library.
         *
         * A category is a shelf: a named, ordered place to put series. Many to
         * many with series, because "Reading soon" and "Webtoons" overlap, and a
         * series that can only be in one place forces a reader to choose and then
         * lose the other association.
         *
         * `order_column` rather than an `order` column because ORDER is a
         * reserved word on at least one engine and the migration should not care
         * which one runs it.
         */
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 128);
            $table->unsignedSmallInteger('order_column')->default(0);
            $table->timestamps();

            // One name per reader. Two shelves called "Reading soon" is a data
            // problem that surfaces as an unexplained split, not as an error.
            $table->unique(['user_id', 'name']);
        });

        /*
         * Membership.
         *
         * Keyed on series_state_id, so a real foreign key and belongsToMany both
         * work. The alternative is suwayomi_manga_id, which needs no series row to
         * exist, and that sounds more flexible until you notice it means no
         * referential integrity, no eager loading, and a hand written query
         * everywhere a relationship would do. Every shelved series is in the
         * library, and library membership lives on series_states, so the row is
         * always there.
         *
         * `added_at` is on the membership rather than on series_states because a
         * series can enter two shelves on different days, and "what did I add
         * last week" is a question about the shelf it is in.
         *
         * The unique pair makes filing the same series into the same shelf twice a
         * no-op at the storage layer rather than a check in PHP.
         */
        Schema::create('category_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('series_state_id')->constrained()->cascadeOnDelete();
            $table->timestamp('added_at')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'series_state_id'], 'category_series_pair_unique');

            /*
             * user_id is carried alongside the foreign keys rather than inferred,
             * because every read of this table is scoped to a user and an index
             * that leads with user_id is the one the query planner can use. The
             * global tenancy scope on the models would add that predicate anyway;
             * carrying the column means the index matches the query.
             */
            $table->index(['user_id', 'category_id'], 'category_series_shelf_index');
            $table->index(['user_id', 'series_state_id'], 'category_series_series_index');
        });

        /*
         * The home shelf.
         *
         * A series can be in several shelves but needs one place to live when the
         * reader has not chosen, and "Unfiled" has to be able to mean something.
         * Mihon models this the same way.
         *
         * Added as a column rather than a flag on the pivot so that "which is the
         * default" is one readable value on the series row instead of a partial
         * unique index and a query to find out. Deliberately NOT a foreign key:
         * a series can be removed from a shelf while that shelf was its default,
         * and a constraint would either block the removal or cascade the series
         * row away. {@see \App\Support\Library::forgetCategory()} clears it.
         */
        Schema::table('series_states', function (Blueprint $table) {
            $table->unsignedBigInteger('default_category_id')->nullable()->after('in_library');

            $table->index(
                ['user_id', 'default_category_id'],
                'series_states_default_category_index'
            );
        });

        /*
         * Chapter list fingerprint.
         *
         * Unread is `chapters.totalCount` from the source minus our own read rows,
         * which is exact while the source's chapter list is stable and silently
         * wrong when a source inserts, removes or renumbers chapters. Rather than
         * show a number we know might be wrong, the count is fingerprinted when we
         * see the list and the two are compared on the library page. A mismatch
         * says the list may be out of date instead of lying about it.
         *
         * Nullable, because a series in the library that has never been opened
         * has no list and so no fingerprint. That is different from a fingerprint
         * of "no chapters".
         */
        Schema::table('series_states', function (Blueprint $table) {
            $table->string('chapter_list_fingerprint', 64)->nullable();
            $table->timestamp('chapter_list_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('series_states', function (Blueprint $table) {
            $table->dropIndex('series_states_default_category_index');

            $table->dropColumn([
                'default_category_id',
                'chapter_list_fingerprint',
                'chapter_list_checked_at',
            ]);
        });

        Schema::dropIfExists('category_series');
        Schema::dropIfExists('categories');
    }
};
