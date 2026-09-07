<?php

/*
|--------------------------------------------------------------------------
| Council Provider Profiles
|--------------------------------------------------------------------------
|
| A council profile is a WHOLE roster plus the provider it runs on: base URL,
| the config path of its credential, its provider headers, a chairman and five
| members. Selecting a profile swaps the entire council; it does not run a
| second council alongside the first.
|
| Base URL and credential are resolved TOGETHER from the selected profile. That
| pairing is the point: CouncilClient used to read
| config('ai.providers.openrouter.key') unconditionally, so repointing base_url
| at another provider would have sent an OpenRouter token to it.
|
| The workers_ai roster was chosen by MEASUREMENT, not reputation. Every seat
| was probed against the real falsification-round schema over Cloudflare's
| OpenAI-compatible endpoint on 2026-09-07, and three otherwise attractive
| models were rejected for failing it: @cf/qwen/qwq-32b returned invalid JSON,
| @cf/meta/llama-3.3-70b returned valid JSON of the wrong shape, and
| @cf/google/gemma-4-26b failed the schema on both trials. A seat that cannot
| hold the schema does not merely underperform, it drops out of the
| falsification round silently, which is the defect fixed on 2026-09-06.
|
| The six seats carry SIX DISTINCT FAMILIES with no overlap, against the
| openrouter roster's three Anthropic seats plus one OpenAI and one Google. ADR
| 0009 discloses family skew in every verdict, so this is a diversity
| improvement and not only a cost one.
|
*/

$councilProfiles = [

    'openrouter' => [
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'credential' => 'ai.providers.openrouter.key',
        'headers' => [
            'HTTP-Referer' => 'https://github.com/ikarolaborda/buddy',
            'X-Title' => 'Buddy Council',
        ],
        'chairman' => ['key' => 'chairman', 'model' => 'anthropic/claude-fable-5', 'family' => 'anthropic'],
        'members' => [
            ['key' => 'gpt', 'model' => 'openai/gpt-6-astra', 'family' => 'openai', 'reasoning_effort' => 'xhigh'],
            ['key' => 'fable', 'model' => 'anthropic/claude-fable-5', 'family' => 'anthropic'],
            ['key' => 'opus', 'model' => 'anthropic/claude-opus-4.8', 'family' => 'anthropic'],
            ['key' => 'sonnet', 'model' => 'anthropic/claude-sonnet-5', 'family' => 'anthropic'],
            ['key' => 'gemini', 'model' => 'google/gemini-3.1-pro-preview', 'family' => 'google'],
        ],
    ],

    'workers_ai' => [
        'base_url' => 'https://api.cloudflare.com/client/v4/accounts/'.env('CLOUDFLARE_ACCOUNT_ID').'/ai/v1',
        'credential' => 'ai.providers.cloudflare.key',
        'headers' => ['X-Title' => 'Buddy Council'],
        'chairman' => ['key' => 'chairman', 'model' => '@cf/nvidia/nemotron-3-120b-a12b', 'family' => 'nvidia'],
        'members' => [
            ['key' => 'gpt', 'model' => '@cf/openai/gpt-oss-120b', 'family' => 'openai'],
            ['key' => 'glm', 'model' => '@cf/zai-org/glm-5.3', 'family' => 'zhipu'],
            ['key' => 'deepseek', 'model' => '@cf/deepseek-ai/deepseek-v4-pro-0813', 'family' => 'deepseek'],
            ['key' => 'kimi', 'model' => '@cf/moonshotai/kimi-k2.7-code', 'family' => 'moonshot'],
            ['key' => 'mistral', 'model' => '@cf/mistralai/mistral-small-3.1-24b-instruct', 'family' => 'mistral'],
        ],
    ],

];

$councilProfile = (string) env('BUDDY_COUNCIL_PROFILE', 'openrouter');
$activeCouncil = $councilProfiles[$councilProfile] ?? $councilProfiles['openrouter'];

return [

    /*
    |--------------------------------------------------------------------------
    | Agent Profiles
    |--------------------------------------------------------------------------
    |
    | Versioned default configuration per agent. An active row in the
    | agent_profiles table with the same name overrides these values, so
    | production can retune model routing without a deploy. Effective
    | values are recorded on every run.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Problem-Type Model Routing
    |--------------------------------------------------------------------------
    |
    | Optional low-stakes routing can send the evaluator to a faster model.
    | Applies ONLY to the evaluator-optimizer agent and ONLY when no
    | active agent_profiles row overrides that agent: a DB override is
    | the ops escape hatch and always wins verbatim. The fast model was
    | verified against the live OpenAI model list on 2026-07-22.
    | Effective model is recorded on every run. ADR 0008.
    |
    */

    'routing' => [
        'enabled' => (bool) env('BUDDY_MODEL_ROUTING', false),
        'fast_model' => env('BUDDY_FAST_MODEL', 'gpt-5.4-mini'),
        'fast_problem_types' => array_filter(array_map('trim', explode(',', (string) env('BUDDY_FAST_PROBLEM_TYPES', 'configuration,other')))),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM Council
    |--------------------------------------------------------------------------
    |
    | Falsification-first multi-model deliberation (plan
    | 2026-07-22-llm-council, ADR 0009). Explicit invocation only; a
    | council is never auto-routed. Defeats require evidence references
    | that resolve to real packet items; packet evidence is testimony,
    | so `underdetermined` with a discriminator list is the expected
    | modal outcome, not a failure. Chairman narrates; PHP computes the
    | ranking. Cost cap counts council runs per UTC day across clients.
    |
    | The 'gpt' seat moved from gpt-5.6-sol to gpt-6-astra on 2026-09-06. Two
    | things made that safe to do, both checked rather than assumed. OpenRouter
    | normalises `max_tokens`, which direct Azure rejects for this model in
    | favour of max_completion_tokens, so CouncilClient's payload is accepted
    | unchanged; verified by a live 200 with the exact payload including
    | reasoning_effort=xhigh. And the seat is 5x the price per token, which the
    | output cap and the 10-councils-per-day limit keep bounded.
    |
    | It also forced the timeout raise below: the seat is about 45% slower per
    | call, and the council was already running at 811s against a 900s ceiling.
    |
    | max_output_tokens went 8000 -> 24000 on 2026-09-06 for a measured reason.
    | OpenRouter bills reasoning inside completion_tokens, so max_tokens is one
    | budget shared by thinking and answering. Replaying the production
    | falsification round against this seat returned finish_reason=length with
    | 6877 of its 8000 tokens spent reasoning, leaving 1123 for an answer that
    | needed about 2100: the JSON was cut mid-string and the member dropped out
    | of the round. 24000 clears three times the observed reasoning plus a full
    | answer. It also triples the worst case per call, to roughly $1.20 for this
    | seat, which the daily cap bounds at about $36/day if every council ran
    | every seat to its ceiling.
    |
    | Raising the budget makes the call longer, so call_timeout moved 300 -> 420
    | in the same breath. The same replay at 24000 finished in 221s with
    | finish_reason=stop and valid JSON, spending 6732 reasoning tokens and 4044
    | on the answer. 221s against a 300s ceiling is the thin margin that started
    | this whole thread; 420 leaves real room and still fits four sequential
    | stages inside the 1800s council_job.
    |
    | Worth recording because it was the live worry: reasoning did NOT expand to
    | fill the larger budget. It was 6877 tokens at max_tokens=8000 and 6732 at
    | 24000. The ceiling was starving the answer, not restraining the thinking.
    |
    | artifact_chars went 4000 -> 16000 and gained a packet_chars companion, for
    | a reason that is not "the window is bigger now". Every seat carries at
    | least a 1,000,000-token context and the packet was running about 9k-15k,
    | so the window was never the constraint. Two other things were.
    |
    | The count of artifacts is caller-controlled and unbounded, so a per-item
    | cap bounded nothing; packet_chars is the actual guard and it is what makes
    | a generous per-item cap safe. And a council writes its own rounds back as
    | council_transcript artifacts, so a second council on the same task read
    | its own previous deliberation as testimony. Those are now excluded, which
    | is a correctness fix rather than a size one: ADR 0009 tiers packet items
    | as testimony that claims must cite, and the council's own prior reasoning
    | is not evidence about the problem.
    |
    | Latency was the stated worry and this knob does not touch it. artifact_chars
    | is read in exactly one place, the council packet. Agents waiting on buddy
    | are on the evaluator path. Within the council itself the packet is repeated
    | across all four rounds and the measured prompt cache hit was 9051 of 9054
    | tokens, so it is paid for once and is close to free thereafter.
    |
    */

    'council' => [
        'enabled' => (bool) env('BUDDY_COUNCIL', true),
        'profile' => $councilProfile,
        'profiles' => array_keys($councilProfiles),
        'base_url' => $activeCouncil['base_url'],
        'credential' => $activeCouncil['credential'],
        'headers' => $activeCouncil['headers'],
        'max_per_day' => (int) env('BUDDY_COUNCIL_MAX_PER_DAY', 10),
        'gate_enabled' => (bool) env('BUDDY_COUNCIL_GATE', true),
        'gate_attempt_threshold' => (int) env('BUDDY_COUNCIL_GATE_ATTEMPTS', 2),
        'gate_min_reason_length' => (int) env('BUDDY_COUNCIL_GATE_MIN_REASON', 30),
        'call_timeout' => (int) env('BUDDY_COUNCIL_CALL_TIMEOUT', 420),
        'artifact_chars' => (int) env('BUDDY_COUNCIL_ARTIFACT_CHARS', 16000),
        'packet_chars' => (int) env('BUDDY_COUNCIL_PACKET_CHARS', 160000),
        'max_output_tokens' => (int) env('BUDDY_COUNCIL_MAX_OUTPUT_TOKENS', 24000),
        'min_positions' => 3,
        'chairman' => $activeCouncil['chairman'],
        'members' => $activeCouncil['members'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Temperature and gpt-6-astra
    |--------------------------------------------------------------------------
    |
    | These profiles used to ask for 0.2 and 0.3. gpt-6-astra REJECTS both:
    | "Unsupported value: 'temperature' does not support 0.2 with this model.
    | Only the default (1) value is supported." (verified live against the
    | eastus2 deployment, 2026-09-05). It also rejects `max_tokens` and wants
    | `max_completion_tokens` instead.
    |
    | Neither rejection can fire on this code path today, and it is worth being
    | precise about why rather than assuming the config is what gets sent:
    | laravel/ai builds request options from PHP ATTRIBUTES on the agent class,
    | not from this array. EvaluatorOptimizerAgent and PromptRefinementAgent
    | declare only #[MaxSteps(10)], so temperature and maxTokens are both null,
    | Prism drops the null temperature via array_filter, and the max-tokens
    | default of 64000 in CreatesPrismTextRequests applies only to Anthropic.
    |
    | So 1.0 here is not a bug fix; it stops the config from asserting a value
    | the model would refuse, which is what would bite whoever later adds a
    | #[Temperature] attribute or an agent_profiles override row.
    |
    */

    'profiles' => [
        'evaluator-optimizer' => [
            'provider' => env('BUDDY_EVALUATOR_PROVIDER', 'azure'),
            'model' => env('BUDDY_MODEL', 'gpt-6-astra'),
            'timeout' => (int) env('BUDDY_EVALUATION_TIMEOUT', 240),
            'max_steps' => (int) env('BUDDY_MAX_EVALUATION_STEPS', 10),
            'temperature' => 1.0,
        ],
        'prompt-refiner' => [
            'provider' => env('BUDDY_REFINER_PROVIDER', 'azure'),
            'model' => env('BUDDY_MODEL', 'gpt-6-astra'),
            'timeout' => (int) env('BUDDY_EVALUATION_TIMEOUT', 240),
            'max_steps' => (int) env('BUDDY_MAX_EVALUATION_STEPS', 10),
            'temperature' => 1.0,
        ],
    ],

];
