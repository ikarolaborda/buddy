<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Jobs\EvaluateTaskJob;
use App\Models\BuddyTask;
use App\Services\EvaluatorOptimizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins the behaviour that made #[Tries(3)] decorative.
 *
 * executeRun used to move the task to Failed on ANY exception. Failed is
 * terminal, and EvaluateTaskJob::handle() opens with a refresh() and an
 * isTerminal() early return, so the retry re-entered, saw a terminal task and
 * returned having done nothing. Every transient failure was therefore fatal on
 * the first attempt while appearing to have been retried three times.
 *
 * It stayed invisible because the whole suite passed without covering it, and
 * because it only becomes expensive when the provider starts failing: it went
 * unnoticed under a model that answered in 45s and surfaced immediately under
 * one that takes about 67s against a 120s timeout.
 */
class EvaluationRetrySurvivabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transient_provider_failure_leaves_the_task_retryable(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'The server had an error processing your request.']], 500),
        ]);

        $task = BuddyTask::factory()->create(['status' => TaskStatus::Pending]);

        try {
            app(EvaluatorOptimizerService::class)->evaluate($task);
        } catch (\Throwable) {
            // The service rethrows so the queue can retry; that is the point.
        }

        $task->refresh();

        $this->assertFalse(
            $task->isTerminal(),
            'A transient provider failure must not make the task terminal, or the queue retry becomes a no-op.',
        );
        $this->assertSame(TaskStatus::Evaluating, $task->status);
    }

    public function test_the_failed_run_is_still_recorded_for_a_transient_failure(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'The server had an error processing your request.']], 500),
        ]);

        $task = BuddyTask::factory()->create(['status' => TaskStatus::Pending]);

        try {
            app(EvaluatorOptimizerService::class)->evaluate($task);
        } catch (\Throwable) {
        }

        // The task stays retryable but the attempt must remain visible: one run
        // row per attempt is what keeps the history honest.
        $this->assertSame(1, $task->runs()->count());
        $this->assertSame('failed', $task->runs()->first()->status->value);
    }

    public function test_a_permanent_failure_still_terminates_the_task(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'Invalid schema field supplied']], 400),
        ]);

        $task = BuddyTask::factory()->create(['status' => TaskStatus::Pending]);

        try {
            app(EvaluatorOptimizerService::class)->evaluate($task);
        } catch (\Throwable) {
        }

        $this->assertTrue(
            $task->refresh()->isTerminal(),
            'A permanent failure must terminate rather than burn three attempts on work that cannot succeed.',
        );
    }

    /**
     * The counterpart to leaving transient failures non-terminal: something has
     * to close the task when no further attempt is coming, or it sits in
     * Evaluating for ever.
     */
    public function test_the_job_terminates_the_task_once_the_queue_gives_up(): void
    {
        $task = BuddyTask::factory()->create(['status' => TaskStatus::Evaluating]);

        (new EvaluateTaskJob($task))->failed(new \RuntimeException('status code 500'));

        $this->assertSame(TaskStatus::Failed, $task->refresh()->status);
    }

    public function test_giving_up_does_not_reopen_an_already_terminal_task(): void
    {
        $task = BuddyTask::factory()->create(['status' => TaskStatus::Completed]);

        (new EvaluateTaskJob($task))->failed(new \RuntimeException('status code 500'));

        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
    }

    /**
     * config/buddy.php documents "provider < job < worker < retry_after" and
     * nothing enforced it. Worse, 'provider' used to read BUDDY_PROVIDER_TIMEOUT
     * which no code consumed, while the value actually sent to the HTTP client
     * came from BUDDY_EVALUATION_TIMEOUT, so the documented invariant described
     * numbers that were never in effect.
     */
    public function test_the_documented_timeout_ordering_actually_holds(): void
    {
        $t = config('buddy.timeouts');

        $this->assertLessThan($t['job'], $t['provider'], 'provider timeout must be under the job timeout');
        $this->assertLessThan($t['worker'], $t['job'], 'job timeout must be under the worker timeout');
        $this->assertLessThan($t['retry_after'], $t['worker'], 'worker timeout must be under queue retry_after');
    }

    /**
     * Asserted against the config SOURCE, not the resolved values.
     *
     * Comparing config('buddy.timeouts.provider') to the agent profile's
     * timeout looks like the obvious test and is worthless: a local .env
     * pinning BUDDY_EVALUATION_TIMEOUT to the same number that the wrong knob
     * defaults to makes both sides equal, and the assertion passes while the
     * two are reading different env vars. Mutation testing caught exactly that.
     * Binding the env var name is the thing that cannot be satisfied by
     * coincidence.
     */
    public function test_the_provider_timeout_is_bound_to_the_env_var_the_agent_profiles_read(): void
    {
        $buddy = file_get_contents(config_path('buddy.php'));
        $agents = file_get_contents(config_path('buddy_agents.php'));

        $this->assertMatchesRegularExpression(
            "/'provider' => \(int\) env\('BUDDY_EVALUATION_TIMEOUT'/",
            $buddy,
            'timeouts.provider must read the same env var the HTTP client is actually given.',
        );

        $this->assertStringContainsString(
            "env('BUDDY_EVALUATION_TIMEOUT'",
            $agents,
            'The agent profiles must read that env var for the timeout they hand to laravel/ai.',
        );

        $this->assertSame(
            config('buddy_agents.profiles.evaluator-optimizer.timeout'),
            config('buddy.timeouts.provider'),
            'Sharing one env var, the resolved values must also agree.',
        );
    }
}
