<?php

namespace App\Services\Outbox;

use App\Services\Outbox\Handlers\KnowledgePrefetchHandler;
use App\Services\Outbox\Handlers\TaskSubmittedHandler;

/*
 * Explicit topic routing (plan §6). The previous publisher mapped any topic
 * it did not recognise to evaluation dispatch, which meant a typo or a new
 * event type could start paid inference. Unknown topics now have no handler
 * and no destination, and the publisher quarantines them.
 */
final class OutboxTopicRegistry
{
    public const DESTINATION_LOCAL = 'local_queue';

    public const DESTINATION_CLOUDFLARE_EVENTS = 'cloudflare_events';

    public const TOPIC_TASK_SUBMITTED = 'buddy.task.submitted';

    public const TOPIC_KNOWLEDGE_PREFETCH = 'buddy.knowledge.prefetch.requested';

    public const TOPIC_TASK_EVENT = 'buddy.task.event';

    /**
     * @var array<string, array{handler: class-string|null, destinations: array<int, string>}>
     */
    private array $topics = [
        self::TOPIC_TASK_SUBMITTED => [
            'handler' => TaskSubmittedHandler::class,
            'destinations' => [self::DESTINATION_LOCAL],
        ],
        self::TOPIC_KNOWLEDGE_PREFETCH => [
            'handler' => KnowledgePrefetchHandler::class,
            'destinations' => [self::DESTINATION_LOCAL],
        ],
        self::TOPIC_TASK_EVENT => [
            'handler' => null,
            'destinations' => [self::DESTINATION_CLOUDFLARE_EVENTS],
        ],
    ];

    public function knows(string $topic): bool
    {
        return array_key_exists($topic, $this->topics);
    }

    /**
     * @return array<int, string>
     */
    public function topics(): array
    {
        return array_keys($this->topics);
    }

    /**
     * @return array<int, string>
     */
    public function destinationsFor(string $topic): array
    {
        return $this->topics[$topic]['destinations'] ?? [];
    }

    /**
     * @return class-string|null
     */
    public function handlerFor(string $topic): ?string
    {
        return $this->topics[$topic]['handler'] ?? null;
    }
}
