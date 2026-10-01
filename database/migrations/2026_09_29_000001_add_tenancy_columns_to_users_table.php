<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('password');
            $table->string('status')->default('active')->after('role');
            $table->unsignedBigInteger('storage_quota_bytes')->nullable()->after('status');
            $table->timestamp('last_seen_at')->nullable()->after('storage_quota_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'status', 'storage_quota_bytes', 'last_seen_at']);
        });
    }
};
