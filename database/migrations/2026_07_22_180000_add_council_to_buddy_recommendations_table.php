<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_recommendations', function (Blueprint $table) {
            $table->json('council')->nullable()->after('refinement');
        });
    }

    public function down(): void
    {
        Schema::table('buddy_recommendations', function (Blueprint $table) {
            $table->dropColumn('council');
        });
    }
};
