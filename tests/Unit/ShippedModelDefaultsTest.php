<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The evaluator silently ran on a stale model because BUDDY_MODEL was never set
 * on the deployed container apps, so the repo default governed production. On
 * top of that, problem-type routing sent two problem types to a different model,
 * which masked a total outage as an intermittent one.
 *
 * These assertions pin the SHIPPED DEFAULTS (the env() fallbacks in the config
 * files), not the values a developer's local .env happens to resolve to, since
 * the fallback is what production actually ran on.
 */
class ShippedModelDefaultsTest extends TestCase
{
    /**
     * Re-evaluate a config file with the given env vars unset, so the assertions
     * see the committed fallback rather than an ambient override.
     */
    private function shippedConfig(string $file, string ...$unset): array
    {
        $saved = [];

        foreach ($unset as $key) {
            $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        try {
            return require base_path("config/{$file}.php");
        } finally {
            foreach ($saved as $key => [$env, $server, $raw]) {
                if ($env !== null) {
                    $_ENV[$key] = $env;
                }

                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }

                if ($raw !== false) {
                    putenv("{$key}={$raw}");
                }
            }
        }
    }

    public function test_shipped_default_model_is_the_supported_evaluator_model(): void
    {
        $buddy = $this->shippedConfig('buddy', 'BUDDY_MODEL');
        $agents = $this->shippedConfig('buddy_agents', 'BUDDY_MODEL');

        $this->assertSame('gpt-5.6-sol', $buddy['model']);
        $this->assertSame('gpt-5.6-sol', $agents['profiles']['evaluator-optimizer']['model']);
        $this->assertSame('gpt-5.6-sol', $agents['profiles']['prompt-refiner']['model']);
    }

    /**
     * Routing stays ON because it is worth real money. Benchmarked 2026-08-18 on
     * the compiled evaluator prompt, 5 reps per arm: sending configuration and
     * other to gpt-5.6-sol instead of gpt-5.4-mini cost +1620% (about 17x, driven
     * by a 6.67x unit price and roughly 3x the output tokens once reasoning
     * tokens are counted) and +600% median latency, about 5s to about 33s.
     *
     * The reliability hazard that routing created is handled instead by
     * StructuredOutputSchemaTest: the outage happened because a malformed strict
     * schema was rejected by the base model and tolerated by the fast one, so
     * pinning schema validity is what makes two models safe to run.
     */
    public function test_problem_type_model_routing_is_on_with_both_models_pinned(): void
    {
        $agents = $this->shippedConfig('buddy_agents', 'BUDDY_MODEL_ROUTING', 'BUDDY_FAST_MODEL', 'BUDDY_MODEL');

        $this->assertTrue($agents['routing']['enabled']);
        $this->assertSame('gpt-5.4-mini', $agents['routing']['fast_model']);
        $this->assertSame(['configuration', 'other'], $agents['routing']['fast_problem_types']);
    }

    public function test_council_roster_is_not_governed_by_the_evaluator_model(): void
    {
        $agents = $this->shippedConfig('buddy_agents', 'BUDDY_MODEL');

        $this->assertSame('anthropic/claude-fable-5', $agents['council']['chairman']['model']);
    }
}
