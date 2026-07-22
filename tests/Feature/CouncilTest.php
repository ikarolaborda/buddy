<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Enums\TaskStatus;
use App\Jobs\CouncilDeliberateJob;
use App\Models\ApiClient;
use App\Models\BuddyArtifact;
use App\Models\BuddyRecommendation;
use App\Models\BuddyRun;
use App\Models\BuddyTask;
use App\Services\ApiKeyService;
use App\Services\Council\CouncilGate;
use App\Services\EvaluatorOptimizerService;
use App\Services\TaskStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CouncilTest extends TestCase
{
    use RefreshDatabase;

    protected function or(array $json): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode($json)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ];
    }

    protected function fakeFullCouncil(): void
    {
        $frame = $this->or([
            'claims' => [['id' => 'C1', 'text' => 'root cause claim']],
            'hypotheses' => [
                ['id' => 'H1', 'statement' => 'retry_after below timeout causes duplicates', 'kill_conditions' => ['logs show no duplicate deliveries']],
                ['id' => 'H2', 'statement' => 'worker crash loses jobs', 'kill_conditions' => ['failed_jobs table is empty']],
            ],
            'open_questions' => [],
        ]);

        $position = fn (string $stance1, array $refs1) => $this->or([
            'stances' => [
                ['hypothesis_id' => 'H1', 'stance' => $stance1, 'evidence_refs' => $refs1, 'reasoning_only' => false, 'reasoning' => 'cited', 'confidence' => 0.8],
                ['hypothesis_id' => 'H2', 'stance' => 'underdetermined', 'evidence_refs' => [], 'reasoning_only' => true, 'reasoning' => 'no data', 'confidence' => 0.4],
            ],
            'new_hypotheses' => [],
            'proposed_falsifiers' => [['hypothesis_id' => 'H2', 'check' => 'query failed_jobs count']],
        ]);

        $attackNoDefeat = $this->or(['defeaters' => [], 'concessions' => []]);
        $attackDefeatH2 = $this->or([
            'defeaters' => [
                ['hypothesis_id' => 'H2', 'target_member' => 'Member A', 'text' => 'E1 shows jobs persisted', 'evidence_refs' => ['E1'], 'kill_condition' => 'failed_jobs table is empty'],
                ['hypothesis_id' => 'H1', 'target_member' => 'Member B', 'text' => 'fabricated', 'evidence_refs' => ['Z9'], 'kill_condition' => 'nope'],
            ],
            'concessions' => [],
        ]);

        $verdict = $this->or([
            'accepted' => true,
            'confidence' => 'medium',
            'summary' => 'H1 is the strongest surviving hypothesis.',
            'recommended_plan' => ['raise retry_after above the job timeout'],
            'findings' => [],
            'supported_hypotheses' => ['H1 supported by E1,E2'],
            'weak_hypotheses' => [],
            'defeated' => ['H2 (testimony defeat via E1)'],
            'dissents' => ['one member found H1 underdetermined'],
            'proposed_discriminators' => ['run a duplicate-delivery log query over 24h'],
            'risks' => [],
        ]);

        // Order: frame, 5 positions, 5 attacks, verdict.
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push($frame)
                ->push($position('support', ['E1']))
                ->push($position('support', ['E2']))
                ->push($position('support', ['E1', 'E2']))
                ->push($position('underdetermined', []))
                ->push($position('support', ['E1', 'BOGUS']))
                ->push($attackDefeatH2)
                ->push($attackNoDefeat)
                ->push($attackNoDefeat)
                ->push($attackNoDefeat)
                ->push($attackNoDefeat)
                ->push($verdict),
        ]);
    }

    protected function makeCouncilTask(): BuddyTask
    {
        return BuddyTask::factory()->create([
            'problem_type' => 'bug',
            'operation' => 'council',
            'evidence' => ['queue logs show duplicate deliveries at 60s', 'failed_jobs has 12 rows'],
        ]);
    }

    public function test_full_council_deliberation_produces_verdict_and_transcript(): void
    {
        $this->fakeFullCouncil();

        $task = $this->makeCouncilTask();

        $verdict = app(EvaluatorOptimizerService::class)->council($task);

        $this->assertSame('unique_survivor', $verdict['output_mode']);
        $this->assertTrue($verdict['accepted']);

        $tally = $verdict['mechanical_tally'];
        $this->assertSame('testimony_defeated', $tally['per_hypothesis']['H2']['status']);
        $this->assertSame('live', $tally['per_hypothesis']['H1']['status']);
        $this->assertGreaterThanOrEqual(1, $tally['per_hypothesis']['H1']['fabricated_ref_count']);
        $this->assertSame(['H1'], $tally['ranking']);
        $this->assertSame('testimony', $tally['disclosure']['defeat_ceiling']);

        $run = BuddyRun::query()->where('buddy_task_id', $task->id)->first();
        $this->assertSame('council', $run->run_type);
        $this->assertGreaterThan(0, $run->token_usage['prompt_tokens']);

        $recommendation = BuddyRecommendation::query()->where('buddy_run_id', $run->id)->first();
        $this->assertNotNull($recommendation->council);
        $this->assertSame('unique_survivor', $recommendation->council['output_mode']);

        $transcripts = BuddyArtifact::query()->where('buddy_task_id', $task->id)->get();
        $this->assertGreaterThanOrEqual(4, $transcripts->count());

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
    }

    public function test_quorum_failure_fails_the_run(): void
    {
        $frame = $this->or([
            'hypotheses' => [['id' => 'H1', 'statement' => 's', 'kill_conditions' => ['k']]],
        ]);
        $garbage = ['choices' => [['message' => ['content' => 'not json at all']]], 'usage' => []];

        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push($frame)
            ->push($garbage)->push($garbage)->push($garbage)->push($garbage)->push($garbage)
            // re-asks, one per member
            ->push($garbage)->push($garbage)->push($garbage)->push($garbage)->push($garbage),
        ]);

        $task = $this->makeCouncilTask();

        $this->expectExceptionMessage('quorum');

        app(EvaluatorOptimizerService::class)->council($task);
    }

    public function test_mcp_council_evaluate_sets_operation_and_dispatches(): void
    {
        Queue::fake();
        config(['buddy.api.auth_required' => true, 'queue.default' => 'database']);

        $client = ApiClient::create(['name' => 'council-test', 'project' => 'buddy']);
        $key = app(ApiKeyService::class)->issue($client, [ApiScope::TasksWrite, ApiScope::TasksRead])['plaintext'];
        $task = BuddyTask::factory()->create(['api_client_id' => $client->id]);

        $this->withToken($key)->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'buddy.council_evaluate', 'arguments' => [
                'task_id' => $task->ulid,
                'criticality' => 'critical',
                'reason' => 'irreversible production data migration must not proceed on a wrong assumption',
            ]],
        ])->assertOk();

        $this->assertSame('council', $task->refresh()->operation);
        $this->assertSame(TaskStatus::Evaluating, $task->status);
        $this->assertDatabaseHas('outbox_messages', ['message_key' => $task->ulid.':council']);
    }

    public function test_gate_denies_fresh_task_without_declared_criticality(): void
    {
        $task = $this->makeCouncilTask();

        $gate = app(CouncilGate::class)->evaluate($task, null, null);

        $this->assertFalse($gate['allowed']);
        $this->assertSame('not_eligible', $gate['basis']);
    }

    public function test_gate_denies_short_reason_and_allows_substantive_one(): void
    {
        $task = $this->makeCouncilTask();
        $gate = app(CouncilGate::class);

        $this->assertFalse($gate->evaluate($task, 'critical', 'because')['allowed']);
        $this->assertTrue($gate->evaluate($task, 'critical', str_repeat('security-critical auth boundary decision ', 2))['allowed']);
    }

    public function test_gate_allows_task_with_distress_markers(): void
    {
        $task = $this->makeCouncilTask();
        $task->attempt_count = 2;
        $task->save();

        $gate = app(CouncilGate::class)->evaluate($task, null, null);

        $this->assertTrue($gate['allowed']);
        $this->assertSame('markers', $gate['basis']);
        $this->assertContains('attempt_count>=2', $gate['markers']);
    }

    public function test_gate_allows_after_rejected_evaluation_and_blocks_second_council(): void
    {
        $task = $this->makeCouncilTask();
        $run = BuddyRun::create([
            'buddy_task_id' => $task->id,
            'run_number' => 1,
            'run_type' => 'evaluation',
            'status' => 'completed',
        ]);
        BuddyRecommendation::create([
            'buddy_run_id' => $run->id,
            'accepted' => false,
            'confidence' => 'low',
            'summary' => 'rejected',
        ]);

        $gate = app(CouncilGate::class);

        $this->assertSame('markers', $gate->evaluate($task, null, null)['basis']);

        BuddyRun::create([
            'buddy_task_id' => $task->id,
            'run_number' => 2,
            'run_type' => 'council',
            'status' => 'completed',
        ]);

        $second = $gate->evaluate($task, 'critical', str_repeat('still critical ', 5));
        $this->assertFalse($second['allowed']);
        $this->assertSame('council_already_convened', $second['basis']);
    }

    public function test_council_job_enforces_daily_cap(): void
    {
        config(['buddy_agents.council.max_per_day' => 0]);

        $task = $this->makeCouncilTask();

        $job = new CouncilDeliberateJob($task);
        $job->handle(app(EvaluatorOptimizerService::class), app(TaskStateService::class));

        $this->assertSame(0, BuddyRun::query()->count());
    }

    public function test_reaper_fails_tasks_with_long_expired_leases(): void
    {
        $task = BuddyTask::factory()->create([
            'status' => TaskStatus::Evaluating,
            'claimed_by' => 'dead-worker',
            'lease_expires_at' => now()->subHours(2),
        ]);

        $reaped = app(TaskStateService::class)->reapExpiredLeases();

        $this->assertSame(1, $reaped);
        $this->assertSame(TaskStatus::Failed, $task->refresh()->status);
    }
}
