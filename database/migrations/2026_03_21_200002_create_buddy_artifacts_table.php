<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buddy_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buddy_task_id')->constrained('buddy_tasks')->cascadeOnDelete();
            $table->string('type');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_artifacts');
    }
};
