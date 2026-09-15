<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Additive foundation for the Cloudflare plan (P1-P3). Nothing here changes
 * existing rows or existing behavior: every column is nullable or defaulted,
 * and every new table stays empty until its feature flag is enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_tasks', function (Blueprint $table) {
            $table->string('phase')->nullable();
            $table->timestamp('phase_started_at')->nullable();
            $table->timestamp('phase_deadline_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('worker_started_at')->nullable();
            // Latest committed event sequence; distinct from state_version,
            // which guards ownership. Both advance only inside transactions.
            $table->unsignedInteger('progress_sequence')->default(0);
            // Execution generation: bumped by recovery so supervisors and
            // delegations bound to an older generation stop matching.
            $table->unsignedInteger('generation')->default(1);
            $table->string('failure_category')->nullable();
        });

        Schema::table('outbox_messages', function (Blueprint $table) {
            // Intended destinations are frozen at creation so a later flag
            // change cannot reinterpret pending rows (design review item 6).
            $table->json('destinations')->nullable();
            $table->timestamp('quarantined_at')->nullable();
        });

        Schema::create('buddy_task_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('buddy_task_id')->constrained('buddy_tasks')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->unsignedInteger('state_version');
            $table->unsignedInteger('generation')->default(1);
            $table->timestamp('occurred_at');
            $table->string('trace_id', 64)->nullable();
            $table->json('data');
            $table->timestamp('created_at')->nullable();

            $table->unique(['buddy_task_id', 'sequence']);
            $table->index(['type', 'occurred_at']);
        });

        Schema::create('outbox_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbox_message_id')->constrained('outbox_messages')->cascadeOnDelete();
            $table->string('destination', 64);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['outbox_message_id', 'destination']);
            $table->index(['destination', 'delivered_at', 'next_attempt_at']);
        });

        Schema::create('edge_inbox_records', function (Blueprint $table) {
            $table->id();
            $table->string('consumer', 64);
            $table->string('event_id', 64);
            $table->string('payload_hash', 64);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['consumer', 'event_id']);
        });

        Schema::create('buddy_view_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('buddy_task_id')->constrained('buddy_tasks')->cascadeOnDelete();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('ticket_hash', 64)->unique();
            $table->string('scope', 64);
            $table->unsignedInteger('generation')->default(1);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('buddy_view_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('buddy_view_ticket_id')->constrained('buddy_view_tickets')->cascadeOnDelete();
            $table->foreignId('buddy_task_id')->constrained('buddy_tasks')->cascadeOnDelete();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('session_hash', 64)->unique();
            $table->string('scope', 64);
            $table->string('origin')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('hard_expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['buddy_task_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_view_sessions');
        Schema::dropIfExists('buddy_view_tickets');
        Schema::dropIfExists('edge_inbox_records');
        Schema::dropIfExists('outbox_deliveries');
        Schema::dropIfExists('buddy_task_events');
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropColumn(['destinations', 'quarantined_at']);
        });
        Schema::table('buddy_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'phase',
                'phase_started_at',
                'phase_deadline_at',
                'queued_at',
                'worker_started_at',
                'progress_sequence',
                'generation',
                'failure_category',
            ]);
        });
    }
};
