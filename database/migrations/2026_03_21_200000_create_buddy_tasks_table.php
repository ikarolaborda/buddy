<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buddy_tasks', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('source_agent');
            $table->string('repo')->nullable();
            $table->string('branch')->nullable();
            $table->text('task_summary');
            $table->string('problem_type');
            $table->json('constraints')->nullable();
            $table->json('evidence')->nullable();
            $table->string('requested_outcome')->nullable();
            $table->string('status')->default('pending');
            $table->integer('attempt_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_tasks');
    }
};
