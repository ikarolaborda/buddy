<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('api_client_id')->nullable()->after('id');
            $table->string('operation')->default('evaluate')->after('problem_type');
            $table->unsignedInteger('state_version')->default(0)->after('status');
            $table->string('claimed_by')->nullable()->after('state_version');
            $table->timestamp('lease_expires_at')->nullable()->after('claimed_by');
            $table->timestamp('heartbeat_at')->nullable()->after('lease_expires_at');

            $table->index('api_client_id');
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('buddy_tasks', function (Blueprint $table) {
            $table->dropIndex(['api_client_id']);
            $table->dropIndex(['status', 'lease_expires_at']);
            $table->dropColumn([
                'api_client_id',
                'operation',
                'state_version',
                'claimed_by',
                'lease_expires_at',
                'heartbeat_at',
            ]);
        });
    }
};
