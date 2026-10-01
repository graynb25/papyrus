<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Suwayomi has no custom-cover concept whatsoever - ImageMutation only
         * exposes clearCachedImages. Uploads live on our own disk and the app
         * layer overrides thumbnailUrl wherever it renders a cover.
         */
        Schema::create('series_covers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('suwayomi_manga_id');
            $table->string('disk');
            $table->string('path');
            $table->string('origin', 16)->default('upload');
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'suwayomi_manga_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_covers');
    }
};
