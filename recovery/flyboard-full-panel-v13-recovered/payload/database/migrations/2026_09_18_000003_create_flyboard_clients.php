<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('flyboard_clients')) Schema::create('flyboard_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('token', 128)->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('traffic_limit')->default(0);
            $table->unsignedBigInteger('traffic_used')->default(0);
            $table->unsignedInteger('device_limit')->default(1);
            $table->unsignedInteger('device_window')->default(86400);
            $table->unsignedBigInteger('access_count')->default(0);
            $table->timestamp('last_access_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('flyboard_access_logs') && !Schema::hasColumn('flyboard_access_logs', 'client_id')) Schema::table('flyboard_access_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->index()->after('user_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('flyboard_access_logs') && Schema::hasColumn('flyboard_access_logs', 'client_id')) {
            Schema::table('flyboard_access_logs', function (Blueprint $table) {
                $table->dropColumn('client_id');
            });
        }
        Schema::dropIfExists('flyboard_clients');
    }
};
