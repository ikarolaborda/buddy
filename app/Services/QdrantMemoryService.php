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

        $response = $this->client()
            ->post("/collections/{$this->collection()}/points/search", $body);

        if (! $response->successful()) {
            Log::warning('Qdrant search failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $results = $response->json('result', []);

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

        $client = Http::baseUrl("{$host}:{$port}")
            ->acceptJson()
            ->asJson()
            ->timeout(30);

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
