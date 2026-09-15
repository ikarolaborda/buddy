<?php

namespace Tests\Feature;

use App\Services\Council\CouncilClient;
use App\Services\Council\CouncilProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AzureBackgroundCouncilTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['buddy_agents.council.rosters.azure.base_url' => 'https://azure.example/openai/v1', 'ai.providers.azure.key' => 'AZURE-SECRET']);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function completed(): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"ok":true}']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'output_tokens_details' => ['reasoning_tokens' => 15]]];
    }

    public function test_polling_recovers_transport_failure_without_restarting_generation(): void
    {
        $polls = 0;
        Http::fake(function ($request) use (&$polls) {
            $this->assertSame(['AZURE-SECRET'], $request->header('api-key'));
            if ($request->method() === 'POST') {
                $this->assertTrue($request['background']);
                $this->assertTrue($request['store']);
                $this->assertSame('xhigh', $request['reasoning']['effort']);

                return Http::response(['id' => 'resp_test', 'status' => 'queued']);
            }
            $this->assertSame('https://azure.example/openai/v1/responses/resp_test', $request->url());
            if ($request->method() === 'DELETE') {
                return Http::response(['deleted' => true]);
            }
            if (++$polls === 1) {
                throw new ConnectionException('Polling connection interrupted.');
            }

            return Http::response($polls === 2 ? ['id' => 'resp_test', 'status' => 'in_progress'] : $this->completed());
        });

        $result = (new CouncilClient)->forProfile('azure')->ask(CouncilProfile::resolve('azure')['chairman'], 'Return JSON.', 'test');
        $this->assertTrue($result['json']['ok']);
        $this->assertSame(15, $result['usage']['reasoning_tokens']);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'DELETE'));
    }

    public function test_parallel_round_starts_all_reviewers_before_polling(): void
    {
        $started = 0;
        Http::fake(function ($request) use (&$started) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'resp_'.++$started, 'status' => 'queued']);
            }
            $this->assertSame(3, $started);

            return Http::response($request->method() === 'DELETE' ? ['deleted' => true] : $this->completed());
        });
        $results = (new CouncilClient)->forProfile('azure')->askAll(CouncilProfile::resolve('azure')['members'], 'Return JSON.', fn () => 'test');
        foreach ($results as $result) {
            $this->assertTrue($result['json']['ok']);
        }
        Http::assertSentCount(9);
    }

    public function test_expired_deadline_cancels_and_deletes_the_existing_response(): void
    {
        config(['buddy_agents.council.call_timeout' => 0]);
        Http::fake(['*' => Http::response(['id' => 'resp_test', 'status' => 'queued'])]);
        $result = (new CouncilClient)->forProfile('azure')->ask(CouncilProfile::resolve('azure')['chairman'], 'Return JSON.', 'test');
        $this->assertNull($result['json']);
        $this->assertStringContainsString('timed out', $result['error']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/resp_test/cancel'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/resp_test'));
        Http::assertSentCount(3);
    }

    public function test_provider_supplied_id_cannot_change_the_poll_target(): void
    {
        Http::fake(['*' => Http::response(['id' => '../other?token=x', 'status' => 'queued'])]);
        $result = (new CouncilClient)->forProfile('azure')->ask(CouncilProfile::resolve('azure')['chairman'], 'Return JSON.', 'test');
        $this->assertNull($result['json']);
        $this->assertStringContainsString('valid response ID', $result['error']);
        Http::assertSentCount(1);
    }

    public function test_cancellation_failure_still_attempts_delete_and_preserves_timeout(): void
    {
        config(['buddy_agents.council.call_timeout' => 0]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/cancel')) {
                throw new ConnectionException('Cancel connection interrupted.');
            }

            return Http::response(['id' => 'resp_test', 'status' => 'queued']);
        });
        $result = (new CouncilClient)->forProfile('azure')->ask(CouncilProfile::resolve('azure')['chairman'], 'Return JSON.', 'test');
        $this->assertStringContainsString('timed out', $result['error']);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/resp_test'));
    }

    public function test_ambiguous_creation_failure_is_not_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Connection lost after submission.');
        });
        $result = (new CouncilClient)->forProfile('azure')->ask(CouncilProfile::resolve('azure')['chairman'], 'Return JSON.', 'test');
        $this->assertSame('Connection lost after submission.', $result['error']);
        $this->assertSame(1, $attempts);
    }
}
