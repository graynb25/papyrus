<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per press of a row's Restore action, mirroring live_backup_runs so the
         * page can read both run families in one language: the stage note moment by
         * moment, and the finished receipt after.
         *
         * The rows are the whole install's, deliberately outside the ownership shape the
         * import rows use, for the reason LiveBackupRun's migration names: a restore puts
         * shared data back, so its record is not one reader's row. The person who pressed
         * it is recorded and can go away with that attribution.
         *
         * One column is added and one is dropped against the backup table's shape. The
         * addition is restore_summary, the import report the server answers with once its
         * side is done. The drop is include_downloads, which names nothing here: a restore
         * does not carry the downloads zip, and a column that is always false would read
         * as a claim about what the run did.
         *
         * token, the counts and the two summaries are the finished run's receipt and stay
         * null while there is nothing to claim: zero series states is not "no pair yet",
         * it is a claim about a pair that restored an empty catalogue, and null reads as
         * "the run has not produced that" instead.
         */
        Schema::create('live_restore_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token')->nullable();
            $table->string('state');
            $table->string('progress_note')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->string('suwayomi_version')->nullable();
            $table->unsignedInteger('series_states')->nullable();
            $table->unsignedInteger('chapter_states')->nullable();
            $table->json('manga_summary')->nullable();
            $table->json('restore_summary')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_restore_runs');
    }
};
