<?php

namespace Tests\Feature;

use App\Models\BuddyTask;
use App\Models\TaskFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CilHarvestTest extends TestCase
{
    use RefreshDatabase;

    protected function labeledTask(bool $verdictAccepted, string $outcome): BuddyTask
    {
        $task = BuddyTask::factory()->completed()->create();

        $run = $task->runs()->create(['run_type' => 'evaluation', 'status' => 'completed']);
        $run->recommendation()->create([
            'accepted' => $verdictAccepted,
            'confidence' => 'high',
            'summary' => 's',
            'recommended_plan' => [],
            'rejected_reasons' => [],
            'required_followups' => [],
            'risks' => [],
            'next_actions' => [],
            'memory_hits' => [],
        ]);

        TaskFeedback::create([
            'buddy_task_id' => $task->id,
            'outcome' => $outcome,
            'score' => $outcome === 'resolved' ? 100 : 0,
            'source' => 'agent_close',
        ]);

        return $task;
    }

    public function test_harvest_writes_reviewable_suite_draft(): void
    {
        $confirmed = $this->labeledTask(true, 'resolved');
        $refuted = $this->labeledTask(true, 'not_useful');

        $output = sys_get_temp_dir().'/harvest-test.json';

        $this->artisan('buddy:cil-harvest', ['--output' => $output])
            ->assertSuccessful()
            ->expectsOutputToContain('Harvested 2 case(s)');

        $suite = json_decode((string) file_get_contents($output), true);

        $this->assertSame('harvested', $suite['kind']);
        $this->assertFalse($suite['frozen']);
        $this->assertCount(2, $suite['cases']);

        $byTask = collect($suite['cases'])->keyBy('provenance.task');

        $this->assertTrue($byTask[$confirmed->ulid]['expected']['accepted']);
        $this->assertFalse($byTask[$refuted->ulid]['expected']['accepted']);
        $this->assertSame('not_useful', $byTask[$refuted->ulid]['provenance']['outcome']);

        unlink($output);
    }

    public function test_harvest_skips_ambiguous_and_unlabeled_tasks(): void
    {
        $this->labeledTask(true, 'partially_resolved');
        BuddyTask::factory()->completed()->create();

        $this->artisan('buddy:cil-harvest', ['--output' => sys_get_temp_dir().'/harvest-empty.json'])
            ->assertSuccessful()
            ->expectsOutputToContain('No harvestable tasks');
    }
}
