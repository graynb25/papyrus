<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * `sources_skipped` counts the sources a run never asked.
         *
         * A term is pushed where the source has a matching filter and the source is
         * skipped where it does not. Measured across all 82 installed sources, `genre`
         * reaches 77, `status` 74 and `sort` 69, so a filtered run leaves 5 to 13 sources
         * unasked depending on the term.
         *
         * That number has to be on the run rather than summed from the per-source rows,
         * because the page reads it while the run is still going and the rows arrive one
         * job at a time. It also makes the cost of a term visible next to the results it
         * produced, which is the only way a reader can tell a narrow filter from a broken
         * one.
         *
         * This replaces nothing. The column is added rather than a row updated because
         * the runs already on disk were counted under the earlier design and there is no
         * honest way to backfill them: nothing recorded which sources were skipped,
         * because the earlier design asked every source and filtered the rows instead.
         */
        Schema::table('search_runs', function (Blueprint $table) {
            $table->unsignedInteger('sources_skipped')->default(0)->after('sources_failed');
        });
    }

    public function down(): void
    {
        Schema::table('search_runs', function (Blueprint $table) {
            $table->dropColumn('sources_skipped');
        });
    }
};
