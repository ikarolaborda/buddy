<?php

namespace App\Jobs;

use App\Contracts\EcosystemKnowledgeGateway;
use App\Models\BuddyTask;
use App\Services\Knowledge\EcosystemKnowledgeQueryFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

#[Tries(2)]
#[Backoff(2, 10)]
#[Timeout(5)]
#[FailOnTimeout]
class PrefetchEcosystemKnowledgeJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected BuddyTask $task,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->task->ulid;
    }

    public function handle(
        EcosystemKnowledgeGateway $knowledge,
        EcosystemKnowledgeQueryFactory $queries,
    ): void {
        $this->task->refresh();

        if ($this->task->isTerminal() || ! config('buddy.knowledge.prefetch_enabled')) {
            return;
        }

        $query = $queries->forTask($this->task);

        if ($query->query === '') {
            $this->persist([], 'skipped', $query->hash(), null);

            return;
        }

        $page = $knowledge->search($query);

        if ($page->degraded) {
            Log::info('Ecosystem knowledge prefetch degraded', [
                'task_ulid' => $this->task->ulid,
                'reason' => $page->degradedReason,
            ]);

            $this->persist([], 'degraded', $query->hash(), $page->degradedReason);

            return;
        }

        $this->persist(
            array_map(static fn ($hit): array => $hit->toArray(), $page->results),
            'ready',
            $query->hash(),
            null,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $context
     */
    protected function persist(array $context, string $status, string $hash, ?string $error): void
    {
        BuddyTask::query()->whereKey($this->task->id)->update([
            'knowledge_context' => $context,
            'knowledge_context_status' => $status,
            'knowledge_context_hash' => $hash,
            'knowledge_context_fetched_at' => now(),
            'knowledge_context_error' => $error,
        ]);
    }
}
