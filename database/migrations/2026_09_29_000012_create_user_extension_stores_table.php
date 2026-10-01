<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_extension_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_store_id')->constrained()->cascadeOnDelete();
            $table->timestamp('trusted_at')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'extension_store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_extension_stores');
    }
};
