<?php

namespace App\Services\Council;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Direct OpenRouter chat-completions client. Bypasses laravel/ai
 * deliberately (ADR 0009): the council needs reasoning_effort
 * passthrough, response_format control, Http::pool concurrency, and
 * per-call usage capture. json_object mode plus a prompt-embedded
 * schema is used instead of strict json_schema: Anthropic structured
 * output via OpenRouter is tool-shimmed and degrades under extended
 * reasoning, and Gemini rejects several JSON-Schema keywords.
 */
class CouncilClient
{
    /**
     * @param  array<string, mixed>  $member  config row (model, reasoning_effort?)
     * @return array{json: array<string, mixed>|null, usage: array<string, int>, error: string|null}
     */
    public function ask(array $member, string $system, string $user): array
    {
        $response = null;

        try {
            $response = $this->request()->post('/chat/completions', $this->payload($member, $system, $user));
        } catch (\Throwable $e) {
            return ['json' => null, 'usage' => [], 'error' => $e->getMessage()];
        }

        return $this->interpret($member, $system, $user, $response);
    }

    /**
     * Parallel round: one call per member on a shared pool. A failed
     * slot degrades to an absent member; the round never throws.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return array<string, array{json: array<string, mixed>|null, usage: array<string, int>, error: string|null}>
     */
    public function askAll(array $members, string $system, callable $userPromptFor): array
    {
        $responses = Http::pool(function (Pool $pool) use ($members, $system, $userPromptFor) {
            foreach ($members as $member) {
                $this->configure($pool->as($member['key']))
                    ->post(
                        rtrim($this->baseUrl(), '/').'/chat/completions',
                        $this->payload($member, $system, $userPromptFor($member)),
                    );
            }
        });

        $results = [];

        foreach ($members as $member) {
            $slot = $responses[$member['key']] ?? null;

            if (! $slot instanceof Response) {
                $results[$member['key']] = [
                    'json' => null,
                    'usage' => [],
                    'error' => $slot instanceof \Throwable ? $slot->getMessage() : 'no response',
                ];

                continue;
            }

            $results[$member['key']] = $this->interpret($member, $system, $userPromptFor($member), $slot);
        }

        return $results;
    }

    /**
     * @return array{json: array<string, mixed>|null, usage: array<string, int>, error: string|null}
     */
    protected function interpret(array $member, string $system, string $user, Response $response): array
    {
        if (! $response->successful()) {
            return [
                'json' => null,
                'usage' => [],
                'error' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300),
            ];
        }

        $usage = $this->usage($response->json('usage') ?? []);
        $content = (string) ($response->json('choices.0.message.content') ?? '');
        $json = $this->extractJson($content);

        if ($json !== null) {
            return ['json' => $json, 'usage' => $usage, 'error' => null];
        }

        // One cheap re-ask before declaring the member absent: dropping
        // an expensive reply over malformed JSON is bad economics.
        try {
            $retry = $this->request()->post('/chat/completions', $this->payload(
                $member,
                $system,
                $user."\n\nYour previous reply was not valid JSON. Reply again with ONLY the JSON object, no prose.",
            ));

            if ($retry->successful()) {
                $usage = $this->mergeUsage($usage, $this->usage($retry->json('usage') ?? []));
                $json = $this->extractJson((string) ($retry->json('choices.0.message.content') ?? ''));

                if ($json !== null) {
                    return ['json' => $json, 'usage' => $usage, 'error' => null];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Council re-ask failed', ['member' => $member['key'] ?? '?', 'error' => $e->getMessage()]);
        }

        return ['json' => null, 'usage' => $usage, 'error' => 'unparseable response'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(array $member, string $system, string $user): array
    {
        $payload = [
            'model' => $member['model'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => (int) config('buddy_agents.council.max_output_tokens', 8000),
        ];

        if (isset($member['reasoning_effort'])) {
            $payload['reasoning_effort'] = $member['reasoning_effort'];
        }

        return $payload;
    }

    protected function request(): PendingRequest
    {
        return $this->configure(Http::baseUrl($this->baseUrl()));
    }

    protected function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->withHeaders([
                'Authorization' => 'Bearer '.(string) config('ai.providers.openrouter.key'),
                'HTTP-Referer' => 'https://github.com/ikarolaborda/buddy',
                'X-Title' => 'Buddy Council',
            ])
            ->timeout((int) config('buddy_agents.council.call_timeout', 300))
            ->connectTimeout(10)
            ->retry(1, 2000, fn ($e, $req) => $e instanceof ConnectionException, false)
            ->asJson();
    }

    protected function baseUrl(): string
    {
        return (string) config('buddy_agents.council.base_url', 'https://openrouter.ai/api/v1');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function extractJson(string $content): ?array
    {
        $candidates = [$content, trim($content)];

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $m)) {
            $candidates[] = $m[1];
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($content, $start, $end - $start + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            // Trailing-comma repair, the most common cross-model defect.
            $repaired = preg_replace('/,\s*([}\]])/', '$1', $candidate);
            $decoded = json_decode((string) $repaired, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    protected function usage(array $raw): array
    {
        return [
            'prompt_tokens' => (int) ($raw['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($raw['completion_tokens'] ?? 0),
        ];
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    protected function mergeUsage(array $a, array $b): array
    {
        return [
            'prompt_tokens' => ($a['prompt_tokens'] ?? 0) + ($b['prompt_tokens'] ?? 0),
            'completion_tokens' => ($a['completion_tokens'] ?? 0) + ($b['completion_tokens'] ?? 0),
        ];
    }
}
