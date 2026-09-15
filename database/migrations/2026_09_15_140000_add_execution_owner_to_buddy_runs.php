<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_runs', function (Blueprint $table) {
            $table->string('execution_owner')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('buddy_runs', function (Blueprint $table) {
            $table->dropIndex(['execution_owner']);
            $table->dropColumn('execution_owner');
        });
    }
};
