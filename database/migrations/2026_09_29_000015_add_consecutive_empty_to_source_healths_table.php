<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_healths', function (Blueprint $table) {
            /*
             * Reachable-but-silent. A live dead source (Aqua Manga) answers every
             * request with zero results and no error, which is indistinguishable
             * from a healthy source that simply has nothing matching the query.
             * Counting empty responses separately is the only way to tell those
             * two cases apart.
             */
            $table->unsignedInteger('consecutive_empty')->default(0)->after('consecutive_failures');
        });
    }

    public function down(): void
    {
        Schema::table('source_healths', function (Blueprint $table) {
            $table->dropColumn('consecutive_empty');
        });
    }
};
