<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('parent_version')->nullable();
            $table->text('rationale');
            $table->text('expected_effect')->nullable();
            $table->json('payload');
            $table->string('status')->default('proposed');
            $table->timestamps();

            $table->index(['kind', 'status']);
        });

        Schema::create('evaluation_suites', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('kind');
            $table->json('cases');
            $table->boolean('frozen')->default(false);
            $table->timestamps();
        });

        Schema::create('evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('improvement_candidate_id')->constrained('improvement_candidates')->cascadeOnDelete();
            $table->foreignId('evaluation_suite_id')->constrained('evaluation_suites')->cascadeOnDelete();
            $table->json('baseline_metrics')->nullable();
            $table->json('candidate_metrics')->nullable();
            $table->boolean('passed')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promotion_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('improvement_candidate_id')->constrained('improvement_candidates')->cascadeOnDelete();
            $table->string('decided_by');
            $table->boolean('approved');
            $table->text('rationale')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_decisions');
        Schema::dropIfExists('evaluation_runs');
        Schema::dropIfExists('evaluation_suites');
        Schema::dropIfExists('improvement_candidates');
    }
};
