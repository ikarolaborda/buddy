<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimiterBucketsTest extends TestCase
{
    protected function limitFor(string $limiter, ?string $token, string $ip = '10.0.0.1'): object
    {
        $server = ['REMOTE_ADDR' => $ip];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return RateLimiter::limiter($limiter)(Request::create('/api/mcp', 'POST', server: $server));
    }

    public function test_named_limiters_are_registered(): void
    {
        foreach (['mcp', 'buddy-api', 'buddy-admin'] as $name) {
            $this->assertNotNull(RateLimiter::limiter($name));
        }
    }

    public function test_different_bearer_tokens_get_separate_buckets(): void
    {
        $a = $this->limitFor('mcp', 'bdy_live_aaa');
        $b = $this->limitFor('mcp', 'bdy_live_bbb');

        $this->assertNotSame($a->key, $b->key);
    }

    public function test_same_token_shares_a_bucket_across_ips(): void
    {
        $a = $this->limitFor('mcp', 'bdy_live_aaa', '10.0.0.1');
        $b = $this->limitFor('mcp', 'bdy_live_aaa', '10.0.0.2');

        $this->assertSame($a->key, $b->key);
    }

    public function test_unauthenticated_requests_bucket_by_ip(): void
    {
        $a = $this->limitFor('mcp', null, '10.0.0.1');
        $b = $this->limitFor('mcp', null, '10.0.0.2');

        $this->assertNotSame($a->key, $b->key);
        $this->assertStringContainsString('10.0.0.1', $a->key);
    }

    public function test_surfaces_use_distinct_buckets_for_one_token(): void
    {
        $mcp = $this->limitFor('mcp', 'bdy_live_aaa');
        $api = $this->limitFor('buddy-api', 'bdy_live_aaa');

        $this->assertNotSame($mcp->key, $api->key);
        $this->assertSame(120, $mcp->maxAttempts);
        $this->assertSame(60, $api->maxAttempts);
        $this->assertSame(10, $this->limitFor('buddy-admin', 'bdy_live_aaa')->maxAttempts);
    }
}
