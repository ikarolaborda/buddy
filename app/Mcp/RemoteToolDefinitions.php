<?php

namespace App\Mcp;

use App\Enums\ArtifactType;

class RemoteToolDefinitions
{
    /**
     * The definitions are constant for the life of the process, but they were
     * rebuilt from scratch on every tools/list - a few hundred array
     * allocations per call, on a surface every client hits at least once per
     * session and some hit repeatedly. Memoised per process, which under
     * Octane means once per worker rather than once per request.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $cached = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$cached !== null && config('buddy.mcp.cache_tool_definitions', true)) {
            return self::$cached;
        }

        return self::$cached = self::build();
    }

    /*
     * Drops the memo. Only tests need this; production definitions never
     * change without a deploy.
     */
    public static function flush(): void
    {
        self::$cached = null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function build(): array
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
                        'type' => [
                            'type' => 'string',
                            'enum' => array_map(static fn (ArtifactType $t): string => $t->value, ArtifactType::cases()),
                        ],
                        'content' => ['type' => 'string'],
                        'metadata' => ['type' => 'object'],
                    ],
                    'required' => ['task_id', 'type', 'content'],
                ],
            ],
            [
                'name' => 'buddy.council_evaluate',
                'description' => 'Convene a council on a task. Optional profile="azure" selects the backup: Astra xhigh chair and three GPT-5.5 reviewers with distinct review focuses (one shared model family). Default uses the configured council. Slow and costly; requires failed or rejected evaluation, or criticality="critical" with a substantive reason. Supply rich evidence. Poll buddy.get_task_status for the verdict.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => $taskId,
                        'criticality' => [
                            'type' => 'string',
                            'enum' => ['critical'],
                            'description' => 'Declare only when the subject is genuinely critical and has not yet earned escalation through a failed or rejected evaluation.',
                        ],
                        'profile' => ['type' => 'string', 'enum' => config('buddy_agents.council.profiles'), 'description' => 'Council provider for this task only.'],
                        'reason' => [
                            'type' => 'string',
                            'description' => 'Why this subject is critical or cannot be missed (min 30 chars). Recorded for audit.',
                        ],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'buddy.intervene',
                'description' => 'Diagnose Buddy service health or recover a failed evaluation using bounded agent context. Requires interventions:execute. Recovery is limited to recorded transient operational failures, runs once per original task, and returns a linked task to poll. Does not execute arbitrary commands or override policy, approval, or credential restrictions. Reuse request_id with the identical packet to retrieve its audit result.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => $taskId,
                        'request_id' => ['type' => 'string', 'maxLength' => 128],
                        'action' => ['type' => 'string', 'enum' => ['diagnose_health', 'recover_evaluation']],
                        'blocker' => ['type' => 'string', 'enum' => ['operational_failure', 'capability_missing', 'policy_denied', 'approval_required', 'credential_restriction']],
                        'context' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'summary' => ['type' => 'string', 'maxLength' => 4000, 'description' => 'Visible task summary and current state. Never send secrets or private reasoning.'],
                                'session_id' => ['type' => 'string', 'maxLength' => 255],
                                'last_error' => ['type' => 'string', 'maxLength' => 2000],
                                'attempted_actions' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'string', 'maxLength' => 500]],
                            ],
                            'required' => ['summary'],
                        ],
                    ],
                    'required' => ['task_id', 'request_id', 'action', 'blocker', 'context'],
                ],
            ],
            [
                'name' => 'buddy.close_task',
                /*
                 * The close protocol travels on the TOOL, not only on the
                 * initialize handshake. Under the 2026-07-28 stateless
                 * revision a client need never call initialize, and that
                 * handshake was buddy's only channel for teaching this -
                 * losing it would quietly erode the outcome labels the whole
                 * learning corpus is built from. tools/list is the one call
                 * every client makes, so the text rides there, sourced from
                 * the same constant so the two can never drift apart.
                 */
                'description' => 'Close a task. '.UsageInstructions::CLOSE_PROTOCOL.' Optionally add notes and a learnings summary stored into Buddy memory.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => $taskId,
                        'learnings_summary' => ['type' => 'string'],
                        'outcome' => ['type' => 'string', 'enum' => ['resolved', 'partially_resolved', 'not_useful', 'abandoned'], 'description' => 'How the recommendation turned out. Pass this on every close: resolved = it worked, partially_resolved = helped but incomplete, not_useful = wrong or unhelpful, abandoned = dropped for unrelated reasons.'],
                        'notes' => ['type' => 'string', 'description' => 'Optional context about the outcome.'],
                    ],
                    'required' => ['task_id'],
                ],
            ],
        ];
    }
}
