<?php

namespace Tests\Feature;

use App\Services\Council\CouncilClient;
use Illuminate\Support\Facades\Http;
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
     * The per-call ceiling has to clear how long a call actually takes.
     *
     * Measured on the real falsification payload at the current output budget:
     * 221 seconds, finish_reason=stop, valid JSON. Against the old 300s cap that
     * is 26% headroom, which is the same margin that made the council ceiling
     * unsafe in the first place.
     */
    public function test_a_member_call_ceiling_clears_the_longest_measured_call(): void
    {
        $measured = 221;

        $this->assertGreaterThan(
            $measured * 1.5,
            (int) config('buddy_agents.council.call_timeout'),
            'A member call takes about 221s at the current output budget; the cap must leave real margin over that.',
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

    /**
     * The budget that truncated a live member.
     *
     * Replaying the 2026-09-06 falsification round against the gpt seat came
     * back finish_reason=length, completion_tokens=8000, reasoning_tokens=6877.
     * Reasoning had taken 86% of the shared budget and the JSON was cut off
     * mid-string. Successful attacks from the other seats ran 688-1919 tokens,
     * so the answer is small; it is the thinking that has to fit alongside it.
     */
    public function test_the_output_budget_leaves_room_to_answer_after_reasoning(): void
    {
        $observedReasoning = 6877;
        $observedAnswer = 1919;

        $this->assertGreaterThan(
            $observedReasoning + $observedAnswer,
            (int) config('buddy_agents.council.max_output_tokens'),
            'max_tokens is shared by reasoning and response, so it must clear both or the member is cut off mid-JSON.',
        );
    }

    /**
     * A truncated reply and a malformed one need different responses. Re-asking
     * a truncated member on the same budget reproduces the truncation and bills
     * for it twice, which is exactly what production did.
     */
    public function test_a_truncated_reply_is_retried_with_less_reasoning_and_named_as_truncation(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push([
                    'choices' => [['message' => ['content' => '{"defeaters": [{"hypo'], 'finish_reason' => 'length']],
                    'usage' => ['prompt_tokens' => 9054, 'completion_tokens' => 8000, 'completion_tokens_details' => ['reasoning_tokens' => 6877]],
                ])
                ->push([
                    'choices' => [['message' => ['content' => '{"defeaters": [{"hypo'], 'finish_reason' => 'length']],
                    'usage' => ['prompt_tokens' => 9054, 'completion_tokens' => 8000, 'completion_tokens_details' => ['reasoning_tokens' => 6877]],
                ]),
        ]);

        $client = new CouncilClient;

        $result = $client->ask(
            ['key' => 'gpt', 'model' => 'openai/gpt-6-astra', 'family' => 'openai', 'reasoning_effort' => 'xhigh'],
            'system',
            'user',
        );

        $this->assertNull($result['json']);
        $this->assertStringContainsString('truncated', (string) $result['error']);
        $this->assertStringContainsString('6877', (string) $result['error']);

        $sent = [];
        Http::recorded(function ($request) use (&$sent) {
            $sent[] = $request->data();

            return true;
        });

        $this->assertCount(2, $sent);
        $this->assertSame('xhigh', $sent[0]['reasoning_effort'], 'The first call uses the seat\'s configured effort.');
        $this->assertSame('low', $sent[1]['reasoning_effort'], 'The re-ask must buy the answer room, not repeat the same truncation.');
    }

    /**
     * OpenRouter reports reasoning tokens INSIDE completion_tokens and breaks
     * them out under completion_tokens_details, so capturing them does not
     * change the council's total cost; it says how much of that total was
     * thinking rather than answer.
     *
     * That split is what sizes max_output_tokens. max_tokens caps reasoning and
     * response together, so a seat whose reasoning is most of its completion
     * budget has correspondingly less room to answer in. Without this field the
     * transcript cannot tell a member that had nothing to say from one that ran
     * out of room to say it.
     */
    public function test_the_council_records_reasoning_tokens(): void
    {
        $client = new CouncilClient;

        $usage = (fn (array $raw) => $this->usage($raw))->call($client, [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'completion_tokens_details' => ['reasoning_tokens' => 900],
        ]);

        $this->assertSame(900, $usage['reasoning_tokens'], 'Reasoning tokens are billed and must be recorded.');

        $merged = (fn (array $a, array $b) => $this->mergeUsage($a, $b))->call($client, $usage, $usage);

        $this->assertSame(1800, $merged['reasoning_tokens'], 'Merging per-member usage must carry reasoning tokens too.');
    }
}
