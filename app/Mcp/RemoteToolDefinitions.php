<?php

namespace App\Mcp;

class RemoteToolDefinitions
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $taskId = ['type' => 'string', 'description' => 'Task ULID returned by buddy.submit_problem.'];

        return [
            [
                'name' => 'buddy.submit_problem',
                'description' => 'Submit a problem packet to Buddy. Returns a task ULID to poll.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'source_agent' => ['type' => 'string'],
                        'task_summary' => ['type' => 'string'],
                        'problem_type' => ['type' => 'string', 'description' => 'bug, test_failure, performance, architecture, integration, configuration, security, prompt_refinement, ambiguous, or other.'],
                        'repo' => ['type' => 'string'],
                        'branch' => ['type' => 'string'],
                        'constraints' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'evidence' => ['type' => 'array', 'items' => ['type' => 'object']],
                        'requested_outcome' => ['type' => 'string'],
                    ],
                    'required' => ['source_agent', 'task_summary', 'problem_type'],
                ],
            ],
            [
                'name' => 'buddy.get_task_status',
                'description' => 'Get the current status and recommendation for a Buddy task.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['task_id' => $taskId],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'buddy.evaluate_task',
                'description' => 'Trigger asynchronous evaluation of a submitted task.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['task_id' => $taskId],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'buddy.refine_prompt',
                'description' => 'Refine a vague task into an execution-ready engineering brief (synchronous).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['task_id' => $taskId],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'buddy.attach_artifact',
                'description' => 'Attach an artifact (log, diff, test output) to a task.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => $taskId,
                        'type' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'metadata' => ['type' => 'object'],
                    ],
                    'required' => ['task_id', 'type', 'content'],
                ],
            ],
            [
                'name' => 'buddy.close_task',
                'description' => 'Close a task, optionally recording the outcome (fed back into recommendation quality tracking) and a learnings summary stored into Buddy memory.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => $taskId,
                        'learnings_summary' => ['type' => 'string'],
                        'outcome' => ['type' => 'string', 'enum' => ['resolved', 'partially_resolved', 'not_useful', 'abandoned'], 'description' => 'How useful the recommendation turned out to be.'],
                        'notes' => ['type' => 'string', 'description' => 'Optional context about the outcome.'],
                    ],
                    'required' => ['task_id'],
                ],
            ],
        ];
    }
}
