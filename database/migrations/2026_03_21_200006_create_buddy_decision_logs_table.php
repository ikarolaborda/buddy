<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buddy_decision_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buddy_task_id')->constrained('buddy_tasks')->cascadeOnDelete();
            $table->foreignId('buddy_run_id')->nullable()->constrained('buddy_runs')->nullOnDelete();
            $table->string('decision_type');
            $table->text('rationale');
            $table->json('evidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_decision_logs');
    }
};
