<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\BuddyIntervention;
use App\Models\BuddyTask;
use App\Models\BuddyTaskEvent;
use App\Models\BuddyViewSession;
use App\Services\Edge\TaskProgressService;
use App\Services\Edge\ViewTicketService;
use App\Services\Interventions\InterventionService;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/*
 * Concurrency gates for the edge foundation (plan §14): sequence allocation,
 * single-use ticket exchange and delegated recovery must hold across
 * independent database connections, which only PostgreSQL and real
 * processes can exercise. SQLite serialises everything and proves nothing.
 */
class PostgresEdgeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for independent concurrent connections.');
        }
    }

    public function test_concurrent_progress_writers_allocate_a_dense_unique_sequence(): void
    {
        config(['buddy.edge.progress' => true]);
        $task = BuddyTask::factory()->create();

        $results = $this->race(4, function () use ($task): array {
            $sequences = [];
            foreach (range(1, 3) as $attempt) {
                $sequences[] = app(TaskProgressService::class)->phase(BuddyTask::findOrFail($task->id), 'memory')?->sequence;
            }

            return ['sequences' => $sequences];
        });

        $allocated = array_merge(...array_column($results, 'sequences'));
        sort($allocated);
        $this->assertSame(range(1, 12), $allocated);

        $stored = BuddyTaskEvent::query()->where('buddy_task_id', $task->id)->orderBy('sequence')->pluck('sequence')->all();
        $this->assertSame(range(1, 12), $stored);
        $this->assertSame(12, BuddyTaskEvent::query()->where('buddy_task_id', $task->id)->distinct()->count('sequence'));
        $this->assertSame(12, (int) BuddyTask::query()->whereKey($task->id)->value('progress_sequence'));
    }

    public function test_a_view_ticket_exchanges_exactly_once_across_connections(): void
    {
        $client = ApiClient::create(['name' => 'postgres-edge-race', 'project' => 'buddy']);
        $task = BuddyTask::factory()->create(['api_client_id' => $client->id]);
        $plaintext = app(ViewTicketService::class)->mint($task, $client)['ticket'];

        $results = $this->race(4, fn (): array => [
            'session' => app(ViewTicketService::class)->exchange($plaintext) !== null,
        ]);

        $this->assertCount(1, array_filter(array_column($results, 'session')));
        $this->assertSame(1, BuddyViewSession::count());
    }

    public function test_concurrent_delegated_recoveries_create_exactly_one_child(): void
    {
        $client = ApiClient::create(['name' => 'postgres-edge-race', 'project' => 'buddy']);
        $task = BuddyTask::factory()->failed()->create(['api_client_id' => $client->id]);
        $task->runs()->create(['run_number' => 1, 'run_type' => 'evaluation', 'status' => 'failed', 'error_category' => 'transient']);

        $results = $this->race(4, function (int $number) use ($task, $client): array {
            $response = app(InterventionService::class)->performDelegated(
                BuddyTask::findOrFail($task->id),
                ApiClient::findOrFail($client->id),
                ['request_id' => 'sup-'.$number, 'action' => 'recover_evaluation', 'blocker' => 'operational_failure', 'context' => ['summary' => 'Concurrent supervisor fixture.']],
                'delegation:test',
            );

            return ['status' => $response['status'], 'task' => $response['result']['recovery_task_id'] ?? null];
        });

        $this->assertSame(['dispatched'], array_values(array_unique(array_column($results, 'status'))));
        $this->assertCount(1, array_unique(array_column($results, 'task')));
        $this->assertSame(1, BuddyTask::where('recovery_of_task_id', $task->id)->count());
        $this->assertSame(4, BuddyIntervention::count());
        $this->assertSame(1, DB::table('outbox_messages')->where('topic', 'buddy.task.submitted')->count());
    }

    /**
     * Forks independent processes that each open their own connection, then
     * releases them together so every writer contends for the same rows.
     *
     * @param  Closure(int): array<string, mixed>  $work
     * @return array<int, array<string, mixed>>
     */
    private function race(int $workers, Closure $work): array
    {
        $children = [];
        DB::disconnect();

        foreach (range(1, $workers) as $number) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                try {
                    DB::purge();
                    Queue::fake();
                    fwrite($sockets[1], json_encode($work($number)));
                    fclose($sockets[1]);
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite($sockets[1], json_encode(['error' => $e::class, 'message' => $e->getMessage()]));
                    fclose($sockets[1]);
                    exit(1);
                }
            }
            fclose($sockets[1]);
            $children[] = [$pid, $sockets[0]];
        }

        foreach ($children as [, $socket]) {
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

        return $results;
    }
}
