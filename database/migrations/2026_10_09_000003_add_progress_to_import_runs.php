<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a page show an import while it is happening.
 *
 * ## Why this exists
 *
 * The counters this adds next to sit in `import_runs`, and the migration that made that table
 * says in terms that they are "what a page polls while the import is going". No page did, so
 * nothing polled them and the only route in was a command that printed to a terminal. Then a
 * page did get built, and it found that the run row was written once at the end: a run of
 * 1,277 series is about eleven minutes of upstream calls at the measured 520ms a series, and
 * for eleven minutes a polling page would have shown a run that had started and nothing else.
 *
 * So the run row now carries a progress line of its own, written as the importer goes, and the
 * tallies beside it stay written once at the end where they mean what they say.
 *
 * ## Why progress is separate from the tallies
 *
 * `applied` and `unchanged` are final answers. Writing them every 25 series would make a run
 * that died at 60% claim it had applied 60% of the library, with no way to tell that apart from
 * a run that finished. `series_processed` is only ever compared against `library_series`, which
 * is written before the loop starts, so a partial number is always legible as partial.
 *
 * `progress_note` is the line the importer would have printed to a terminal. Free text on
 * purpose: it is a human sentence that changes shape as the run moves from reading the file to
 * installing extensions to resolving series, and no set of counters would carry that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            /**
             * Series looked at so far, against `library_series` for the total. Only ever
             * meaningful as a fraction, which is why nothing sums it or reports it alone.
             */
            $table->unsignedInteger('series_processed')->default(0)->after('library_series');

            /**
             * The importer's own sentence, so a page shows what a terminal would have shown.
             * Null until the run reports something, which is not the same as a run with nothing
             * to say.
             */
            $table->text('progress_note')->nullable()->after('series_processed');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn(['series_processed', 'progress_note']);
        });
    }
};
