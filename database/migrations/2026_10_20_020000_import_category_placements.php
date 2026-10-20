<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a backup's categories land, recorded beside what the import did.
 *
 * The landing itself goes into the shelves that already exist (`categories` and
 * `category_series`): a category is a shelf in this project's own vocabulary, and a second
 * table holding the same placement would have been a copy to keep in step. What these
 * columns add is the receipt.
 *
 * **`import_runs`** carries the counters, so the run report can say how many members a
 * shelf gained without re-deriving it from the pivot: members landed and members not.
 * Null, not 0, for a run that predates this or never reached a file with categories, the
 * same distinction the other diffable counters here draw.
 *
 * **`imported_series.categories`** is the per-item half: the name(s) the backup itself
 * filed that series into, carried from `CategoryManga` where the file links it. The row's
 * `state` and `reason` already say whether the series resolved; with this beside them the
 * one row answers "it was in `Snowflakes` and it did not resolve", which is a per-item
 * placement report nobody has to join anything else to read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->unsignedInteger('categories_in_file')->nullable()->after('series_on_missing_sources');
            $table->unsignedInteger('category_members_placed')->nullable()->after('categories_in_file');
            $table->unsignedInteger('category_members_skipped')->nullable()->after('category_members_placed');
        });

        Schema::table('imported_series', function (Blueprint $table) {
            $table->json('categories')->nullable()->after('backup_manga_id');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn(['categories_in_file', 'category_members_placed', 'category_members_skipped']);
        });

        Schema::table('imported_series', function (Blueprint $table) {
            $table->dropColumn('categories');
        });
    }
};
