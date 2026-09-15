<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_tasks', function (Blueprint $table) {
            $table->string('council_profile')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('buddy_tasks', function (Blueprint $table) {
            $table->dropColumn('council_profile');
        });
    }
};
