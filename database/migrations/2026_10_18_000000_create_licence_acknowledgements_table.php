<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The acknowledgement ledger behind the licences page.
 *
 * ## Why a table rather than session or local state
 *
 * What a reader has read owes its life to neither the browser nor the session: the licence page is
 * about trusting what an install shipped, and the only thing that makes the pills true forever is a
 * row written with the identity the read covered. A package that moves version or licence in a later
 * build must not borrow the old pill, which is why the identity lives beside the acknowledgement and
 * is compared, never trusted.
 *
 * ## Why one row per reader per package
 *
 * A package appears on the page by one key at a time and the reader has either read it or not, so a
 * history of acknowledgements would only ever be read for the newest row. The unique key holds that
 * shape at the database level, and {@see \App\Models\LicenceAcknowledgement::markFor()} re-stamps
 * identity instead of appending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licence_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /** The page's text key: `composer:<name>`, `npm:<path>`, `bundled:<slug>`. */
            $table->string('package');

            /** The identity under the row when the read happened, compared for the rearm state. */
            $table->string('version');
            $table->string('licence');
            $table->timestamp('acknowledged_at');

            $table->unique(['user_id', 'package']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_acknowledgements');
    }
};
