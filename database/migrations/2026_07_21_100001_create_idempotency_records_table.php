<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('idempotency_key');
            $table->string('request_hash');
            $table->unsignedBigInteger('buddy_task_id')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['api_client_id', 'idempotency_key']);
            $table->index('buddy_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
