<?php

namespace Tests\Feature;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Models\ApiKey;
use App\Models\BuddyIntervention;
use App\Models\BuddyTask;
use App\Services\ApiKeyService;
use App\Services\Interventions\InterventionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PostgresInterventionTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for independent concurrent connections.');
        }
    }

    public function test_same_request_id_recovers_once_across_independent_connections(): void
    {
        $this->race(false);
    }

    public function test_additive_upgrade_preserves_existing_tasks_and_enforces_recovery_uniqueness(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 2, '--force' => true])->assertSuccessful();
        $original = BuddyTask::factory()->failed()->create();
        $other = BuddyTask::factory()->completed()->create();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame(2, BuddyTask::count());
        $this->assertSame('failed', $original->refresh()->status->value);
        $this->assertSame('completed', $other->refresh()->status->value);
        $this->assertNull($original->recovery_of_task_id);
        $this->assertNull($other->recovery_of_task_id);
        BuddyTask::factory()->create(['recovery_of_task_id' => $original->id]);
        try {
            DB::transaction(fn () => BuddyTask::factory()->create(['recovery_of_task_id' => $original->id]));
            $this->fail('Duplicate non-null recovery source was accepted.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(3, BuddyTask::count());
        }
    }

    public function test_different_request_ids_still_recover_a_source_only_once(): void
    {
        $this->race(true);
    }

    private function race(bool $distinctRequests): void
    {
        $client = ApiClient::create(['name' => 'postgres-race', 'project' => 'buddy']);
        $issued = app(ApiKeyService::class)->issue($client, [ApiScope::InterventionsExecute]);
        $key = app(ApiKeyService::class)->verify($issued['plaintext']);
        $task = BuddyTask::factory()->failed()->create(['api_client_id' => $client->id]);
        $task->runs()->create(['run_number' => 1, 'run_type' => 'evaluation', 'status' => 'failed', 'error_category' => 'transient']);
        $children = [];
        DB::disconnect();

        foreach (range(1, 4) as $number) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                try {
                    DB::purge();
                    Queue::fake();
                    $response = app(InterventionService::class)->perform(
                        BuddyTask::findOrFail($task->id),
                        ApiClient::findOrFail($client->id),
                        ApiKey::findOrFail($key->id),
                        ['request_id' => $distinctRequests ? 'race-'.$number : 'race', 'action' => 'recover_evaluation', 'blocker' => 'operational_failure', 'context' => ['summary' => 'Concurrent recovery fixture.']],
                    );
                    fwrite($sockets[1], json_encode(['status' => $response['status'], 'task' => $response['result']['recovery_task_id']]));
                    fclose($sockets[1]);
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite($sockets[1], json_encode(['error' => $e::class]));
                    fclose($sockets[1]);
                    exit(1);
                }
            }
            fclose($sockets[1]);
            $children[] = [$pid, $sockets[0]];
        }

        foreach ($children as [$pid, $socket]) {
            fwrite($socket, '1');
        }
        $results = [];
        foreach ($children as [$pid, $socket]) {
            $results[] = json_decode(stream_get_contents($socket), true);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), json_encode($results));
        }
        DB::purge();
        $this->assertSame(['dispatched'], array_values(array_unique(array_column($results, 'status'))));
        $this->assertCount(1, array_unique(array_column($results, 'task')));
        $this->assertSame(1, BuddyTask::where('recovery_of_task_id', $task->id)->count());
        $this->assertSame($distinctRequests ? 4 : 1, BuddyIntervention::count());
        $this->assertSame(1, DB::table('outbox_messages')->where('topic', 'buddy.task.submitted')->count());
    }
}
