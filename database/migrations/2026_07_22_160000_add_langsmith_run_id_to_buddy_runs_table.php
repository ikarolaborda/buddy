<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_runs', function (Blueprint $table) {
            $table->string('langsmith_run_id')->nullable()->after('prompt_modules');
        });
    }

    public function down(): void
    {
        Schema::table('buddy_runs', function (Blueprint $table) {
            $table->dropColumn('langsmith_run_id');
        });
    }
};
