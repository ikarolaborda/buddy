<?php

namespace App\Services\Council;

use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/** Long reasoning runs outlive Azure's idle connection limit. Poll one response ID. */
final class AzureBackgroundResponse
{
    public function await(Response $response, Closure $request, float $deadline): Response
    {
        if (! $response->successful()) {
            return $response;
        }

        $id = $response->json('id');
        $id = is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,200}$/', $id) ? $id : null;
        $pending = in_array($response->json('status'), ['queued', 'in_progress'], true);

        try {
            while ($pending) {
                if ($id === null) {
                    return $this->error('Azure background response has no valid response ID.');
                }
                if (microtime(true) >= $deadline) {
                    return $this->error('Azure background response timed out.', 504);
                }

                Sleep::for(2)->seconds();
                try {
                    $poll = $request()->timeout(max(1, min(30, $deadline - microtime(true))))->get('/responses/'.$id);
                } catch (ConnectionException) {
                    // A GET can be repeated without starting or billing another generation.
                    continue;
                }
                if ($poll->status() === 429 || $poll->serverError()) {
                    continue;
                }
                if (! $poll->successful()) {
                    return $poll;
                }
                $response = $poll;
                $pending = in_array($response->json('status'), ['queued', 'in_progress'], true);
            }

            return $this->normalize($response);
        } finally {
            if ($id !== null) {
                if ($pending) {
                    try {
                        $request()->timeout(10)->post('/responses/'.$id.'/cancel');
                    } catch (\Throwable) {
                        Log::warning('Azure council response cancellation failed', ['response_id' => $id]);
                    }
                }
                try {
                    // Background mode requires storage. Remove the response after retrieval.
                    $deleted = $request()->timeout(10)->delete('/responses/'.$id);
                    if (! $deleted->successful()) {
                        Log::warning('Azure council response cleanup failed', ['response_id' => $id, 'status' => $deleted->status()]);
                    }
                } catch (\Throwable) {
                    Log::warning('Azure council response cleanup failed', ['response_id' => $id]);
                }
            }
        }
    }

    private function normalize(Response $response): Response
    {
        $body = $response->json();
        $content = '';
        $refused = in_array($body['error']['code'] ?? $body['incomplete_details']['reason'] ?? null, ['content_filter', 'responsible_ai_policy_violation'], true);
        foreach ($body['output'] ?? [] as $output) {
            foreach ($output['content'] ?? [] as $part) {
                $refused = $refused || ($part['type'] ?? '') === 'refusal';
                if (($part['type'] ?? '') === 'output_text') {
                    $content .= $part['text'] ?? '';
                }
            }
        }

        if (! $refused && ! in_array($body['status'] ?? '', ['completed', 'incomplete'], true)) {
            return $this->error('Azure response ended with status '.($body['status'] ?? 'unknown').'.');
        }
        if (! $refused && ($body['status'] ?? '') === 'incomplete' && ($body['incomplete_details']['reason'] ?? '') !== 'max_output_tokens') {
            return $this->error('Azure response incomplete without an output-token limit.');
        }

        return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'choices' => [[
                'message' => ['content' => $content, 'refusal' => $refused ? 'provider_refusal' : null],
                'finish_reason' => $refused ? 'content_filter' : (($body['status'] ?? '') === 'incomplete' ? 'length' : 'stop'),
            ]],
            'usage' => [
                'prompt_tokens' => $body['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $body['usage']['output_tokens'] ?? 0,
                'completion_tokens_details' => ['reasoning_tokens' => $body['usage']['output_tokens_details']['reasoning_tokens'] ?? 0],
            ],
        ])));
    }

    private function error(string $message, int $status = 502): Response
    {
        return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => $message]])));
    }
}
