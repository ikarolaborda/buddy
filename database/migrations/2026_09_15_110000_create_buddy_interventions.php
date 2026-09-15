<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_runs', function (Blueprint $table) {
            $table->string('error_category')->nullable();
        });
        Schema::table('buddy_tasks', function (Blueprint $table) {
            $table->foreignId('recovery_of_task_id')->nullable()->unique()->constrained('buddy_tasks')->nullOnDelete();
        });
        Schema::create('buddy_interventions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('buddy_task_id')->constrained('buddy_tasks')->cascadeOnDelete();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('request_id', 128);
            $table->string('request_hash', 64);
            $table->string('action');
            $table->string('status');
            $table->json('context');
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['buddy_task_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_interventions');
        Schema::table('buddy_tasks', function (Blueprint $table) {
            $table->dropUnique(['recovery_of_task_id']);
            $table->dropConstrainedForeignId('recovery_of_task_id');
        });
        Schema::table('buddy_runs', function (Blueprint $table) {
            $table->dropColumn('error_category');
        });
    }
};
