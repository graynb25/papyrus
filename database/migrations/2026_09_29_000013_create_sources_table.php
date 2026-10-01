<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The source catalogue, mirrored from Suwayomi.
         *
         * Global, not user-owned: sources are a property of which extensions are
         * installed on the server, which is global. Per-user preferences live in
         * user_sources; health lives in source_health.
         *
         * suwayomi_source_id is a LongString and genuinely exceeds 2^53 in
         * practice - a live instance returned 8513894820944573933 - so it is
         * stored as a 64-bit column and never routed through JavaScript.
         */
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('suwayomi_source_id')->unique();
            $table->string('name');
            $table->string('display_name');
            $table->string('extension_pkg_name');

            /*
             * Region-qualified: verified values include "pt-BR" alongside plain
             * "en"/"ja". 16 chars is enough; do not assume two letters.
             */
            $table->string('lang', 16);

            // SAFE | MIXED | NSFW. Not a boolean - a source can be MIXED.
            $table->string('content_warning', 8)->default('SAFE');

            $table->string('home_url')->nullable();
            $table->string('icon_url')->nullable();

            // Both must be checked before calling, or the server throws a
            // stack-trace exception rather than returning an empty page.
            $table->boolean('supports_latest')->default(false);
            $table->boolean('is_configurable')->default(false);

            $table->boolean('is_installed')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['is_installed', 'lang']);
            $table->index(['is_installed', 'content_warning']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
