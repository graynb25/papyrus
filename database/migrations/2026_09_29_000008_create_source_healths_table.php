<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Deliberately NOT user-scoped.
         *
         * Suwayomi exposes no per-source health, last-error, last-success or
         * rate-limit state anywhere in its schema, even though the server keeps
         * RateLimitInterceptor and CloudflareInterceptor internally. We build
         * the ledger ourselves by observing call outcomes (Suwayomi-Server#2269
         * has 13 comments and no fix upstream).
         *
         * Health describes the upstream, not the user, so this table is global
         * even in "public" deployment mode.
         */
        Schema::create('source_healths', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_id')->unique();
            $table->string('name');
            $table->string('lang', 16)->nullable();

            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedBigInteger('total_successes')->default(0);
            $table->unsignedBigInteger('total_failures')->default(0);
            $table->unsignedInteger('avg_latency_ms')->nullable();

            $table->boolean('is_dead')->default(false);
            $table->timestamp('marked_dead_at')->nullable();

            $table->timestamps();

            $table->index(['is_dead', 'last_success_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_healths');
    }
};
