<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per attempt to read another reader app's backup.
         *
         * A run is kept even when it is refused or resolves nothing, because "I imported and
         * it did nothing" and "I imported and it worked" are different outcomes and a reader
         * needs to be able to see which happened. The counters live here rather than only in
         * the run's log because they are what a page polls while the import is going.
         */
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** 'tachimanga' today, 'mihon' when the protobuf reader lands. */
            $table->string('format', 32);

            /** The backup's own version, so a report can say what it read. */
            $table->unsignedInteger('schema_version')->nullable();

            /** The file's name, never its path: a path would leak the reader's disk layout. */
            $table->string('file_name', 255)->nullable();

            /** running | complete | failed | refused */
            $table->string('state', 16)->default('running');

            /**
             * A refusal or a failure, in the reader's words. Null for a run that worked,
             * and set for the two cases where a reader needs to know it did not import.
             */
            $table->text('refusal_reason')->nullable();

            $table->unsignedInteger('series_in_file')->nullable();
            $table->unsignedInteger('library_series')->nullable();
            $table->unsignedInteger('chapters')->nullable();

            $table->unsignedInteger('resolved')->default(0);
            $table->unsignedInteger('from_catalogue')->default(0);
            $table->unsignedInteger('from_source')->default(0);
            $table->unsignedInteger('sources_missing')->default(0);
            $table->unsignedInteger('not_found')->default(0);
            $table->unsignedInteger('ambiguous')->default(0);

            /**
             * Series on a source this server has no extension for. Kept apart from
             * `not_found` because it is the one unresolved outcome with a fix, and a reader
             * shown only a total cannot tell which of their missing series to go install
             * things for. Counted in series, not sources: `sources_missing` below counts the
             * sources to install, this counts what installing them would recover.
             */
            $table->unsignedInteger('series_on_missing_sources')->default(0);

            /** How many series this run actually wrote, which differs from resolved. */
            $table->unsignedInteger('applied')->default(0);

            /** How many were unchanged from a previous run and so were left alone. */
            $table->unsignedInteger('unchanged')->default(0);

            $table->unsignedInteger('read_chapters_applied')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        /*
         * The ledger that makes a re-import a delta rather than a second import.
         *
         * ## Why this table exists at all
         *
         * A reader will import again. Their phone backup changes, a source that was dead
         * last month comes back, chapters get read, series get added. If every run rewrote
         * everything then a second import would cost the same eleven minutes of upstream
         * calls as the first and would overwrite state the reader has since moved past.
         *
         * So each series is remembered by the identity both apps agree on, `(source, url)`,
         * together with a fingerprint of everything this importer would write for it. A
         * re-import compares fingerprints: equal means nothing to do, different means apply
         * the difference, absent means new.
         *
         * ## The fingerprint is what makes "unchanged" a real claim
         *
         * It covers the chapter rows this importer acts on, their read and bookmark flags,
         * their last page, plus the two dates and the resume point. It does not cover the
         * title, because a re-fetched title changing is not something to rewrite a library
         * over, and it does not cover a chapter count on its own, because a source that adds
         * a chapter adds an unread one and that needs no write.
         */
        Schema::create('imported_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();

            /**
             * A source id shared with Suwayomi, and past 2^53, so this is a bigint rather
             * than the integer a Suwayomi manga id uses. See trap 4.
             */
            $table->unsignedBigInteger('source_id');

            /** The source-relative path, normalised, which is the other half of the identity. */
            $table->string('source_url', 2048);

            /**
             * A hash of what would be written for this series, or null when the series has
             * never been resolved. A null fingerprint is what makes "we could not resolve
             * this" survive into the next run instead of being retried from nothing.
             */
            $table->string('fingerprint', 64)->nullable();

            /** The Suwayomi manga this resolved to. Null until it resolves. */
            $table->unsignedInteger('suwayomi_manga_id')->nullable();

            /** The backup's own manga id, which is local to that file and means nothing here. */
            $table->unsignedBigInteger('backup_manga_id')->nullable();

            $table->string('title', 512)->nullable();

            /** resolved | unresolved | ambiguous | missing_source */
            $table->string('state', 24)->default('unresolved');

            /** Why it did not resolve, for the report. */
            $table->text('reason')->nullable();

            /** How many times a run has looked at this and could not place it. */
            $table->unsignedInteger('attempts')->default(0);

            $table->unsignedInteger('chapters_seen')->default(0);
            $table->unsignedInteger('chapters_applied')->default(0);

            /*
             * The run that last saw this series. An id, so `foreignId` rather than
             * `timestamp`, which is what this column was declared as until it was read
             * properly: the name ends in `_id` and the line above it ends in `_at`, and
             * writing a migration by that pattern produced a datetime column holding a
             * run id.
             *
             * SQLite does not catch it. Measured: inserting `7` into a `timestamp` column
             * stores the integer 7 and `typeof` reports `integer`, so every write succeeded
             * and every read came back right. It is silent on the database this project
             * ships with and would be wrong on one that coerces. `foreignId` costs nothing
             * and says what the column holds, so the next reader is not misled.
             */
            $table->foreignId('last_seen_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();

            /*
             * The identity, per reader. This is what a re-import joins on, and the
             * uniqueness is what stops a second run filing the same series twice.
             */
            $table->unique(['user_id', 'source_id', 'source_url'], 'imported_series_identity');

            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'suwayomi_manga_id']);
        });

        /*
         * Which sources a run found missing, and which a reader has since installed.
         *
         * Kept rather than derived because "this source was gone when you imported, and is
         * still gone" is the answer a reader needs on the second run, and re-deriving it
         * means re-checking a repo every time for a fact that has probably not changed.
         */
        Schema::create('imported_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_run_id')->constrained('import_runs')->cascadeOnDelete();

            $table->unsignedBigInteger('source_id');

            /** The name the backup gave it, so the report can name it rather than a number. */
            $table->string('name')->nullable();

            /** The package name, which is what Suwayomi identifies an extension by. */
            $table->string('pkg_name', 255)->nullable();

            $table->unsignedInteger('series_count')->default(0);

            /** The backup marked it obsolete, which is a different thing from "not installed". */
            $table->boolean('obsolete')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_sources');
        Schema::dropIfExists('imported_series');
        Schema::dropIfExists('import_runs');
    }
};
