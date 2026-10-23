<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A note says what the work is doing, but its words are not a progress protocol. The
         * stage is the key LiveBackup already supplies with every note, retained so the modal can
         * report which work is done without trying to read prose back into data.
         */
        Schema::table('live_backup_runs', function (Blueprint $table) {
            $table->string('progress_stage')->nullable()->after('include_downloads');
        });
    }

    public function down(): void
    {
        Schema::table('live_backup_runs', function (Blueprint $table) {
            $table->dropColumn('progress_stage');
        });
    }
};
