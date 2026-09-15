<?php

namespace Tests\Feature;

use App\DTOs\MemorySearchPage;
use App\Enums\ApiScope;
use App\Jobs\EvaluateTaskJob;
use App\Models\ApiClient;
use App\Models\BuddyTask;
use App\Services\ApiKeyService;
use App\Services\Council\CouncilClient;
use App\Services\Council\CouncilProfile;
use App\Services\Council\CouncilService;
use App\Services\EvaluatorOptimizerService;
use App\Services\TaskStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AzureCouncilTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'buddy_agents.council.rosters.azure.base_url' => 'https://azure.example/openai/v1',
            'ai.providers.azure.key' => 'AZURE-SECRET',
            'ai.providers.openrouter.key' => 'ROUTER-SECRET',
        ]);
        Http::preventStrayRequests();
    }

    private function reply(array $json): array
    {
        return ['choices' => [['message' => ['content' => json_encode($json)], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20]];
    }

    public function test_azure_transport_is_isolated_in_serial_and_parallel_calls(): void
    {
        Http::fake(['*' => Http::response($this->reply(['ok' => true]))]);
        $profile = CouncilProfile::resolve('azure');
        $client = (new CouncilClient)->forProfile('azure');
        $client->ask($profile['chairman'], 'Return JSON.', 'test');
        $client->askAll($profile['members'], 'Return JSON.', fn ($member) => $member['review_focus']);
        (new CouncilClient)->ask(config('buddy_agents.council.chairman'), 'Return JSON.', 'test');

        $requests = Http::recorded()->pluck(0);
        $this->assertCount(5, $requests);
        foreach ($requests->take(4) as $request) {
            $this->assertSame('https://azure.example/openai/v1/chat/completions', $request->url());
            $this->assertSame(['AZURE-SECRET'], $request->header('api-key'));
            $this->assertEmpty($request->header('Authorization'));
            $this->assertEmpty($request->header('HTTP-Referer'));
            $this->assertSame(24000, $request['max_completion_tokens']);
            $this->assertArrayNotHasKey('max_tokens', $request->data());
        }
        $this->assertSame('xhigh', $requests[0]['reasoning_effort']);
        $this->assertSame('gpt-6-astra', $requests[0]['model']);
        $this->assertSame(['gpt-5.5'], $requests->slice(1, 3)->map(fn ($r) => $r['model'])->unique()->values()->all());
        $this->assertSame(['Bearer ROUTER-SECRET'], $requests[4]->header('Authorization'));
        $this->assertEmpty($requests[4]->header('api-key'));
        $this->assertSame('openrouter', config('buddy_agents.council.profile'));
    }

    public function test_azure_chair_keeps_xhigh_on_json_repair(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => '{'], 'finish_reason' => 'length']]])
            ->push($this->reply(['ok' => true]))]);
        $client = (new CouncilClient)->forProfile('azure');
        $result = $client->ask(CouncilProfile::resolve('azure')['chairman'], 'Return JSON.', 'test');
        $this->assertTrue($result['json']['ok']);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('xhigh', $request['reasoning_effort']);
        }
    }

    public function test_explicit_provider_refusal_is_not_reasked(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['refusal' => 'restricted'], 'finish_reason' => 'stop']]])]);
        $result = (new CouncilClient)->forProfile('azure')->ask(CouncilProfile::resolve('azure')['chairman'], 's', 'u');
        $this->assertSame('provider_refusal', $result['error']);
        Http::assertSentCount(1);
    }

    #[TestWith(['refusal'])]
    #[TestWith(['content_filter'])]
    public function test_refusal_or_content_filter_during_repair_is_not_accepted_as_json(string $kind): void
    {
        $repair = $this->reply(['ok' => true]);
        if ($kind === 'refusal') {
            $repair['choices'][0]['message']['refusal'] = 'restricted';
        } else {
            $repair['choices'][0]['finish_reason'] = 'content_filter';
        }
        Http::fake(['*' => Http::sequence()
            ->push(['choices' => [['message' => ['content' => '{'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1]])
            ->push($repair)]);
        $result = (new CouncilClient)->forProfile('azure')->ask(CouncilProfile::resolve('azure')['chairman'], 's', 'u');
        $this->assertSame('provider_refusal', $result['error']);
        $this->assertNull($result['json']);
        $this->assertSame(11, $result['usage']['prompt_tokens']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_connection_failure_on_rate_limit_retry_degrades_in_serial_and_parallel_paths(bool $parallel): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                return Http::response(['error' => 'rate limited'], 429);
            }
            throw new ConnectionException('Connection lost on retry.');
        });
        $client = (new CouncilClient)->forProfile('azure');
        $member = CouncilProfile::resolve('azure')['chairman'];
        $result = $parallel ? $client->askAll([$member], 's', fn () => 'u')['chairman'] : $client->ask($member, 's', 'u');
        $this->assertNull($result['json']);
        $this->assertSame('Connection lost on retry.', $result['error']);
    }

    public function test_azure_council_discloses_repeated_model_and_keeps_three_reviewers(): void
    {
        $sequence = Http::sequence()->push($this->reply(['hypotheses' => [['id' => 'H1', 'statement' => 'Network failure', 'kill_conditions' => ['network healthy']]]]));
        foreach (range(1, 3) as $i) {
            $sequence->push($this->reply(['stances' => [['hypothesis_id' => 'H1', 'stance' => 'support', 'confidence' => 0.8, 'evidence_refs' => ['E1']]]]));
        }
        foreach (range(1, 3) as $i) {
            $sequence->push($this->reply(['defeaters' => [], 'concessions' => []]));
        }
        $sequence->push($this->reply(['accepted' => true, 'confidence' => 'medium', 'summary' => 'Check network.', 'recommended_plan' => ['Check network']]));
        Http::fake(['azure.example/*' => $sequence]);

        $task = BuddyTask::factory()->create(['council_profile' => 'azure', 'evidence' => ['Connection timed out.']]);
        $result = app(CouncilService::class)->deliberate($task, new MemorySearchPage([], 'test'));
        Http::assertSentCount(8);
        $this->assertSame('azure', $result['verdict']['roster']['profile']);
        $this->assertSame(1, $result['verdict']['roster']['distinct_member_models']);
        $this->assertSame(['openai' => 3], $result['verdict']['mechanical_tally']['disclosure']['family_spread']);
        $this->assertTrue($result['verdict']['mechanical_tally']['disclosure']['shared_model_reviewers']);
        $this->assertFalse($result['verdict']['mechanical_tally']['disclosure']['chairman_is_member']);
    }

    public function test_profile_is_persisted_before_queue_dispatch_and_invalid_profile_is_rejected(): void
    {
        Queue::fake();
        config(['buddy.api.auth_required' => true, 'buddy_agents.council.gate_enabled' => false]);
        $client = ApiClient::create(['name' => 'azure-test', 'project' => 'buddy']);
        $key = app(ApiKeyService::class)->issue($client, [ApiScope::TasksWrite])['plaintext'];
        $task = BuddyTask::factory()->create(['api_client_id' => $client->id]);
        $this->withToken($key)->postJson('/api/buddy/tasks/'.$task->ulid.'/council', ['profile' => 'azure'])->assertAccepted()->assertJsonPath('profile', 'azure');
        $this->assertSame('azure', $task->refresh()->council_profile);
        $other = BuddyTask::factory()->create(['api_client_id' => $client->id]);
        $this->withToken($key)->postJson('/api/buddy/tasks/'.$other->ulid.'/council', ['profile' => 'typo'])->assertUnprocessable();
        $this->assertSame('evaluate', $other->refresh()->operation);
        config(['ai.providers.azure.key' => null]);
        $this->withToken($key)->postJson('/api/buddy/tasks/'.$other->ulid.'/council', ['profile' => 'azure'])->assertUnprocessable();
    }

    public function test_old_evaluation_message_cannot_claim_a_task_switched_to_council(): void
    {
        $task = BuddyTask::factory()->create();
        $job = new EvaluateTaskJob($task);
        $task->update(['operation' => 'council', 'council_profile' => 'azure']);
        $evaluator = $this->mock(EvaluatorOptimizerService::class);
        $evaluator->shouldNotReceive('evaluate');
        $state = $this->mock(TaskStateService::class);
        $state->shouldNotReceive('claim');
        $job->handle($evaluator, $state);
        $this->assertNull($task->refresh()->claimed_by);
        Http::assertNothingSent();
    }
}
