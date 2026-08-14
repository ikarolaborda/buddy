<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_tasks', function (Blueprint $table): void {
            $table->json('knowledge_context')->nullable();
            $table->string('knowledge_context_status')->default('not_requested');
            $table->string('knowledge_context_hash', 64)->nullable();
            $table->timestamp('knowledge_context_fetched_at')->nullable();
            $table->text('knowledge_context_error')->nullable();
        });

        Schema::table('buddy_recommendations', function (Blueprint $table): void {
            $table->json('knowledge_hits')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('buddy_recommendations', function (Blueprint $table): void {
            $table->dropColumn('knowledge_hits');
        });

        Schema::table('buddy_tasks', function (Blueprint $table): void {
            $table->dropColumn([
                'knowledge_context',
                'knowledge_context_status',
                'knowledge_context_hash',
                'knowledge_context_fetched_at',
                'knowledge_context_error',
            ]);
        });
    }
};
