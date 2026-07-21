<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_memory_references', function (Blueprint $table) {
            $table->string('memory_id')->nullable()->after('qdrant_point_id');
            $table->string('backend')->default('legacy_qdrant')->after('memory_id');
            $table->string('project')->nullable()->after('backend');
            $table->string('revision')->nullable()->after('project');
            $table->string('memory_status')->nullable()->after('revision');
            $table->text('use_rationale')->nullable()->after('memory_status');
        });
    }

    public function down(): void
    {
        Schema::table('buddy_memory_references', function (Blueprint $table) {
            $table->dropColumn([
                'memory_id',
                'backend',
                'project',
                'revision',
                'memory_status',
                'use_rationale',
            ]);
        });
    }
};
