<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buddy_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buddy_run_id')->constrained('buddy_runs')->cascadeOnDelete();
            $table->boolean('accepted');
            $table->string('confidence');
            $table->text('summary');
            $table->json('recommended_plan')->nullable();
            $table->json('rejected_reasons')->nullable();
            $table->json('required_followups')->nullable();
            $table->json('risks')->nullable();
            $table->json('next_actions')->nullable();
            $table->json('memory_hits')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_recommendations');
    }
};
