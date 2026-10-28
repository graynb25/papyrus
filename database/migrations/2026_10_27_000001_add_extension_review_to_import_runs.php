<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an import show what it would install before it installs anything.
 *
 * ## Why the run row holds the review
 *
 * The page dispatches a survey, and the answer has to survive a closed modal, a reload,
 * and a worker that takes a minute to start. The run row is already the durable record of
 * an attempt, so the review lives beside its state rather than in a session or a queue
 * payload that a repeated click could replace.
 *
 * `file_path` is stored so a reader can confirm a review opened from history later. It is
 * never rendered: the run's `file_name` is the label, and a path says where the server
 * keeps things.
 *
 * ## The upload is not kept forever
 *
 * A review that is never confirmed would otherwise pin a reader's 40MB backup on disk
 * indefinitely. The state added with this column (`awaiting`) is expired after 24 hours by
 * {@see \App\Import\ImportReviewExpiry}, which deletes any stored upload. Confirm, import
 * without extensions, and discard delete it at the moment they are chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->string('file_path', 2048)->nullable()->after('file_name');

            /*
             * Store groups with their signing keys, the unresolved packages, the counts
             * already here, and the limit and force the reader chose. A snapshot, not a
             * permission: the install re-derives trust from the database when it runs.
             */
            $table->json('review')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'review']);
        });
    }
};
