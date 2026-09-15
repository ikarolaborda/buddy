<?php

namespace App\Services\Interventions;

use App\Models\BuddyTask;
use App\Services\Knowledge\Extraction\KnowledgeContentPolicy;

final class InterventionContext
{
    public function __construct(private KnowledgeContentPolicy $policy) {}

    public function capture(BuddyTask $task, array $agentContext): array
    {
        $budget = 12000;
        $truncated = false;
        $clean = function (mixed $value) use (&$budget, &$truncated): string {
            $text = $this->redact(is_string($value) ? $value : (string) json_encode($value));
            $truncated = $truncated || mb_strlen($text) > min(2000, $budget);
            $text = mb_substr($text, 0, min(2000, $budget));
            $budget -= mb_strlen($text);

            return $text;
        };
        $result = [
            'task_id' => $task->ulid,
            'source_agent' => $clean($task->source_agent),
            'repo' => $clean($task->repo ?? ''),
            'branch' => $clean($task->branch ?? ''),
            'summary' => $clean($task->task_summary),
            'constraints' => $clean($task->constraints ?? []),
            'agent_context' => array_map($clean, $agentContext),
            'evidence' => [],
            'artifacts' => [],
        ];
        foreach (array_slice((array) $task->evidence, 0, 10) as $item) {
            if ($budget <= 0) {
                break;
            }
            $result['evidence'][] = $clean($item);
        }
        foreach ($task->artifacts()->where('type', '!=', 'council_transcript')->latest('id')->limit(5)->get() as $artifact) {
            if ($budget <= 0) {
                break;
            }
            $result['artifacts'][] = ['type' => $artifact->type->value, 'content' => $clean($artifact->content)];
        }
        $result['truncated'] = $truncated || $budget <= 0;

        return $result;
    }

    public function redact(string $text): string
    {
        $text = preg_replace('/\b(?:set-cookie|cookie|authorization)\s*:[^\r\n]+/i', '[REDACTED_HEADER]', $text) ?? '';
        $text = preg_replace('/([?&](?:token|code|key|secret|session|password)=)[^&\s]+/i', '$1[REDACTED]', $text) ?? '';
        $text = preg_replace('/"(?:api[_-]?key|access[_-]?token|refresh[_-]?token|token|password|secret|cookie)"\s*:\s*"(?:\\\\.|[^"\\\\])*"/i', '"credential":"[REDACTED]"', $text) ?? '';

        return $this->policy->sanitize($text);
    }
}
