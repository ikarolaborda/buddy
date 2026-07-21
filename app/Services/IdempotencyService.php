<?php

namespace App\Services;

use App\Models\ApiClient;
use App\Models\IdempotencyRecord;
use Illuminate\Database\UniqueConstraintViolationException;

class IdempotencyService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function hashRequest(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload));
    }

    public function find(ApiClient $client, string $key): ?IdempotencyRecord
    {
        return IdempotencyRecord::query()
            ->where('api_client_id', $client->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * Must be called inside the transaction that creates the task, so the
     * unique constraint arbitrates concurrent duplicate submissions.
     */
    public function record(
        ApiClient $client,
        string $key,
        string $requestHash,
        int $taskId,
        int $responseStatus,
        array $responseBody,
    ): ?IdempotencyRecord {
        try {
            return IdempotencyRecord::create([
                'api_client_id' => $client->id,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'buddy_task_id' => $taskId,
                'response_status' => $responseStatus,
                'response_body' => $responseBody,
                'expires_at' => now()->addDays(1),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
