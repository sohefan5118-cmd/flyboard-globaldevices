<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('flyboard_main_user_whitelist')) {
            Schema::create('flyboard_main_user_whitelist', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->primary();
                $table->boolean('enabled')->default(false);
                $table->string('note', 500)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (!Schema::hasTable('flyboard_main_user_whitelist_logs')) {
            Schema::create('flyboard_main_user_whitelist_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->string('action', 32);
                $table->string('reason', 64)->nullable();
                $table->string('ip', 64)->nullable();
                $table->string('user_agent', 512)->nullable();
                $table->string('path', 255)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['user_id', 'created_at'], 'fb_user_wl_logs_user_created_idx');
                $table->index(['action', 'created_at'], 'fb_user_wl_logs_action_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flyboard_main_user_whitelist_logs');
        Schema::dropIfExists('flyboard_main_user_whitelist');
    }
};
