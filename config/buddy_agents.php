<?php

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
    | Low-stakes problem types route the evaluator to a faster model.
    | Applies ONLY to the evaluator-optimizer agent and ONLY when no
    | active agent_profiles row overrides that agent: a DB override is
    | the ops escape hatch and always wins verbatim. The fast model was
    | verified against the live OpenAI model list on 2026-07-22.
    | Effective model is recorded on every run. ADR 0008.
    |
    */

    'routing' => [
        'enabled' => (bool) env('BUDDY_MODEL_ROUTING', true),
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
    */

    'council' => [
        'enabled' => (bool) env('BUDDY_COUNCIL', true),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'max_per_day' => (int) env('BUDDY_COUNCIL_MAX_PER_DAY', 10),
        'gate_enabled' => (bool) env('BUDDY_COUNCIL_GATE', true),
        'gate_attempt_threshold' => (int) env('BUDDY_COUNCIL_GATE_ATTEMPTS', 2),
        'gate_min_reason_length' => (int) env('BUDDY_COUNCIL_GATE_MIN_REASON', 30),
        'call_timeout' => (int) env('BUDDY_COUNCIL_CALL_TIMEOUT', 300),
        'artifact_chars' => (int) env('BUDDY_COUNCIL_ARTIFACT_CHARS', 4000),
        'max_output_tokens' => (int) env('BUDDY_COUNCIL_MAX_OUTPUT_TOKENS', 8000),
        'min_positions' => 3,
        'chairman' => ['key' => 'chairman', 'model' => 'anthropic/claude-fable-5', 'family' => 'anthropic'],
        'members' => [
            ['key' => 'gpt', 'model' => 'openai/gpt-5.6-sol', 'family' => 'openai', 'reasoning_effort' => 'xhigh'],
            ['key' => 'fable', 'model' => 'anthropic/claude-fable-5', 'family' => 'anthropic'],
            ['key' => 'opus', 'model' => 'anthropic/claude-opus-4.8', 'family' => 'anthropic'],
            ['key' => 'sonnet', 'model' => 'anthropic/claude-sonnet-5', 'family' => 'anthropic'],
            ['key' => 'gemini', 'model' => 'google/gemini-3.1-pro-preview', 'family' => 'google'],
        ],
    ],

    'profiles' => [
        'evaluator-optimizer' => [
            'provider' => env('BUDDY_EVALUATOR_PROVIDER', 'openai'),
            'model' => env('BUDDY_MODEL', 'gpt-5.4'),
            'timeout' => (int) env('BUDDY_EVALUATION_TIMEOUT', 120),
            'max_steps' => (int) env('BUDDY_MAX_EVALUATION_STEPS', 10),
            'temperature' => 0.2,
        ],
        'prompt-refiner' => [
            'provider' => env('BUDDY_REFINER_PROVIDER', 'openai'),
            'model' => env('BUDDY_MODEL', 'gpt-5.4'),
            'timeout' => (int) env('BUDDY_EVALUATION_TIMEOUT', 120),
            'max_steps' => (int) env('BUDDY_MAX_EVALUATION_STEPS', 10),
            'temperature' => 0.3,
        ],
    ],

];
