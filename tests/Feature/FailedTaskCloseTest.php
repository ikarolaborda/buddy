<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Models\BuddyTask;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FailedTaskCloseTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['mcp'])]
    #[TestWith(['rest'])]
    public function test_failed_task_can_be_closed_once_without_losing_its_failed_run(string $transport): void
    {
        config(['buddy.api.auth_required' => true]);
        $client = ApiClient::create(['name' => 'close-test', 'project' => 'buddy']);
        $key = app(ApiKeyService::class)->issue($client, [ApiScope::TasksWrite])['plaintext'];
        $task = BuddyTask::factory()->create(['api_client_id' => $client->id, 'status' => 'failed']);
        $run = $task->runs()->create(['run_number' => 1, 'run_type' => 'council', 'status' => 'failed', 'error_category' => 'transient']);
        $args = ['task_id' => $task->ulid, 'outcome' => 'abandoned', 'notes' => 'Failed canary retained for audit.'];
        $route = $transport === 'mcp' ? '/api/mcp' : '/api/buddy/tasks/'.$task->ulid.'/close';
        $body = $transport === 'mcp' ? ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'buddy.close_task', 'arguments' => $args]] : $args;

        $response = $this->withToken($key)->postJson($route, $body)->assertOk();
        $payload = $transport === 'mcp' ? json_decode($response->json('result.content.0.text'), true) : $response->json();
        $this->assertSame('closed', $payload['status']);
        $this->assertSame('failed', $run->refresh()->status->value);
        $this->assertSame('transient', $run->error_category);
        $this->assertDatabaseHas('task_feedback', ['buddy_task_id' => $task->id, 'outcome' => 'abandoned']);

        $this->withToken($key)->postJson($route, $body);
        $this->assertDatabaseCount('task_feedback', 1);
    }
}
