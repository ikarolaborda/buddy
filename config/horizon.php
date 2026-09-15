<?php

/*
 * One Horizon supervisor per queue lane (config/buddy.php 'queues', ADR 0014).
 * Lane names and capacities are repeated here through the same environment
 * variables because config files cannot read each other;
 * tests/Unit/HorizonConfigTest fails if the two drift apart.
 *
 * Pools are fixed-size ('simple' balance): the replica always has the lane's
 * full capacity idle and ready, so a burst of agents is served immediately
 * instead of waiting for Horizon to add processes. An idle worker process
 * costs about 65 MB; the worker template is sized for the sum of capacities.
 *
 * Timeouts keep provider < job < supervisor < retry_after with a margin. The
 * job attribute is what actually stops a job; the supervisor value is the
 * ceiling Horizon applies to a process it is retiring. The legacy supervisor
 * drains 'default' under the council ceiling because a council enqueued by a
 * previous release may still be sitting there.
 */

$lanes = [
    'evaluations' => env('BUDDY_QUEUE_EVALUATIONS', 'evaluations'),
    'council' => env('BUDDY_QUEUE_COUNCIL', 'council'),
    'fast' => env('BUDDY_QUEUE_FAST', 'fast'),
    'legacy' => env('REDIS_QUEUE', 'default'),
];

$supervisor = fn (string $lane, int $processes, int $timeout, int $sleep = 1) => [
    'connection' => 'redis',
    'queue' => [$lanes[$lane]],
    'balance' => 'simple',
    'minProcesses' => $processes,
    'maxProcesses' => $processes,
    'memory' => 384,
    'tries' => 1,
    'timeout' => $timeout,
    'sleep' => $sleep,
    'maxJobs' => 500,
    'maxTime' => 0,
    'nice' => 0,
];

$supervisors = [
    'evaluations' => $supervisor('evaluations', (int) env('BUDDY_WORKERS_EVALUATIONS', 5), (int) env('BUDDY_JOB_TIMEOUT', 600) + 60),
    'council' => $supervisor('council', (int) env('BUDDY_WORKERS_COUNCIL', 1), (int) env('BUDDY_COUNCIL_JOB_TIMEOUT', 1800) + 60, 3),
    'fast' => $supervisor('fast', (int) env('BUDDY_WORKERS_FAST', 2), 150),
    'legacy' => $supervisor('legacy', (int) env('BUDDY_WORKERS_LEGACY', 1), (int) env('BUDDY_COUNCIL_JOB_TIMEOUT', 1800) + 60),
];

return [
    'name' => env('HORIZON_NAME', 'buddy-worker'),
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', 'buddy-horizon:'),
    'middleware' => ['web'],

    'waits' => [
        'redis:'.$lanes['evaluations'] => 30,
        'redis:'.$lanes['council'] => 600,
        'redis:'.$lanes['fast'] => 15,
        'redis:'.$lanes['legacy'] => 60,
    ],

    // Redis is a 256 MB noeviction transport, so bookkeeping is trimmed within the hour.
    'trim' => [
        'recent' => 30,
        'pending' => 30,
        'completed' => 30,
        'recent_failed' => 1440,
        'failed' => 1440,
        'monitored' => 1440,
    ],

    'silenced' => [],
    'silenced_tags' => [],
    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],
    'fast_termination' => false,
    'memory_limit' => 128,

    'defaults' => $supervisors,

    'environments' => [
        '*' => array_map(fn () => [], $supervisors),
    ],

    'watch' => [],
];
