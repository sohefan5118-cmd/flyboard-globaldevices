<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('flyboard_upstream_users')) return;
        $columns = [
            'email' => fn (Blueprint $table) => $table->string('email')->nullable()->after('name'),
            'token' => fn (Blueprint $table) => $table->string('token', 512)->nullable()->after('subscribe_url'),
            'user_id' => fn (Blueprint $table) => $table->string('user_id', 128)->nullable()->after('token'),
            'uuid' => fn (Blueprint $table) => $table->string('uuid', 128)->nullable()->after('user_id'),
            'user_agent' => fn (Blueprint $table) => $table->string('user_agent', 512)->nullable()->after('uuid'),
            'upload' => fn (Blueprint $table) => $table->unsignedBigInteger('upload')->default(0)->after('user_agent'),
            'download' => fn (Blueprint $table) => $table->unsignedBigInteger('download')->default(0)->after('upload'),
            'transfer_enable' => fn (Blueprint $table) => $table->unsignedBigInteger('transfer_enable')->default(0)->after('download'),
            'expired_at' => fn (Blueprint $table) => $table->timestamp('expired_at')->nullable()->after('transfer_enable'),
            'last_node_count' => fn (Blueprint $table) => $table->unsignedInteger('last_node_count')->default(0)->after('expired_at'),
        ];
        foreach ($columns as $name => $add) {
            if (!Schema::hasColumn('flyboard_upstream_users', $name)) Schema::table('flyboard_upstream_users', $add);
        }
    }
    public function down(): void
    {
        Schema::table('flyboard_upstream_users', function (Blueprint $table) {
            foreach (['email','token','user_id','uuid','user_agent','upload','download','transfer_enable','expired_at','last_node_count'] as $column) {
                $table->dropColumn($column);
            }
        });
    }
};
