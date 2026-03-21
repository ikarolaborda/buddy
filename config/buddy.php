<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Buddy Evaluator Model
    |--------------------------------------------------------------------------
    |
    | The AI model used by Buddy's evaluator-optimizer agent. This should
    | be a model that supports structured output via the provider.
    |
    */

    'model' => env('BUDDY_MODEL', 'gpt-5.4'),

    'embedding_model' => env('BUDDY_EMBEDDING_MODEL', 'text-embedding-3-small'),

    'max_evaluation_steps' => (int) env('BUDDY_MAX_EVALUATION_STEPS', 10),

    'evaluation_timeout' => (int) env('BUDDY_EVALUATION_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Qdrant Configuration
    |--------------------------------------------------------------------------
    |
    | Connection details for the Qdrant vector database used for Buddy's
    | episodic memory and knowledge storage.
    |
    */

    'qdrant' => [
        'host' => env('QDRANT_HOST', 'http://localhost'),
        'port' => (int) env('QDRANT_PORT', 6333),
        'api_key' => env('QDRANT_API_KEY'),
        'collection' => env('QDRANT_COLLECTION', 'buddy_episodes'),
        'knowledge_collection' => env('QDRANT_KNOWLEDGE_COLLECTION', 'buddy_knowledge'),
        'vector_size' => (int) env('QDRANT_VECTOR_SIZE', 1536),
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation Thresholds
    |--------------------------------------------------------------------------
    |
    | These define the default thresholds for the escalation trigger policy.
    | A primary agent should escalate when at least two conditions are met.
    |
    */

    'escalation' => [
        'min_triggers' => 2,
        'max_elapsed_seconds' => 300,
        'max_failed_attempts' => 2,
    ],

];
