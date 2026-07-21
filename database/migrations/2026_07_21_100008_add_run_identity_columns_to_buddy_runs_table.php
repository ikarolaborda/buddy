<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_runs', function (Blueprint $table) {
            $table->string('run_type')->default('evaluation')->after('run_number');
            $table->string('provider')->nullable()->after('model_used');
            $table->string('prompt_hash', 64)->nullable()->after('provider');
            $table->json('prompt_modules')->nullable()->after('prompt_hash');
            $table->string('error_class')->nullable()->after('prompt_modules');
            $table->json('cost')->nullable()->after('token_usage');

            $table->unique(['buddy_task_id', 'run_number']);
        });
    }

    public function down(): void
    {
        Schema::table('buddy_runs', function (Blueprint $table) {
            $table->dropUnique(['buddy_task_id', 'run_number']);
            $table->dropColumn([
                'run_type',
                'provider',
                'prompt_hash',
                'prompt_modules',
                'error_class',
                'cost',
            ]);
        });
    }
};
