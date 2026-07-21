<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->string('agent');
            $table->string('content_hash', 64);
            $table->json('module_ids');
            $table->json('module_hashes');
            $table->timestamps();

            $table->unique(['agent', 'content_hash']);
        });

        Schema::create('prompt_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_version_id')->constrained('prompt_versions')->cascadeOnDelete();
            $table->string('activated_by')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('timeout')->default(120);
            $table->unsignedInteger('max_steps')->default(10);
            $table->decimal('temperature', 3, 2)->default(0.2);
            $table->boolean('active')->default(false);
            $table->timestamps();

            $table->unique(['name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_profiles');
        Schema::dropIfExists('prompt_deployments');
        Schema::dropIfExists('prompt_versions');
    }
};
