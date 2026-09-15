<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Additive storage metadata for R2-backed artifacts (plan §9). Legacy inline
 * artifacts keep storage_status null and every new column is nullable, so an
 * existing row is read, attached and packed exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buddy_artifacts', function (Blueprint $table) {
            $table->string('object_key')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('media_type', 128)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('storage_status', 32)->nullable();
            $table->foreignId('original_artifact_id')->nullable()->constrained('buddy_artifacts')->nullOnDelete();
            $table->string('processor_version', 32)->nullable();
            $table->string('processing_status', 32)->nullable();
            $table->timestamp('retention_until')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['storage_status', 'retention_until']);
        });

        Schema::create('buddy_artifact_uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('buddy_task_id')->constrained('buddy_tasks')->cascadeOnDelete();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('artifact_type', 64);
            $table->unsignedBigInteger('declared_size');
            $table->string('media_type', 128);
            $table->string('staging_key')->unique();
            $table->string('status', 32);
            $table->timestamp('expires_at');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('buddy_artifact_id')->nullable()->constrained('buddy_artifacts')->nullOnDelete();
            $table->timestamps();

            $table->index(['api_client_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('buddy_artifact_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedBigInteger('committed_bytes')->default(0);
            $table->timestamps();

            $table->unique(['api_client_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buddy_artifact_quotas');
        Schema::dropIfExists('buddy_artifact_uploads');
        Schema::table('buddy_artifacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_artifact_id');
            $table->dropIndex(['storage_status', 'retention_until']);
            $table->dropColumn([
                'object_key',
                'size_bytes',
                'media_type',
                'sha256',
                'storage_status',
                'processor_version',
                'processing_status',
                'retention_until',
                'deleted_at',
            ]);
        });
    }
};
