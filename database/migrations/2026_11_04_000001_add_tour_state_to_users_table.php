<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-account state for the first-run tour.
     *
     * `tour_step` is the resume point, 1-based. Zero means the tour has not
     * started. `tour_completed_at` covers both Done and Skip, because both mean
     * Papyrus should not open the tour on its own again. Replay clears both.
     *
     * Existing readers need no backfill: the tour only auto-starts for an
     * install owner whose library is empty, so a reader with data is already
     * excluded by the state check.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('tour_step')->default(0)->after('last_seen_at');
            $table->timestamp('tour_completed_at')->nullable()->after('tour_step');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['tour_step', 'tour_completed_at']);
        });
    }
};
