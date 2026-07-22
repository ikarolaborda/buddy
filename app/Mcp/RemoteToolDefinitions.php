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
                'name' => 'buddy.council_evaluate',
                'description' => 'Convene the LLM council (5 models, falsification-first deliberation) on a task. Slow (2-10 minutes) and costly, so it is GATED: allowed only after the task has a failed or rejected evaluation (check council_eligible on buddy.get_task_status), or with criticality="critical" plus a substantive reason for subjects that cannot be missed (security, irreversible changes, repeatedly bad implementations). Prefer buddy.evaluate_task first. Supply rich evidence: members may only defeat hypotheses by citing your evidence items. An underdetermined verdict with discriminating checks is a normal, honest outcome.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => $taskId,
                        'criticality' => [
                            'type' => 'string',
                            'enum' => ['critical'],
                            'description' => 'Declare only when the subject is genuinely critical and has not yet earned escalation through a failed or rejected evaluation.',
                        ],
                        'reason' => [
                            'type' => 'string',
                            'description' => 'Why this subject is critical or cannot be missed (min 30 chars). Recorded for audit.',
                        ],
                    ],
                    'required' => ['task_id'],
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
