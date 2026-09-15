<?php

namespace Tests\Feature;

use App\Services\Council\CouncilClient;
use App\Services\Council\CouncilProfile;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SolCouncilRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.providers.cloudflare.key' => 'CF-SECRET',
            'ai.providers.openrouter.key' => 'OR-SECRET',
            'buddy_agents.council.rosters.workers_ai.base_url' => 'https://cloudflare.example/ai/v1',
        ]);
        Http::preventStrayRequests();
    }

    private function sol(): array
    {
        return ['key' => 'sol', 'model' => 'openai/gpt-5.6-sol', 'family' => 'openai', 'reasoning_effort' => 'xhigh', 'provider_profile' => 'openrouter'];
    }

    private function reply(string $content = '{"ok":true}'): array
    {
        return ['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20]];
    }

    public function test_switch_replaces_only_the_glm_seat_and_discloses_sol(): void
    {
        $env = Env::getRepository();
        $previous = $env->get('BUDDY_COUNCIL_WORKERS_AI_SOL');
        try {
            $env->set('BUDDY_COUNCIL_WORKERS_AI_SOL', 'true');
            $settings = require config_path('buddy_agents.php');
        } finally {
            $previous === null ? $env->clear('BUDDY_COUNCIL_WORKERS_AI_SOL') : $env->set('BUDDY_COUNCIL_WORKERS_AI_SOL', $previous);
        }

        $roster = $settings['council']['rosters']['workers_ai'];
        $baseline = CouncilProfile::resolve('workers_ai');
        $this->assertSame($baseline['chairman'], $roster['chairman']);
        $this->assertCount(5, $roster['members']);
        foreach ([0, 1, 2, 4] as $index) {
            $this->assertSame($baseline['members'][$index], $roster['members'][$index]);
        }
        $this->assertSame($this->sol(), $roster['members'][3]);
        $this->assertNotContains('@cf/zai-org/glm-5.3', array_column($roster['members'], 'model'));
        $this->assertCount(6, array_unique(array_column([$roster['chairman'], ...$roster['members']], 'family')));
    }

    public function test_serial_and_parallel_calls_keep_each_seats_transport_and_credential(): void
    {
        Http::fake(['*' => Http::response($this->reply())]);
        $client = (new CouncilClient)->forProfile('workers_ai');
        $native = CouncilProfile::resolve('workers_ai')['members'][0];
        $this->assertNull($client->ask($this->sol(), 'system', 'user')['error']);
        $round = $client->askAll([$native, $this->sol()], 'system', fn ($member) => $member['key']);
        $this->assertNull($round['sol']['error']);
        $this->assertNull($round[$native['key']]['error']);
        $this->assertNull($client->ask($native, 'system', 'after override')['error']);

        Http::assertSentCount(4);
        foreach (Http::recorded()->pluck(0) as $request) {
            $sol = $request['model'] === 'openai/gpt-5.6-sol';
            $this->assertSame($sol ? 'https://openrouter.ai/api/v1/chat/completions' : 'https://cloudflare.example/ai/v1/chat/completions', $request->url());
            $this->assertSame([$sol ? 'Bearer OR-SECRET' : 'Bearer CF-SECRET'], $request->header('Authorization'));
            $this->assertEmpty($request->header('api-key'));
            $this->assertSame(24000, $request['max_tokens']);
            $this->assertSame($sol, ! empty($request->header('HTTP-Referer')));
            if ($sol) {
                $this->assertSame('xhigh', $request['reasoning_effort']);
            }
        }
        $this->assertSame('openrouter', config('buddy_agents.council.profile'));
    }

    public function test_json_repair_stays_on_sol_and_preserves_usage(): void
    {
        Http::fake(['openrouter.ai/*' => Http::sequence()->push($this->reply('invalid'))->push($this->reply())]);
        $reply = (new CouncilClient)->forProfile('workers_ai')->askAll([$this->sol()], 'system', fn () => 'user')['sol'];
        $this->assertSame(['ok' => true], $reply['json']);
        $this->assertSame(20, $reply['usage']['prompt_tokens']);
        $this->assertSame(40, $reply['usage']['completion_tokens']);
        Http::assertSentCount(2);
        foreach (Http::recorded()->pluck(0) as $request) {
            $this->assertSame('openai/gpt-5.6-sol', $request['model']);
            $this->assertSame(['Bearer OR-SECRET'], $request->header('Authorization'));
        }
    }

    #[TestWith([200, ['choices' => [['message' => ['refusal' => 'declined']]]]])]
    #[TestWith([200, ['choices' => [['message' => ['content' => ''], 'finish_reason' => 'content_filter']]]])]
    #[TestWith([403, ['error' => ['message' => 'policy denied']]])]
    #[TestWith([408, ['error' => ['message' => 'timeout']]])]
    #[TestWith([402, ['error' => ['message' => 'insufficient credits']]])]
    public function test_a_refusal_or_failure_never_falls_back_to_glm(int $status, array $body): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($body, $status)]);
        $reply = (new CouncilClient)->forProfile('workers_ai')->ask($this->sol(), 'system', 'user');
        $this->assertNull($reply['json']);
        $this->assertNotNull($reply['error']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['model'] === 'openai/gpt-5.6-sol');
    }

    public function test_missing_override_credentials_reject_the_council_before_inference(): void
    {
        config([
            'buddy_agents.council.rosters.workers_ai.members' => [$this->sol()],
            'ai.providers.openrouter.key' => null,
        ]);
        try {
            CouncilProfile::requireConfigured('workers_ai');
            $this->fail('A Sol council must require the OpenRouter credential.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('openrouter', $e->getMessage());
        }
        Http::assertNothingSent();
    }
}
