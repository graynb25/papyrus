<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The title the run is comparing right now.
 *
 * The page's progress card says "compared 40 of 80" and Tachimanga's equivalent names the
 * series being looked up in the same line: the run is one request per series, and the one
 * in flight is the only thing that explains what the number is waiting on. The matcher
 * writes it before each compare rather than after, so a run that dies mid series leaves
 * the name of the one it died on rather than the name of the last one that survived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_migrations', function (Blueprint $table): void {
            $table->text('current_title')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bulk_migrations', function (Blueprint $table): void {
            $table->dropColumn('current_title');
        });
    }
};
