<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('buddy_task_id')->nullable();
            $table->string('status')->default('quarantined');
            $table->text('problem');
            $table->text('solution');
            $table->text('impact')->nullable();
            $table->json('tags')->nullable();
            $table->json('evidence')->nullable();
            $table->json('technology_versions')->nullable();
            $table->json('source_references')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('promoted_memory_id')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();

            $table->index('buddy_task_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_candidates');
    }
};
