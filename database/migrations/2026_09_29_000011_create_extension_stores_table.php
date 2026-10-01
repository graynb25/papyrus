<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Extension stores are SERVER truth, not user truth. Suwayomi holds one
         * global store list; `user_extension_stores` carries the per-user trust
         * decision, which genuinely differs between people in shared mode.
         */
        Schema::create('extension_stores', function (Blueprint $table) {
            $table->id();
            $table->text('index_url');
            $table->string('name');
            $table->string('badge_label')->nullable();

            /*
             * The repo's signing key fingerprint. This *is* the trust model:
             * extensions from an untrusted store cannot be installed. Recorded
             * so the UI can show what the user is being asked to trust.
             */
            $table->text('signing_key')->nullable();

            $table->string('contact_website')->nullable();
            $table->string('contact_discord')->nullable();

            /*
             * Verified live against Suwayomi: a protobuf index (Keiyoushi) comes
             * back isLegacy=false. The legacy JSON index is the other format.
             */
            $table->boolean('is_legacy')->default(false);
            $table->text('extension_list_url')->nullable();

            $table->timestamp('added_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('index_url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_stores');
    }
};
