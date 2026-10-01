<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What happened to a download, for one reader.
         *
         * This exists because Suwayomi keeps nothing. Measured: once a chapter
         * leaves `downloadStatus.queue` it is gone, and the only lasting trace is
         * `ChapterType.isDownloaded`. There is no error text anywhere in the
         * schema either, so a `DownloadState.ERROR` arrives with no reason
         * attached. Anything a download centre shows beyond "something is
         * happening right now" has to be written down here.
         *
         * Per reader even though the downloader is global. The queue is shared;
         * the intent is not.
         */
        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('suwayomi_chapter_id');
            $table->unsignedInteger('suwayomi_manga_id');

            /*
             * Our own state, not Suwayomi's enum verbatim.
             *
             * `failed` exists because the server reports ERROR with no reason,
             * and `cancelled` because a reader who removes a download wants that
             * recorded as a decision rather than as a failure. Collapsing all three
             * into "error" would make a deliberate cancellation look like a fault.
             */
            $table->string('state', 16)->default('queued');

            /*
             * A fraction, because that is what the server reports. Measured: it
             * steps once per page, so on a 28 page chapter the step is 1/28.
             */
            $table->float('progress')->default(0);

            $table->timestamp('enqueued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            /*
             * Page count, learned from the chapter after it downloads.
             *
             * Undownloaded chapters report `pageCount: -1`, so this is null until
             * a download completes, and it is the reason a download is worth
             * having beyond the images: a download is what makes a chapter's
             * length knowable at all.
             */
            $table->unsignedSmallInteger('page_count')->nullable();

            /*
             * Our error text. The server has none to give, so this is written from
             * what we could observe: a timeout, a rejected mutation, or a chapter
             * that left the queue without becoming downloaded.
             */
            $table->text('error')->nullable();

            $table->timestamps();

            /*
             * One row per reader per chapter, so re-enqueueing is an update rather
             * than a second row. The composite index leads with user_id because
             * every read of this table is scoped to a reader and the reconciler
             * asks for "my unfinished ones" on every sync.
             */
            $table->unique(['user_id', 'suwayomi_chapter_id'], 'downloads_user_chapter_unique');
            $table->index(['user_id', 'state'], 'downloads_user_state_index');
            $table->index(['user_id', 'suwayomi_manga_id'], 'downloads_user_manga_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};
