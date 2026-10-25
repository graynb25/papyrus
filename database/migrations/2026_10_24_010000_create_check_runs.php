<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per library check a reader can watch, whether they pressed the
         * button or the hourly schedule started it.
         *
         * ## Why this is a row and not page state
         *
         * The progress used to live in three Livewire properties, so a reload
         * mid-check forgot the run entirely: no fraction, no Stop control, and
         * no record of what it had found. A run outlives the page that started
         * it, so the row is the state.
         *
         * ## The counters are written per series
         *
         * `checked` and `new_chapters` move as each series finishes rather than
         * at the end of a pass, because a pass is a slice of up to 60 series and
         * a reader watching a bar deserves a number that reflects the work already
         * done. `failed` counts series the source did not answer for, so the
         * finished sentence does not call a run complete while hiding them.
         *
         * `total` is fixed at the start. A denominator that follows the due count
         * down would make the bar always full.
         *
         * `last_progress_at` is what tells a slow worker from a dead queue. It is
         * touched on every series, not on every pass, because a pass can spend 45
         * seconds on one stubborn source.
         *
         * `cancelled_at` is separate from `state` so a Stop press is recorded
         * even if the worker never gets to the row again: the job checks the flag
         * between series, and the page can call the run stopped immediately.
         */
        Schema::create('check_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('state');
            $table->unsignedInteger('total');
            $table->unsignedInteger('checked')->default(0);
            $table->unsignedInteger('new_chapters')->default(0);
            $table->unsignedInteger('failed')->default(0);

            $table->timestamp('started_at');
            $table->timestamp('last_progress_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'state']);
            $table->index(['user_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_runs');
    }
};
