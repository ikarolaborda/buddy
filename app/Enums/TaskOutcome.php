<?php

namespace App\Enums;

enum TaskOutcome: string
{
    case Resolved = 'resolved';
    case PartiallyResolved = 'partially_resolved';
    case NotUseful = 'not_useful';
    case Abandoned = 'abandoned';

    /*
     * Basis points for the LangSmith feedback score. Abandoned is null:
     * a task dropped for unrelated reasons says nothing about whether
     * the recommendation was good, so it must not poison the mean.
     */
    public function score(): ?int
    {
        return match ($this) {
            self::Resolved => 100,
            self::PartiallyResolved => 50,
            self::NotUseful => 0,
            self::Abandoned => null,
        };
    }
}
