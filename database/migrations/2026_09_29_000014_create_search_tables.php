<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Persisted results for a fan-out search, so partial results survive the
         * request that produced them and the UI can poll instead of holding a
         * request open across dozens of slow upstreams.
         */
        Schema::create('search_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('query', 512);
            $table->string('status', 16)->default('running');
            $table->unsignedInteger('sources_total')->default(0);
            $table->unsignedInteger('sources_done')->default(0);
            $table->unsignedInteger('sources_failed')->default(0);
            $table->unsignedInteger('result_count')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('search_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('suwayomi_manga_id');
            $table->unsignedBigInteger('suwayomi_source_id');
            $table->string('title', 512);
            $table->string('normalized_title', 512);
            $table->string('url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('source_name');
            $table->string('content_warning', 8)->default('SAFE');
            $table->string('status', 24)->nullable();
            $table->json('genre')->nullable();
            $table->timestamps();

            $table->index(['search_run_id', 'normalized_title'], 'search_results_run_title_index');
            $table->index(['search_run_id', 'suwayomi_source_id']);
        });

        /*
         * Per-source outcome for a run. This is the seed of the source health
         * ledger (H2): Suwayomi exposes no per-source health anywhere, so the
         * only way to know a source is dead is to record what happened when we
         * called it.
         */
        Schema::create('search_source_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('suwayomi_source_id');
            $table->string('source_name');
            $table->string('status', 16);
            $table->unsignedInteger('result_count')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 512)->nullable();
            $table->timestamps();

            $table->index(['search_run_id', 'suwayomi_source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_source_results');
        Schema::dropIfExists('search_results');
        Schema::dropIfExists('search_runs');
    }
};
