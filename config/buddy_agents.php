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
