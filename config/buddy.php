<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Buddy Evaluator Model
    |--------------------------------------------------------------------------
    |
    | Default model settings. Per-agent overrides live in buddy_agents.php
    | and in the agent_profiles table; effective values are recorded on
    | every run.
    |
    */

    'model' => env('BUDDY_MODEL', 'gpt-5.4'),

    'embedding_model' => env('BUDDY_EMBEDDING_MODEL', 'text-embedding-3-small'),

    'max_evaluation_steps' => (int) env('BUDDY_MAX_EVALUATION_STEPS', 10),

    'evaluation_timeout' => (int) env('BUDDY_EVALUATION_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | API Authentication
    |--------------------------------------------------------------------------
    |
    | External callers authenticate with bdy_live_* API keys. Only the
    | public ID and an HMAC-SHA-256 digest (peppered) are stored. The
    | pepper must come from a secret store in production, never a mounted
    | application .env in the container image.
    |
    */

    'api' => [
        'auth_required' => (bool) env('BUDDY_API_AUTH', true),
        'key_pepper' => env('BUDDY_API_KEY_PEPPER', ''),

        /*
         * Verified-key cache TTL in seconds; 0 disables. Bounds how long
         * a direct-DB revocation or client deactivation can linger.
         */
        'key_cache_ttl' => (int) env('BUDDY_API_KEY_CACHE_TTL', 60),

        /*
         * Origins allowed to call /api/mcp from a browser context.
         * First-party agents send no Origin header and are unaffected.
         * Browser-based tools (MCP Inspector: http://localhost:6274)
         * must be listed here explicitly, localhost included. ADR 0006.
         */
        'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('BUDDY_MCP_ALLOWED_ORIGINS', '')))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory Backend
    |--------------------------------------------------------------------------
    |
    | legacy: direct Qdrant collection through QdrantMemoryService.
    | hub:    Go qdrant-memory hub REST interface (production target).
    | shadow: reads served by legacy, mirrored against the hub for
    |         migration comparison.
    |
    */

    'memory' => [
        'backend' => env('BUDDY_MEMORY_BACKEND', 'legacy'),

        'hub' => [
            'base_url' => env('BUDDY_MEMORY_HUB_URL', 'http://localhost:8090'),
            'token' => env('BUDDY_MEMORY_HUB_TOKEN'),
            'project' => env('BUDDY_MEMORY_HUB_PROJECT', 'buddy'),
            'connect_timeout' => (int) env('BUDDY_MEMORY_HUB_CONNECT_TIMEOUT', 5),
            'timeout' => (int) env('BUDDY_MEMORY_HUB_TIMEOUT', 15),
            /*
             * Frozen against qdrant-memory Go hub openapi.yaml at commit
             * 80b824c: POST /api/search {query, limit, filters}; POST
             * /api/store {problem, solution, impact, tags,
             * file_references}; POST /api/memories/{id}/feedback
             * {kind: useful|not_useful|stale}; GET /api/healthz.
             */
            'paths' => [
                'search' => env('BUDDY_MEMORY_HUB_SEARCH_PATH', '/api/search'),
                'store' => env('BUDDY_MEMORY_HUB_STORE_PATH', '/api/store'),
                'feedback' => env('BUDDY_MEMORY_HUB_FEEDBACK_PATH', '/api/memories/{id}/feedback'),
                'health' => env('BUDDY_MEMORY_HUB_HEALTH_PATH', '/api/healthz'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout Ordering
    |--------------------------------------------------------------------------
    |
    | Invariant: provider HTTP timeout < job timeout < worker timeout <
    | queue retry_after. Breaking this ordering redelivers still-running
    | jobs. Tune from observed model latency.
    |
    */

    'timeouts' => [
        'provider' => (int) env('BUDDY_PROVIDER_TIMEOUT', 120),
        'job' => (int) env('BUDDY_JOB_TIMEOUT', 180),
        'worker' => (int) env('BUDDY_WORKER_TIMEOUT', 210),
        'retry_after' => (int) env('BUDDY_QUEUE_RETRY_AFTER', 240),
        'lease' => (int) env('BUDDY_TASK_LEASE_SECONDS', 300),
        'council_job' => (int) env('BUDDY_COUNCIL_JOB_TIMEOUT', 900),
        'council_lease' => (int) env('BUDDY_COUNCIL_LEASE_SECONDS', 1200),
    ],

    /*
    |--------------------------------------------------------------------------
    | LangSmith Observability
    |--------------------------------------------------------------------------
    |
    | Fire-and-forget run-tree tracing per evaluation. Content (prompts,
    | summaries) leaves the process only when send_prompts is enabled;
    | the default payload is hashes, module IDs, memory IDs, and outcome
    | metadata. Tracing failures never fail an evaluation.
    |
    */

    'langsmith' => [
        'tracing' => (bool) env('LANGSMITH_TRACING', false),
        'api_key' => env('LANGSMITH_API_KEY', ''),
        'endpoint' => env('LANGSMITH_ENDPOINT', 'https://api.smith.langchain.com'),
        'project' => env('LANGSMITH_PROJECT', 'buddy-local'),
        'send_prompts' => (bool) env('LANGSMITH_SEND_PROMPTS', false),
        'timeout' => (int) env('LANGSMITH_TIMEOUT', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Checks
    |--------------------------------------------------------------------------
    */

    'health' => [
        'check_memory' => (bool) env('BUDDY_HEALTH_CHECK_MEMORY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Controlled Improvement Loop
    |--------------------------------------------------------------------------
    |
    | Hard bounds for the offline improvement loop. Candidates are data;
    | promotion always requires a human decision. Report-only mode never
    | routes candidate prompts to live traffic.
    |
    */

    'cil' => [
        'mode' => env('BUDDY_CIL_MODE', 'report_only'),
        'max_candidates_per_cycle' => (int) env('BUDDY_CIL_MAX_CANDIDATES', 3),
        'max_evaluations_per_cycle' => (int) env('BUDDY_CIL_MAX_EVALUATIONS', 20),
        'min_evidence_runs' => (int) env('BUDDY_CIL_MIN_EVIDENCE_RUNS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Qdrant Configuration (legacy backend)
    |--------------------------------------------------------------------------
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
    */

    'escalation' => [
        'min_triggers' => 2,
        'max_elapsed_seconds' => 300,
        'max_failed_attempts' => 2,
    ],

];
