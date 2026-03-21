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
}
