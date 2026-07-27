<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * requested_outcome was VARCHAR(255) while both callers advertised far more:
 * the REST contract allows max:2000 and the MCP tool schema advertised an
 * unbounded string. Anything longer than 255 passed validation and then died
 * at the insert, surfacing to MCP callers as an opaque "Tool execution
 * failed." The column now holds what the contract promises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_tasks', function (Blueprint $table) {
            $table->text('requested_outcome')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('buddy_tasks', function (Blueprint $table) {
            $table->string('requested_outcome')->nullable()->change();
        });
    }
};
