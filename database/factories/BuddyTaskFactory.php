<?php

namespace Database\Factories;

use App\Enums\ProblemType;
use App\Enums\TaskStatus;
use App\Models\BuddyTask;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BuddyTask>
 */
class BuddyTaskFactory extends Factory
{
    protected $model = BuddyTask::class;

    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'source_agent' => $this->faker->randomElement(['claude', 'cursor', 'copilot', 'aider']),
            'repo' => 'org/project',
            'branch' => 'feature/fix-something',
            'task_summary' => $this->faker->sentence(10),
            'problem_type' => $this->faker->randomElement(ProblemType::cases()),
            'constraints' => ['preserve backward compatibility'],
            'evidence' => [],
            'requested_outcome' => $this->faker->sentence(6),
            'status' => TaskStatus::Pending,
            'attempt_count' => 0,
        ];
    }

    public function evaluating(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Evaluating]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Completed]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Failed]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Closed]);
    }
}
