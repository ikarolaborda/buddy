<?php

namespace Tests\Feature;

use App\Contracts\MemoryGateway;
use App\DTOs\MemoryFeedback;
use App\Enums\TaskOutcome;
use App\Models\BuddyTask;
use App\Services\EvaluatorOptimizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryOutcomeFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function taskWithMemoryReferences(): BuddyTask
    {
        $task = BuddyTask::factory()->completed()->create();

        foreach (['mem-1', 'mem-2', 'mem-1'] as $memoryId) {
            $task->memoryReferences()->create([
                'qdrant_point_id' => $memoryId,
                'memory_id' => $memoryId,
                'backend' => 'hub',
                'similarity_score' => 0.9,
                'memory_summary' => 's',
                'tags' => [],
            ]);
        }

        return $task;
    }

    public function test_resolved_close_marks_referenced_memories_useful(): void
    {
        $memory = $this->spy(MemoryGateway::class);
        $task = $this->taskWithMemoryReferences();

        app(EvaluatorOptimizerService::class)->closeTask($task, null, TaskOutcome::Resolved);

        $memory->shouldHaveReceived('feedback')
            ->twice()
            ->withArgs(fn (MemoryFeedback $feedback) => $feedback->useful === true
                && in_array($feedback->memoryId, ['mem-1', 'mem-2'], true));
    }

    public function test_not_useful_close_marks_referenced_memories_not_useful(): void
    {
        $memory = $this->spy(MemoryGateway::class);
        $task = $this->taskWithMemoryReferences();

        app(EvaluatorOptimizerService::class)->closeTask($task, null, TaskOutcome::NotUseful);

        $memory->shouldHaveReceived('feedback')
            ->twice()
            ->withArgs(fn (MemoryFeedback $feedback) => $feedback->useful === false);
    }

    public function test_abandoned_close_sends_no_memory_feedback(): void
    {
        $memory = $this->spy(MemoryGateway::class);
        $task = $this->taskWithMemoryReferences();

        app(EvaluatorOptimizerService::class)->closeTask($task, null, TaskOutcome::Abandoned);

        $memory->shouldNotHaveReceived('feedback');
    }
}
