<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per press of the Back up now button, so the run that keeps
         * the services going always has somewhere the page can look to show
         * what happened to the press: where it is moment by moment through the
         * note, and what it ended as.
         *
         * The rows are the whole install's, deliberately outside the ownership
         * shape the import rows use: a backup protects shared data, so its
         * record is not one reader's row. The person who pressed it is recorded
         * and can go away: deleting the account leaves the row standing with
         * the attribution erased, the same way the pair on disk survives.
         *
         * token, the counts and the summary are the finished run's receipt and
         * stay null while there is nothing to claim: zero series states is not
         * "no pair yet", it is a claim about a file that does not exist, and
         * null reads as "the run has not produced that" instead.
         */
        Schema::create('live_backup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token')->nullable();
            $table->string('state');
            $table->boolean('include_downloads');
            $table->string('progress_note')->nullable();
            $table->string('refusal_reason')->nullable();
            $table->string('suwayomi_version')->nullable();
            $table->unsignedBigInteger('downloads_bytes')->nullable();
            $table->unsignedInteger('series_states')->nullable();
            $table->unsignedInteger('chapter_states')->nullable();
            $table->json('manga_summary')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_backup_runs');
    }
};
