<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Columns for a best-effort `label:value` search, and the measurements behind
         * each one.
         *
         * `search_results.author` is the one that is load-bearing. Measured across all 82
         * installed sources, only **15 offer an author filter**, so `author:` has to be
         * checked against the result afterwards on the other 67 or it would silently do
         * nothing there. That needs the author on the row, which means asking the search
         * request for it, which is one field in a request the fan-out was making anyway.
         *
         * `status` and `genre` were already here, and they cover 90% and 94% of sources
         * respectively, so those two are pushed wherever the source has them and checked
         * against these columns where it does not.
         *
         * `search_runs.text_query` and `search_runs.filters` exist so a run says what it
         * actually asked for. Without them a reader who typed `author:isang` and got three
         * results cannot tell whether the source had no such manga or the filter was
         * dropped, which is the difference between looking elsewhere and looking again.
         */
        Schema::table('search_results', function (Blueprint $table) {
            $table->string('author')->nullable()->after('genre');
        });

        Schema::table('search_runs', function (Blueprint $table) {
            // The free text actually sent upstream, with the `label:value` pairs removed.
            $table->string('text_query')->nullable()->after('query');

            // What was understood, as name to value. A reader can be told which of their
            // terms were honoured rather than silently ignored.
            $table->json('filters')->nullable()->after('text_query');
        });
    }

    public function down(): void
    {
        Schema::table('search_results', function (Blueprint $table) {
            $table->dropColumn('author');
        });

        Schema::table('search_runs', function (Blueprint $table) {
            $table->dropColumn(['text_query', 'filters']);
        });
    }
};
