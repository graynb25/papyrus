<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a series exist when Suwayomi cannot hold one for us.
 *
 * ## Why a series row needs to exist without a Suwayomi manga
 *
 * An import of a real backup resolves 1,082 of 1,277 series and cannot resolve 195, because
 * they sit on 7 sources whose sites have shut down. A reader's decision about those is not
 * usually "throw the titles away": the titles are what they recognise. So they are filed, with
 * no source behind them, and the library page shows them as unavailable rather than omitting
 * them.
 *
 * ## Three columns, and each is load-bearing
 *
 * **`suwayomi_manga_id` becomes nullable.** It is the key the rest of the app joins on, and it
 * is `unsignedInteger NOT NULL`. A row for a series Suwayomi has never heard of has no such id,
 * and 0 is not a stand-in: `Library::entries()` batches every id into one
 * `mangas(filter: {id: {in: [...]}})` call, and a 0 in that list is a real query for a manga
 * that does not exist. Null is the honest value and the two batch call sites now filter on it.
 *
 * SQLite and MySQL both treat NULLs as distinct in a unique index, so
 * `unique(user_id, suwayomi_manga_id)` still holds and any number of filed-only rows can
 * coexist for one reader.
 *
 * **`title`, because there is nowhere else to get it.** Measured and deliberate: the library
 * page fetches titles from Suwayomi for every series in one batched call, precisely so that no
 * local metadata cache is needed. A series the server does not know has no title in that
 * response, and a library card with no title is not a card a reader can recognise. This is the
 * one piece of series metadata stored locally, and only for rows that have no server to ask.
 *
 * **`unavailable_reason`, so "why is this here and empty" is answerable.** A reader who sees a
 * title they cannot open deserves to be told the source has gone rather than being left to
 * wonder whether it is a loading failure.
 *
 * ## And one on the ledger
 *
 * **`imported_series.series_state_id`** is how filing stays idempotent. The ledger row is keyed
 * `(user_id, source_id, source_url)` and is updated in place on every run, so it is the natural
 * place to remember which local row a series was filed as. Keying the filing on the title
 * instead would collide on any two series sharing one, and keying it on the Suwayomi id is
 * impossible, which is the whole reason this column exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series_states', function (Blueprint $table) {
            $table->unsignedInteger('suwayomi_manga_id')->nullable()->change();

            $table->string('title', 512)->nullable();
            $table->string('unavailable_reason', 512)->nullable();
        });

        Schema::table('import_runs', function (Blueprint $table) {
            /*
             * Series filed with no source behind them.
             *
             * Its own column rather than being folded into `applied`, because it means the
             * opposite thing: `applied` is a series that arrived, this is a series that is
             * present as a title and nothing more. A reader reading "1,082 applied, 195 filed"
             * learns something a reader reading "1,277 applied" does not.
             */
            $table->unsignedInteger('filed_without_source')->default(0)->after('applied');
        });

        Schema::table('imported_series', function (Blueprint $table) {
            $table->foreignId('series_state_id')
                ->nullable()
                ->after('suwayomi_manga_id')
                ->constrained('series_states')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn('filed_without_source');
        });

        Schema::table('imported_series', function (Blueprint $table) {
            $table->dropConstrainedForeignId('series_state_id');
        });

        Schema::table('series_states', function (Blueprint $table) {
            $table->dropColumn(['title', 'unavailable_reason']);

            // Cannot be NOT NULL again: rows exist that would violate it.
            $table->unsignedInteger('suwayomi_manga_id')->nullable(false)->change();
        });
    }
};
