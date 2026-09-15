<?php

namespace App\Services\Council;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Council transport: OpenRouter chat completions and Azure background Responses.
 * Uses direct HTTP instead of laravel/ai
 * deliberately (ADR 0009): the council needs reasoning_effort
 * passthrough, response_format control, Http::pool concurrency, and
 * per-call usage capture. json_object mode plus a prompt-embedded
 * schema is used instead of strict json_schema: Anthropic structured
 * output via OpenRouter is tool-shimmed and degrades under extended
 * reasoning, and Gemini rejects several JSON-Schema keywords.
 */
class CouncilClient
{
    protected ?array $profile = null;

    public function forProfile(?string $name): static
    {
        $client = clone $this;
        $client->profile = CouncilProfile::resolve($name);

        return $client;
    }

    protected function setting(string $name, mixed $default = null): mixed
    {
        return $this->profile[$name] ?? config('buddy_agents.council.'.$name, $default);
    }

    /**
     * @param  array<string, mixed>  $member  config row (model, reasoning_effort?)
     * @return array{json: array<string, mixed>|null, usage: array<string, int>, error: string|null}
     */
    public function ask(array $member, string $system, string $user): array
    {
        $response = null;

        try {
            $client = $this->forMember($member);
            $response = $client->send($client->payload($member, $system, $user));
        } catch (\Throwable $e) {
            return ['json' => null, 'usage' => [], 'error' => $e->getMessage()];
        }

        return $client->interpret($member, $system, $user, $response);
    }

    protected function forMember(array $member): static
    {
        return isset($member['provider_profile'])
            ? $this->forProfile($member['provider_profile'])
            : $this;
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
        $clients = [];
        foreach ($members as $member) {
            $clients[$member['key']] = $this->forMember($member);
        }

        $deadline = microtime(true) + (int) $this->setting('call_timeout', 420);
        $responses = Http::pool(function (Pool $pool) use ($members, $system, $userPromptFor, $clients) {
            foreach ($members as $member) {
                $client = $clients[$member['key']];
                $client->configure($pool->as($member['key']))
                    ->post(
                        rtrim($client->baseUrl(), '/').$client->endpoint(),
                        $client->payload($member, $system, $userPromptFor($member)),
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

            try {
                $client = $clients[$member['key']];
                $results[$member['key']] = $client->interpret($member, $system, $userPromptFor($member), $client->finish($slot, $deadline));
            } catch (\Throwable $e) {
                $results[$member['key']] = ['json' => null, 'usage' => [], 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @return array{json: array<string, mixed>|null, usage: array<string, int>, error: string|null}
     */
    protected function interpret(array $member, string $system, string $user, Response $response): array
    {
        if (! $response->successful()) {
            // askAll fires every member at once and OpenRouter reserves each
            // request's worth of credit for as long as it is in flight, so a
            // five-way pool can be refused for budget it has not spent yet.
            // interpret() runs after the pool has drained, which is precisely
            // the condition the 402 asks us to wait for, so one retry here
            // recovers a member that was never actually unaffordable.
            if ($this->settlingWouldHelp($response)) {
                try {
                    $response = $this->send($this->payload($member, $system, $user));
                } catch (\Throwable $e) {
                    return ['json' => null, 'usage' => [], 'error' => $e->getMessage()];
                }
            }

            if (! $response->successful()) {
                return [
                    'json' => null,
                    'usage' => [],
                    'error' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300),
                ];
            }
        }

        $usage = $this->usage($response->json('usage') ?? []);

        if ($response->json('choices.0.message.refusal') || $response->json('choices.0.finish_reason') === 'content_filter') {
            return ['json' => null, 'usage' => $usage, 'error' => 'provider_refusal'];
        }

        $content = (string) ($response->json('choices.0.message.content') ?? '');
        $json = $this->extractJson($content);

        if ($json !== null) {
            return ['json' => $json, 'usage' => $usage, 'error' => null];
        }

        // OpenRouter counts reasoning inside completion_tokens, so max_tokens is
        // one budget shared by thinking and answering. A seat that reasons hard
        // can spend almost all of it before the JSON starts and get cut off
        // mid-object: on 2026-09-06 the gpt seat returned finish_reason=length
        // with 6877 of its 8000 tokens spent reasoning. Re-asking on the same
        // budget reproduces the same truncation and bills for it twice, so the
        // retry only earns its keep if it buys the answer more room.
        $truncated = $response->json('choices.0.finish_reason') === 'length';
        $reasoningSpent = $usage['reasoning_tokens'];

        try {
            $retry = $this->send($this->payload(
                $member,
                $system,
                $truncated
                    ? $user."\n\nYour previous reply was cut off before the JSON closed. Answer more briefly and return ONLY the complete JSON object."
                    : $user."\n\nYour previous reply was not valid JSON. Reply again with ONLY the JSON object, no prose.",
                $truncated && $this->setting('profile') !== 'azure' ? 'low' : null,
            ));

            if ($retry->successful()) {
                $usage = $this->mergeUsage($usage, $this->usage($retry->json('usage') ?? []));
                if ($retry->json('choices.0.message.refusal') || $retry->json('choices.0.finish_reason') === 'content_filter') {
                    return ['json' => null, 'usage' => $usage, 'error' => 'provider_refusal'];
                }
                $json = $this->extractJson((string) ($retry->json('choices.0.message.content') ?? ''));

                if ($json !== null) {
                    return ['json' => $json, 'usage' => $usage, 'error' => null];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Council re-ask failed', ['member' => $member['key'] ?? '?', 'error' => $e->getMessage()]);
        }

        // Distinct errors, because they call for different fixes: truncation is
        // a budget the operator sets, malformed JSON is the model's fault.
        return [
            'json' => null,
            'usage' => $usage,
            'error' => $truncated
                ? 'truncated at max_output_tokens ('.$reasoningSpent.' reasoning tokens)'
                : 'unparseable response',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(array $member, string $system, string $user, ?string $reasoningEffort = null): array
    {
        $payload = [
            'model' => $member['model'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => ['type' => 'json_object'],
            $this->setting('token_parameter', 'max_tokens') => (int) $this->setting('max_output_tokens', 24000),
        ];

        $effort = $reasoningEffort ?? ($member['reasoning_effort'] ?? null);

        if ($effort !== null) {
            $payload['reasoning_effort'] = $effort;
        }

        if ($this->setting('profile') === 'azure') {
            return [
                'model' => $member['model'],
                'input' => $payload['messages'],
                'text' => ['format' => ['type' => 'json_object']],
                'max_output_tokens' => (int) $this->setting('max_output_tokens', 24000),
                'reasoning' => ['effort' => $effort ?? 'high'],
                'background' => true,
                'store' => true,
            ];
        }

        return $payload;
    }

    protected function endpoint(): string
    {
        return $this->setting('profile') === 'azure' ? '/responses' : '/chat/completions';
    }

    protected function send(array $payload): Response
    {
        $deadline = microtime(true) + (int) $this->setting('call_timeout', 420);

        return $this->finish($this->request()->post($this->endpoint(), $payload), $deadline);
    }

    protected function finish(Response $response, float $deadline): Response
    {
        if ($this->setting('profile') !== 'azure') {
            return $response;
        }

        return (new AzureBackgroundResponse)->await($response, fn () => $this->request(), $deadline);
    }

    /**
     * True when the refusal is about requests still in flight rather than about
     * the account being unable to afford the call at all. Retrying anything
     * else just spends the same money twice for the same refusal.
     */
    protected function settlingWouldHelp(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        return $response->status() === 402
            && $response->json('error.metadata.reason') === 'in_flight_budget_exhausted';
    }

    protected function request(): PendingRequest
    {
        return $this->configure(Http::baseUrl($this->baseUrl()));
    }

    /*
     * Credential and headers come from the ACTIVE council profile, not from a
     * hard-coded provider. This used to read ai.providers.openrouter.key
     * unconditionally, which meant repointing base_url at another provider
     * would have sent an OpenRouter token to it. Base URL and credential are
     * now resolved from the same profile so they cannot drift apart.
     */
    protected function configure(PendingRequest $request): PendingRequest
    {
        $credential = (string) $this->setting('credential', 'ai.providers.openrouter.key');
        $authHeader = (string) $this->setting('auth_header', 'Authorization');
        $secret = (string) config($credential);

        return $request
            ->withHeaders(array_merge(
                (array) $this->setting('headers', []),
                [$authHeader => $authHeader === 'Authorization' ? 'Bearer '.$secret : $secret],
            ))
            ->timeout($this->setting('profile') === 'azure' ? 60 : (int) $this->setting('call_timeout', 420))
            ->connectTimeout(10)
            ->retry(1, 2000, fn ($e, $req) => $e instanceof ConnectionException, false)
            ->asJson();
    }

    protected function baseUrl(): string
    {
        return (string) $this->setting('base_url', 'https://openrouter.ai/api/v1');
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
    /**
     * Reasoning tokens are billed as output but are not inside
     * completion_tokens on every provider, so a council seating a reasoning
     * model was under-reporting what it actually cost. laravel/ai already
     * carries them on the evaluator path (Usage::$reasoningTokens); this is the
     * council's own mapper catching up, now that the 'gpt' seat is one.
     */
    protected function usage(array $raw): array
    {
        return [
            'prompt_tokens' => (int) ($raw['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($raw['completion_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($raw['completion_tokens_details']['reasoning_tokens'] ?? 0),
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
            'reasoning_tokens' => ($a['reasoning_tokens'] ?? 0) + ($b['reasoning_tokens'] ?? 0),
        ];
    }
}
