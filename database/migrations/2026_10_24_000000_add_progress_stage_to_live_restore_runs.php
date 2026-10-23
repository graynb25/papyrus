<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The restore sentence is for the reader, but the modal needs a stable key to know
         * which checkpoints are complete. Keep the key beside the sentence, as the live backup
         * run already does, rather than trying to infer progress from prose.
         */
        Schema::table('live_restore_runs', function (Blueprint $table): void {
            $table->string('progress_stage')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('live_restore_runs', function (Blueprint $table): void {
            $table->dropColumn('progress_stage');
        });
    }
};
