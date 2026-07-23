<?php

namespace Tests\Feature;

use App\Models\BuddyTask;
use App\Models\TaskFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FeedbackHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function feedback(string $outcome, ?int $score, int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            TaskFeedback::create([
                'buddy_task_id' => BuddyTask::factory()->completed()->create()->id,
                'outcome' => $outcome,
                'score' => $score,
                'source' => 'agent_close',
            ]);
        }
    }

    public function test_healthy_window_passes(): void
    {
        $this->feedback('resolved', 100, 5);

        $this->artisan('buddy:feedback-health')
            ->assertSuccessful()
            ->expectsOutputToContain('Healthy');
    }

    public function test_low_mean_score_degrades(): void
    {
        Log::spy();
        $this->feedback('not_useful', 0, 4);
        $this->feedback('resolved', 100, 1);

        $this->artisan('buddy:feedback-health')->assertFailed();

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'BUDDY_FEEDBACK_DEGRADED'
                && in_array('mean_score', $context['breaches'], true)
                && in_array('not_useful_rate', $context['breaches'], true));
    }

    public function test_thin_samples_never_alert_on_score(): void
    {
        $this->feedback('not_useful', 0, 2);

        $this->artisan('buddy:feedback-health')
            ->assertSuccessful()
            ->expectsOutputToContain('Healthy');
    }

    public function test_failed_run_rate_degrades_independently(): void
    {
        Log::spy();
        $task = BuddyTask::factory()->completed()->create();
        $task->runs()->create(['run_type' => 'evaluation', 'status' => 'failed']);

        $this->artisan('buddy:feedback-health')->assertFailed();

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context['breaches'] === ['failed_run_rate']);
    }
}
