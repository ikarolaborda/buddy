<?php

namespace Tests\Feature;

use App\DTOs\MemorySearchPage;
use App\Models\BuddyTask;
use App\Services\Council\CouncilClient;
use App\Services\Council\CouncilProfile;
use App\Services\Council\CouncilSchemas;
use App\Services\Council\CouncilService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/*
 * ADR 0009 amendment (2026-09-15): the Azure chairman's frame and verdict
 * calls may use strict json_schema output. These tests pin the payload
 * contract (chair strict, members json_object, other profiles untouched,
 * switch off restores json_object, the schema survives a re-ask, a schema
 * rejection fails without a blind retry) and the schemas against what the
 * service actually reads.
 */
class AzureStrictChairCouncilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'buddy_agents.council.rosters.azure.base_url' => 'https://azure.example/openai/v1',
            'ai.providers.azure.key' => 'AZURE-SECRET',
            'ai.providers.openrouter.key' => 'ROUTER-SECRET',
            'buddy_agents.council.strict_chair' => true,
        ]);
        Http::preventStrayRequests();
    }

    public function test_chair_calls_use_strict_schemas_while_members_keep_json_object(): void
    {
        Log::spy();
        Http::fake(['azure.example/*' => $this->council(['accepted' => false, 'confidence' => 'none', 'summary' => 'Underdetermined.', 'recommended_plan' => [], 'findings' => [], 'supported_hypotheses' => [], 'weak_hypotheses' => [], 'defeated' => [], 'dissents' => [], 'proposed_discriminators' => [], 'risks' => []])]);

        $task = BuddyTask::factory()->create(['council_profile' => 'azure', 'evidence' => ['Connection timed out.']]);
        $result = app(CouncilService::class)->deliberate($task, new MemorySearchPage([], 'test'));

        $requests = Http::recorded()->pluck(0);
        $this->assertCount(8, $requests);
        $this->assertSame(['type' => 'json_schema', 'name' => 'council_frame', 'strict' => true, 'schema' => CouncilSchemas::frame()], $requests[0]['text']['format']);
        foreach ($requests->slice(1, 6) as $request) {
            $this->assertSame(['type' => 'json_object'], $request['text']['format']);
        }
        $this->assertSame(['type' => 'json_schema', 'name' => 'council_verdict', 'strict' => true, 'schema' => CouncilSchemas::verdict()], $requests[7]['text']['format']);
        $this->assertSame('xhigh', $requests[7]['reasoning']['effort']);
        $this->assertSame(24000, $requests[7]['max_output_tokens']);

        $this->assertFalse($result['verdict']['accepted']);
        $this->assertSame('none', $result['verdict']['confidence']);
        $this->assertSame([], $result['verdict']['findings']);
        Log::shouldHaveReceived('info')->with('Council chair request', ['phase' => 'frame', 'format' => 'json_schema'])->once();
        Log::shouldHaveReceived('info')->with('Council chair request', ['phase' => 'verdict', 'format' => 'json_schema'])->once();
    }

    public function test_switch_off_restores_json_object_for_the_chair(): void
    {
        config(['buddy_agents.council.strict_chair' => false]);
        Http::fake(['azure.example/*' => $this->council()]);

        $task = BuddyTask::factory()->create(['council_profile' => 'azure', 'evidence' => ['Connection timed out.']]);
        app(CouncilService::class)->deliberate($task, new MemorySearchPage([], 'test'));

        foreach (Http::recorded()->pluck(0) as $request) {
            $this->assertSame(['type' => 'json_object'], $request['text']['format']);
        }
    }

    public function test_the_schema_survives_a_re_ask_after_truncation(): void
    {
        Http::fake(['azure.example/*' => Http::sequence()
            ->push(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => [['content' => [['type' => 'output_text', 'text' => '{']]]]])
            ->push($this->reply(['claims' => [], 'hypotheses' => [['id' => 'H1', 'statement' => 'x', 'kill_conditions' => ['y']]], 'open_questions' => []]))]);

        $result = (new CouncilClient)->forProfile('azure')->ask(CouncilProfile::resolve('azure')['chairman'], 'Frame.', 'packet', CouncilSchemas::frame(), 'frame');

        $this->assertSame('H1', $result['json']['hypotheses'][0]['id']);
        Http::assertSentCount(2);
        foreach (Http::recorded()->pluck(0) as $request) {
            $this->assertSame('json_schema', $request['text']['format']['type']);
            $this->assertSame('xhigh', $request['reasoning']['effort']);
        }
    }

    public function test_a_schema_rejection_fails_clearly_without_a_retry(): void
    {
        Http::fake(['azure.example/*' => Http::response(['error' => ['message' => 'Invalid schema for response_format']], 400)]);

        $result = (new CouncilClient)->forProfile('azure')->ask(CouncilProfile::resolve('azure')['chairman'], 'Frame.', 'packet', CouncilSchemas::frame(), 'frame');

        $this->assertNull($result['json']);
        $this->assertStringStartsWith('HTTP 400', (string) $result['error']);
        Http::assertSentCount(1);
    }

    public function test_openrouter_payloads_ignore_the_schema(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']]])]);

        (new CouncilClient)->ask(config('buddy_agents.council.chairman'), 'Frame.', 'packet', CouncilSchemas::frame(), 'frame');

        $request = Http::recorded()->pluck(0)->first();
        $this->assertSame(['type' => 'json_object'], $request['response_format']);
        $this->assertArrayNotHasKey('text', $request->data());
    }

    public function test_an_empty_frame_still_fails_the_council_locally(): void
    {
        Http::fake(['azure.example/*' => Http::response($this->reply(['claims' => [], 'hypotheses' => [], 'open_questions' => []]))]);
        $task = BuddyTask::factory()->create(['council_profile' => 'azure', 'evidence' => ['Connection timed out.']]);

        $this->expectExceptionMessage('Chairman frame produced no hypotheses.');
        app(CouncilService::class)->deliberate($task, new MemorySearchPage([], 'test'));
    }

    public function test_schemas_pin_what_the_service_reads(): void
    {
        $frame = CouncilSchemas::frame();
        $verdict = CouncilSchemas::verdict();

        $this->assertSame(CouncilSchemas::FRAME_KEYS, $frame['required']);
        $this->assertSame(['id', 'statement', 'kill_conditions'], $frame['properties']['hypotheses']['items']['required']);
        $this->assertSame(CouncilSchemas::VERDICT_KEYS, $verdict['required']);
        $this->assertSame('boolean', $verdict['properties']['accepted']['type']);
        $this->assertSame(CouncilSchemas::CONFIDENCE, $verdict['properties']['confidence']['enum']);

        foreach (array_slice(CouncilSchemas::VERDICT_KEYS, 3) as $list) {
            $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], $verdict['properties'][$list]);
        }

        $this->assertStringContainsString('"claims": [{"id": "C1", "text": "..."}], "hypotheses": [{"id": "H1", "statement": "...", "kill_conditions": ["..."]}], "open_questions": ["..."]', $this->promptOf('framingSystem'));
        foreach (CouncilSchemas::VERDICT_KEYS as $key) {
            $this->assertStringContainsString('"'.$key.'":', $this->promptOf('verdictSystem'));
        }

        $walk = function (array $node) use (&$walk): void {
            if (($node['type'] ?? null) === 'object') {
                $this->assertFalse($node['additionalProperties']);
                $this->assertSame(array_keys($node['properties']), $node['required']);
                foreach ($node['properties'] as $child) {
                    $walk($child);
                }
            }
            if (($node['type'] ?? null) === 'array') {
                $walk($node['items']);
            }
        };
        $walk($frame);
        $walk($verdict);
    }

    private function promptOf(string $method): string
    {
        $reflection = new \ReflectionMethod(CouncilService::class, $method);

        return (string) $reflection->invoke(app(CouncilService::class));
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function reply(array $json): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($json)]]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]];
    }

    /**
     * @param  array<string, mixed>|null  $verdict
     */
    private function council(?array $verdict = null): ResponseSequence
    {
        $sequence = Http::sequence()->push($this->reply(['claims' => [['id' => 'C1', 'text' => 'Timeouts observed']], 'hypotheses' => [['id' => 'H1', 'statement' => 'Network failure', 'kill_conditions' => ['network healthy']]], 'open_questions' => []]));
        foreach (range(1, 3) as $i) {
            $sequence->push($this->reply(['stances' => [['hypothesis_id' => 'H1', 'stance' => 'support', 'confidence' => 0.8, 'evidence_refs' => ['E1']]]]));
        }
        foreach (range(1, 3) as $i) {
            $sequence->push($this->reply(['defeaters' => [], 'concessions' => []]));
        }

        return $sequence->push($this->reply($verdict ?? ['accepted' => true, 'confidence' => 'medium', 'summary' => 'Check network.', 'recommended_plan' => ['Check network'], 'findings' => [], 'supported_hypotheses' => ['H1'], 'weak_hypotheses' => [], 'defeated' => [], 'dissents' => [], 'proposed_discriminators' => [], 'risks' => []]));
    }
}
