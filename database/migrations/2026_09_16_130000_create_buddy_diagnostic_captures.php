<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P7 diagnostic captures (plan §11, ADR 0013). The table stays empty while
 * BUDDY_EDGE_BROWSER_DIAGNOSTICS is false because the create route refuses
 * before it writes. Only a SHA-256 of the Worker callback token is stored, so
 * a database read never yields a usable completion credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buddy_diagnostic_captures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('buddy_task_id')->constrained('buddy_tasks')->cascadeOnDelete();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('request_id', 128);
            $table->string('request_hash', 64);
            $table->string('target_url', 2048);
            // Null when the URL was refused before a host could be parsed.
            $table->string('target_host')->nullable();
            $table->string('purpose', 500);
            $table->json('policy');
            $table->unsignedSmallInteger('capture_seconds');
            $table->string('status');
            // Null for denied captures: nothing was dispatched, so nothing may call back.
            $table->string('callback_token_hash', 64)->nullable();
            $table->json('result')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['buddy_task_id', 'request_id']);
            $table->index(['api_client_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_diagnostic_captures');
    }
};
