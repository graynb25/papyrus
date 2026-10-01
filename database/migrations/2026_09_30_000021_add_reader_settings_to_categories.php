<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Per-shelf reader settings, the third of four resolution layers.
         *
         * `ReaderSettings` already resolved defaults, then the reader's default
         * profile, then the series. The shelf layer was the one gap, and it is the
         * layer that earns its keep: a shelf called "Night" or "On the train" is a
         * statement about how everything in it should read, and a reader with 300
         * series does not want to visit each one to say so.
         *
         * A JSON map rather than columns, because the shape is owned by
         * `ReaderSettings` and adding a colour filter later should not need a
         * migration. Null means "this shelf says nothing", which is a different state
         * from an empty object and is what lets an unset key fall through to the layer
         * below.
         */
        Schema::table('categories', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('name');
        });

        /*
         * Per-shelf notes, for H18.
         *
         * The plan's "per-chapter notes" and a shelf description are the same shape
         * of feature, and a reader who writes a note about a shelf is writing it for
         * the same reason they write one about a chapter. Kept here rather than in a
         * second table because there is nothing a shelf note needs that a chapter note
         * does not.
         */
        Schema::table('categories', function (Blueprint $table) {
            $table->text('note')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['settings', 'note']);
        });
    }
};
