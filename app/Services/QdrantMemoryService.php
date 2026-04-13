<?php

namespace App\Services;

use App\DTOs\MemorySearchResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;

class QdrantMemoryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, MemorySearchResult>
     */
    public function search(string $query, int $limit = 5, array $filters = []): array
    {
        $embedding = $this->embed($query);

        if ($embedding === []) {
            return [];
        }

        $body = [
            'vector' => $embedding,
            'limit' => $limit,
            'with_payload' => true,
        ];

        if ($filters !== []) {
            $body['filter'] = $this->buildFilter($filters);
        }

        // Laravel Http + Guzzle stalls for long stretches when the sync
        // evaluate endpoint reaches Qdrant under `php artisan serve` —
        // even though the same call from `tinker` returns in ~50ms. Bypass
        // Guzzle entirely and use libcurl directly so evaluation never
        // waits longer than the configured connect+exec budget.
        [$status, $raw] = $this->rawPost(
            "/collections/{$this->collection()}/points/search",
            $body,
            connectTimeout: 5,
            timeout: 15,
        );

        if ($status !== 200 || $raw === null) {
            Log::warning('Qdrant search failed', [
                'status' => $status,
                'body' => $raw,
            ]);

            return [];
        }

        $decoded = json_decode($raw, true);
        $results = is_array($decoded) ? ($decoded['result'] ?? []) : [];

        return array_map(
            fn (array $r) => MemorySearchResult::fromQdrantResult($r),
            $results,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function store(string $summary, array $payload = []): ?string
    {
        $embedding = $this->embed($summary);

        if ($embedding === []) {
            return null;
        }

        $pointId = (string) Str::uuid();

        $payload['summary'] = $summary;
        $payload['stored_at'] = now()->toISOString();

        $response = $this->client()
            ->put("/collections/{$this->collection()}/points", [
                'points' => [
                    [
                        'id' => $pointId,
                        'vector' => $embedding,
                        'payload' => $payload,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Qdrant store failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $pointId;
    }

    public function ensureCollectionExists(): bool
    {
        $collection = $this->collection();

        $check = $this->client()->get("/collections/{$collection}");

        if ($check->successful()) {
            return true;
        }

        $response = $this->client()
            ->put("/collections/{$collection}", [
                'vectors' => [
                    'size' => config('buddy.qdrant.vector_size', 1536),
                    'distance' => 'Cosine',
                ],
            ]);

        return $response->successful();
    }

    /**
     * @return array<int, float>
     */
    protected function embed(string $text): array
    {
        try {
            $response = Embeddings::for([$text])->generate();

            return $response->embeddings[0] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Embedding generation failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function client(): PendingRequest
    {
        $host = config('buddy.qdrant.host', 'http://localhost');
        $port = config('buddy.qdrant.port', 6333);
        $apiKey = config('buddy.qdrant.api_key');

        // Force a fresh cURL handle per request and resolve against IPv4
        // only. queue:listen forks workers from a parent that may have
        // cached a negative DNS result from boot time; reused connections
        // then stall for the full Guzzle timeout. Fresh + forbid-reuse
        // eliminates the cached-handle trap.
        $client = Http::baseUrl("{$host}:{$port}")
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->withOptions([
                'version' => 1.1,
                'curl' => [
                    CURLOPT_FRESH_CONNECT => true,
                    CURLOPT_FORBID_REUSE => true,
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ]);

        if ($apiKey) {
            $client->withHeader('api-key', $apiKey);
        }

        return $client;
    }

    protected function collection(): string
    {
        return config('buddy.qdrant.collection', 'buddy_episodes');
    }

    /**
     * Direct libcurl POST — avoids Guzzle handler caching that causes
     * sporadic connection stalls under `php artisan serve`.
     *
     * @param  array<string, mixed>  $body
     * @return array{0: int, 1: string|null}
     */
    protected function rawPost(
        string $path,
        array $body,
        int $connectTimeout = 5,
        int $timeout = 15,
    ): array {
        $host = rtrim(config('buddy.qdrant.host', 'http://localhost'), '/');
        $port = (int) config('buddy.qdrant.port', 6333);
        $apiKey = config('buddy.qdrant.api_key');
        $url = $host.':'.$port.$path;

        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($apiKey) {
            $headers[] = 'api-key: '.$apiKey;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errMsg = $errno !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        if ($errno !== 0) {
            Log::warning('Qdrant raw post failed', [
                'url' => $url,
                'errno' => $errno,
                'error' => $errMsg,
            ]);

            return [0, null];
        }

        return [$status, is_string($body) ? $body : null];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function buildFilter(array $filters): array
    {
        $must = [];

        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                $must[] = [
                    'key' => $key,
                    'match' => ['any' => $value],
                ];
            } else {
                $must[] = [
                    'key' => $key,
                    'match' => ['value' => $value],
                ];
            }
        }

        return ['must' => $must];
    }
}
