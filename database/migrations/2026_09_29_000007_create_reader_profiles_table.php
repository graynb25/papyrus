<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Mihon's preference hierarchy is the part web clients most often
         * collapse to globals. A profile is a named set of reader settings a
         * user can switch between, layered under the
         * global -> category -> series -> profile -> session chain.
         */
        Schema::create('reader_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->json('settings');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reader_profiles');
    }
};
