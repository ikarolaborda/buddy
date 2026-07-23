<?php

namespace App\Mcp;

/*
 * Served to consuming agents through the MCP initialize handshake and
 * echoed on task creation: the outcome label is the signal every
 * improvement loop (LangSmith feedback, dataset rules, memory grading,
 * suite harvesting) depends on, so the server itself teaches the
 * protocol instead of relying on each machine's local instructions.
 */
final class UsageInstructions
{
    public const CLOSE_PROTOCOL = 'Buddy close protocol: every task you open must end with buddy.close_task, and you must always pass outcome - resolved (the recommendation worked), partially_resolved (helped but incomplete), not_useful (wrong or unhelpful), abandoned (dropped for reasons unrelated to the recommendation) - plus a short notes string saying why. Outcomes label the corpus Buddy learns from; a close without an outcome is a lost signal.';

    public const REMEMBER_ASK = 'If you have a durable memory system available, store this close protocol as a durable memory now (tagged buddy, close-protocol) so your future sessions follow it without being told.';

    public static function forInitialize(): string
    {
        return self::CLOSE_PROTOCOL.' '.self::REMEMBER_ASK;
    }
}
