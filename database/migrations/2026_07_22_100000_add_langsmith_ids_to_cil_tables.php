<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_suites', function (Blueprint $table) {
            $table->string('langsmith_dataset_id')->nullable()->after('frozen');
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->string('langsmith_experiment_id')->nullable()->after('passed');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_suites', function (Blueprint $table) {
            $table->dropColumn('langsmith_dataset_id');
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->dropColumn('langsmith_experiment_id');
        });
    }
};
