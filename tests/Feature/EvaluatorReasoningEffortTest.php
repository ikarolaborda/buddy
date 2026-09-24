<?php

namespace Tests\Feature;

use App\Ai\Agents\EvaluatorOptimizerAgent;
use App\DTOs\MemorySearchPage;
use App\Models\BuddyTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

/*
 * The evaluator's reasoning effort is a product decision (config/buddy_agents.php).
 * These tests pin the two halves that matter operationally: an unset value
 * must leave the provider request untouched, and a set value must actually
 * reach the Responses API body, because a config value being present is
 * not evidence it is transmitted (2026-09-06 finding).
 */
class EvaluatorReasoningEffortTest extends TestCase
{
    use RefreshDatabase;

    public function test_unset_effort_yields_no_provider_options(): void
    {
        config(['buddy_agents.profiles.evaluator-optimizer.reasoning_effort' => null]);

        $this->assertSame([], $this->agent()->providerOptions(Lab::Azure));
        $this->assertSame([], $this->agent()->providerOptions('azure'));
    }

    public function test_a_valid_effort_is_offered_only_to_openai_family_providers(): void
    {
        config(['buddy_agents.profiles.evaluator-optimizer.reasoning_effort' => 'low']);

        $this->assertSame(['reasoning' => ['effort' => 'low']], $this->agent()->providerOptions(Lab::Azure));
        $this->assertSame(['reasoning' => ['effort' => 'low']], $this->agent()->providerOptions('openai'));
        $this->assertSame([], $this->agent()->providerOptions(Lab::Anthropic));

        config(['buddy_agents.profiles.evaluator-optimizer.reasoning_effort' => 'minimal']);
        $this->assertSame([], $this->agent()->providerOptions(Lab::Azure), 'Azure rejects minimal; never send it');
    }

    public function test_the_effort_is_transmitted_in_the_responses_request_and_omitted_when_unset(): void
    {
        config([
            'ai.providers.azure.url' => 'https://azure.test',
            'ai.providers.azure.key' => 'test-key',
            'buddy_agents.profiles.evaluator-optimizer.provider' => 'azure',
        ]);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($this->responsesBody(), 200)]);

        config(['buddy_agents.profiles.evaluator-optimizer.reasoning_effort' => 'low']);
        $this->promptQuietly();
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'responses')
            && data_get($request->data(), 'reasoning.effort') === 'low');

        Http::fake(['*' => Http::response($this->responsesBody(), 200)]);
        config(['buddy_agents.profiles.evaluator-optimizer.reasoning_effort' => null]);
        $this->promptQuietly();
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'responses')
            && ! array_key_exists('reasoning', $request->data()));
    }

    private function agent(): EvaluatorOptimizerAgent
    {
        return new EvaluatorOptimizerAgent(BuddyTask::factory()->create(), MemorySearchPage::degraded('test', 'no memory in tests'));
    }

    private function promptQuietly(): void
    {
        try {
            $this->agent()->prompt('Evaluate the packet.');
        } catch (\Throwable) {
            // Only the outbound request shape is under test here.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function responsesBody(): array
    {
        return [
            'id' => 'resp_test',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'gpt-6-astra',
            'output' => [[
                'type' => 'message',
                'id' => 'msg_test',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => '{}', 'annotations' => []]],
            ]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
        ];
    }
}
