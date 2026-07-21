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
