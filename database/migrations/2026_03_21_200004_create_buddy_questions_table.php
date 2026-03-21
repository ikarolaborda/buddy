<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buddy_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buddy_task_id')->constrained('buddy_tasks')->cascadeOnDelete();
            $table->text('question');
            $table->text('context')->nullable();
            $table->boolean('answered')->default(false);
            $table->text('answer')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_questions');
    }
};
