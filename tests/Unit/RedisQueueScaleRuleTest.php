<?php

namespace Tests\Unit;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/*
 * P0 of the 2026-09-15 Cloudflare plan. The worker autoscaler measures a raw
 * Redis list, so the Bicep default must equal the key Laravel writes:
 * config('database.redis.options.prefix') . 'queues:' . <queue>. The prefix
 * derives from APP_NAME, which the worker does not set, and the queue name
 * derives from REDIS_QUEUE, which it does not set either. Pinning all three
 * inputs here means a future APP_NAME, REDIS_PREFIX or REDIS_QUEUE change on
 * the worker fails CI unless the scale rule changes with it.
 */
class RedisQueueScaleRuleTest extends TestCase
{
    private const WORKER_BICEP = __DIR__.'/../../infra/azure/modules/buddy-worker.bicep';

    private const MAIN_BICEP = __DIR__.'/../../infra/azure/main.bicep';

    private const MEASURED_PRODUCTION_KEY = 'laravel-database-queues:default';

    public function test_it_pins_the_scale_rule_list_name_to_the_laravel_default_derivation(): void
    {
        $expected = Str::slug('Laravel').'-database-'.'queues:'.'default';

        $this->assertSame(self::MEASURED_PRODUCTION_KEY, $expected);
        $this->assertSame($expected, $this->bicepParamDefault(self::WORKER_BICEP, 'redisQueueListName'));
        $this->assertSame($expected, $this->bicepParamDefault(self::MAIN_BICEP, 'redisQueueListName'));
    }

    public function test_the_worker_scale_rule_reads_the_list_name_parameter(): void
    {
        $worker = (string) file_get_contents(self::WORKER_BICEP);

        $this->assertMatchesRegularExpression('/listName:\s*redisQueueListName/', $worker);
        $this->assertStringNotContainsString("listName: 'buddy:queue:default'", $worker);
        $this->assertMatchesRegularExpression('/address:\s*scaleAddress/', $worker);
    }

    public function test_the_worker_does_not_override_the_derivation_inputs(): void
    {
        $worker = (string) file_get_contents(self::WORKER_BICEP);

        foreach (['APP_NAME', 'REDIS_PREFIX', 'REDIS_QUEUE', 'REDIS_DB'] as $variable) {
            $this->assertStringNotContainsString("name: '{$variable}'", $worker, "{$variable} changes the queue key; update redisQueueListName with it.");
        }
    }

    private function bicepParamDefault(string $path, string $name): string
    {
        $source = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression('/^param '.preg_quote($name, '/').' string = \'([^\']+)\'/m', $source);
        preg_match('/^param '.preg_quote($name, '/').' string = \'([^\']+)\'/m', $source, $matches);

        return $matches[1];
    }
}
