<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Phase 2 of the migration parity plan: a move now asks what it may carry
     * before it moves, and the row records what was asked rather than only what
     * the engine did.
     *
     * The four choices sit beside the counts the page already rendered, because
     * the completion summary reads choices as well as outcomes: "as you chose"
     * is only sayable when the choice is on the row.
     *
     * History gets the answer of the move that made it: everything carried,
     * nothing asked of AniList, downloads kept. Those rows told the truth too,
     * because carrying everything and keeping downloads is what they did.
     */
    public function up(): void
    {
        Schema::table('series_migrations', function (Blueprint $table) {
            $table->boolean('carried_chapters')->default(true)->after('unmatched_chapters');
            $table->boolean('carried_shelves')->default(true)->after('carried_chapters');
            $table->boolean('tracking_requested')->default(false)->after('carried_shelves');
            $table->boolean('remove_downloads')->default(false)->after('tracking_requested');

            $table->unsignedSmallInteger('downloads_removed')->default(0)->after('remove_downloads');
            $table->unsignedSmallInteger('downloads_cancelled')->default(0)->after('downloads_removed');
            $table->unsignedSmallInteger('downloads_unremoved')->default(0)->after('downloads_cancelled');
        });
    }

    public function down(): void
    {
        Schema::table('series_migrations', function (Blueprint $table) {
            $table->dropColumn([
                'carried_chapters',
                'carried_shelves',
                'tracking_requested',
                'remove_downloads',
                'downloads_removed',
                'downloads_cancelled',
                'downloads_unremoved',
            ]);
        });
    }
};
