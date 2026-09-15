<?php

namespace App\Contracts;

use App\Models\OutboxMessage;

interface OutboxDestination
{
    /**
     * Deliver the message to an external destination. Throw on any failure;
     * the publisher records the error and schedules a retry. Return false
     * when delivery is intentionally deferred (feature disabled) so the
     * attempt does not count against the message.
     */
    public function deliver(OutboxMessage $message): bool;
}
