<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('flyboard_upstream_users')) Schema::create('flyboard_upstream_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('subscribe_url');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_pulled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        if (!Schema::hasTable('flyboard_upstream_nodes')) Schema::create('flyboard_upstream_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upstream_user_id')->constrained('flyboard_upstream_users')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('address');
            $table->unsignedInteger('port')->nullable();
            $table->text('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['upstream_user_id', 'enabled']);
        });

        if (!Schema::hasTable('flyboard_access_logs')) Schema::create('flyboard_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('fingerprint', 64)->index();
            $table->string('action', 32);
            $table->string('reason', 64)->nullable();
            $table->string('path')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flyboard_access_logs');
        Schema::dropIfExists('flyboard_upstream_nodes');
        Schema::dropIfExists('flyboard_upstream_users');
    }
};
