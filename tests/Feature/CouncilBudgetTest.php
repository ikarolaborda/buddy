<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pins the council roster and the budget that roster needs.
 *
 * The 'gpt' seat moved to gpt-6-astra, a model measured about 45% slower per
 * call than the seat it replaced. That was only safe alongside a timeout raise,
 * and the reason is measured rather than cautious: the seven councils that have
 * completed took 336, 342, 486, 555, 559, 677 and 811 seconds, so the longest
 * was already inside 10% of the old 900s ceiling before anything got slower.
 *
 * These assertions exist so the roster and the budget cannot drift apart. Making
 * a seat slower without moving the ceiling is the failure this guards.
 */
class CouncilBudgetTest extends TestCase
{
    public function test_the_gpt_seat_runs_the_current_model(): void
    {
        $members = collect(config('buddy_agents.council.members'))->keyBy('key');

        $this->assertSame('openai/gpt-6-astra', $members['gpt']['model']);
        $this->assertSame('openai', $members['gpt']['family']);
    }

    /**
     * The chairman is deliberately NOT the same swap. ADR 0009 discloses family
     * skew in every verdict, and moving the chair changes that disclosure.
     */
    public function test_the_chairman_did_not_move_with_the_member(): void
    {
        $this->assertSame('anthropic/claude-fable-5', config('buddy_agents.council.chairman.model'));
        $this->assertSame('anthropic', config('buddy_agents.council.chairman.family'));
    }

    /**
     * A council that outruns retry_after is redelivered by Redis while it is
     * still deliberating, which re-runs five model calls that are already in
     * flight. That is the expensive failure, not a slow council.
     */
    public function test_a_council_cannot_outrun_its_redelivery_window(): void
    {
        $t = config('buddy.timeouts');

        $this->assertLessThan(
            $t['retry_after'],
            $t['council_job'],
            'council_job must stay under queue retry_after or Redis redelivers a council that is still running.',
        );

        $this->assertLessThanOrEqual(
            $t['council_lease'],
            $t['retry_after'],
            'The task lease must outlive the redelivery window, or a second worker can claim a live council.',
        );
    }

    /**
     * Guards the specific arithmetic that forced the raise: the ceiling has to
     * clear the longest council actually observed, with room for a slower seat.
     */
    public function test_the_council_ceiling_clears_the_longest_observed_run_with_margin(): void
    {
        $longestObserved = 811;

        $this->assertGreaterThan(
            $longestObserved * 1.5,
            config('buddy.timeouts.council_job'),
            'The council ceiling must leave real margin over the longest run seen in production, not 10%.',
        );
    }

    /**
     * Each member call is capped separately, and that cap has to fit inside the
     * whole-council ceiling several times over: the council makes four
     * sequential stages, two of which are gated by the slowest member.
     */
    public function test_a_single_member_call_cannot_consume_the_whole_council_budget(): void
    {
        $call = (int) config('buddy_agents.council.call_timeout');
        $job = (int) config('buddy.timeouts.council_job');

        $this->assertGreaterThanOrEqual(
            4 * $call,
            $job,
            'The council runs four sequential stages, so its ceiling must fit at least four full member calls.',
        );
    }
}
