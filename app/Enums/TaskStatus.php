<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Evaluating = 'evaluating';
    case Completed = 'completed';
    case Failed = 'failed';
    case Closed = 'closed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Closed], true);
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Evaluating, self::Closed],
            self::Evaluating => [self::Completed, self::Failed],
            self::Completed => [self::Closed],
            self::Failed, self::Closed => [],
        }, true);
    }
}
