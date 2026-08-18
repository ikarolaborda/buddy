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

    public function test_problem_type_model_routing_is_off_by_default(): void
    {
        $agents = $this->shippedConfig('buddy_agents', 'BUDDY_MODEL_ROUTING');

        $this->assertFalse(
            $agents['routing']['enabled'],
            'Routing splits problem types across models, which hides per-model failures.'
        );
    }

    public function test_council_roster_is_not_governed_by_the_evaluator_model(): void
    {
        $agents = $this->shippedConfig('buddy_agents', 'BUDDY_MODEL');

        $this->assertSame('anthropic/claude-fable-5', $agents['council']['chairman']['model']);
    }
}
