<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per press of the export button, the outward sibling of
         * live_backup_runs: what the run is doing moment by moment through the
         * note, what collection map and read marks it merged, and what product
         * file landed for another reader app to take in.
         *
         * The rows are the whole install's, deliberately outside the ownership
         * shape the import rows use, the way the backup rows are: the file on
         * disk outlives whoever asked for it. The person who pressed it is
         * recorded and can go away, deleting the account leaves the row standing
         * with the attribution erased.
         *
         * The receipt numbers stay null while there is nothing to claim, so
         * zero never reads as "no run yet", it reads as "this run produced
         * nothing there" instead.
         */
        Schema::create('live_export_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_name')->nullable();
            $table->string('state');
            $table->string('progress_note')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->unsignedBigInteger('file_bytes')->nullable();
            $table->unsignedInteger('series_in_file')->nullable();
            $table->unsignedInteger('series_planned')->nullable();
            $table->unsignedInteger('series_matched')->nullable();
            $table->unsignedInteger('shelves_appended')->nullable();
            $table->unsignedInteger('read_marks')->nullable();
            $table->json('skipped')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_export_runs');
    }
};
