<?php

namespace Tests\Feature;

use App\Services\Council\CouncilClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins the council provider profile: a whole roster plus the provider it runs
 * on, selected by one env var.
 *
 * The hazard this guards is specific. CouncilClient used to read
 * config('ai.providers.openrouter.key') unconditionally, so repointing
 * base_url at another provider would have sent an OpenRouter token to it. Base
 * URL and credential must therefore travel together.
 */
class CouncilProfileTest extends TestCase
{
    public function test_the_default_profile_is_still_the_openrouter_roster(): void
    {
        $this->assertSame('openrouter', config('buddy_agents.council.profile'));
        $this->assertSame('https://openrouter.ai/api/v1', config('buddy_agents.council.base_url'));
        $this->assertSame('ai.providers.openrouter.key', config('buddy_agents.council.credential'));
        $this->assertSame('anthropic/claude-fable-5.1', config('buddy_agents.council.chairman.model'));

        $this->assertSame(
            ['openai/gpt-6-astra', 'anthropic/claude-fable-5', 'anthropic/claude-opus-4.8', 'anthropic/claude-sonnet-5', 'google/gemini-3.1-pro-preview'],
            array_column(config('buddy_agents.council.members'), 'model'),
        );
    }

    /**
     * The credential is a CONFIG PATH resolved at call time, not a baked value,
     * so a profile switch cannot leave the wrong provider's token behind.
     */
    public function test_the_request_carries_the_credential_named_by_the_active_profile(): void
    {
        config([
            'buddy_agents.council.base_url' => 'https://example.test/v1',
            'buddy_agents.council.credential' => 'ai.providers.cloudflare.key',
            'buddy_agents.council.headers' => ['X-Title' => 'Buddy Council'],
            'ai.providers.cloudflare.key' => 'CF-TOKEN-XYZ',
            'ai.providers.openrouter.key' => 'OPENROUTER-TOKEN-MUST-NOT-LEAK',
        ]);

        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ])]);

        (new CouncilClient)->ask(['key' => 'gpt', 'model' => '@cf/openai/gpt-oss-120b', 'family' => 'openai'], 'system', 'user');

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';

            $this->assertSame('Bearer CF-TOKEN-XYZ', $auth, 'The active profile names the cloudflare credential, so that is the token that must be sent.');
            $this->assertStringNotContainsString('OPENROUTER', $auth, 'An OpenRouter token must never reach a non-OpenRouter base url.');

            return true;
        });
    }

    /**
     * Provider-specific headers travel with the profile too. HTTP-Referer is an
     * OpenRouter attribution header and is meaningless to Workers AI.
     */
    public function test_profile_headers_replace_rather_than_accumulate(): void
    {
        config([
            'buddy_agents.council.credential' => 'ai.providers.cloudflare.key',
            'buddy_agents.council.headers' => ['X-Title' => 'Buddy Council'],
            'ai.providers.cloudflare.key' => 'CF',
        ]);

        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ])]);

        (new CouncilClient)->ask(['key' => 'gpt', 'model' => '@cf/openai/gpt-oss-120b', 'family' => 'openai'], 's', 'u');

        Http::assertSent(fn ($request) => empty($request->header('HTTP-Referer')));
    }

    /**
     * The workers_ai roster exists to reduce family skew, which ADR 0009
     * discloses in every verdict. Six seats, six families, no overlap.
     */
    public function test_the_workers_ai_roster_has_no_repeated_model_family(): void
    {
        $profiles = require config_path('buddy_agents.php');

        $this->assertContains('workers_ai', $profiles['council']['profiles']);

        $roster = $this->workersAiRoster();
        $families = array_column($roster, 'family');

        $this->assertSame(
            $families,
            array_unique($families),
            'A repeated family reintroduces the skew this roster exists to remove.',
        );
        $this->assertCount(6, $families);
    }

    /**
     * The roster is the measured top five, not a reputation ranking.
     *
     * Eighteen candidates were probed three times each against the real
     * falsification schema at the shipped 24000-token budget on 2026-09-07 and
     * scored on what adjudicate() actually counts: a testimony defeat needs a
     * resolving evidence_ref AND a verbatim kill_condition match. Mean real
     * defeats: mistral 3.7, qwen 2.3, llama 2.0, glm-5.2 2.0, deepseek-r1 2.0.
     */
    public function test_the_roster_is_the_measured_top_five(): void
    {
        $expected = [
            '@cf/mistralai/mistral-small-3.1-24b-instruct',
            '@cf/qwen/qwen3-30b-a3b-fp8',
            '@cf/meta/llama-4-scout-17b-16e-instruct',
            '@cf/zai-org/glm-5.2',
            '@cf/deepseek-ai/deepseek-r1-distill-qwen-32b',
        ];

        $this->assertSame($expected, array_column($this->workersAiMembers(), 'model'));
    }

    /**
     * Two disqualifying failure modes, both measured, both of which a seat can
     * exhibit while looking healthy from the outside.
     *
     * glm-5.3 and kimi-k2.7-code returned HTTP 408 from Cloudflare's own
     * gateway on every trial: they cannot finish a falsification round at all.
     * gpt-oss-120b is perfectly reliable and yields ZERO countable defeats,
     * naming kill conditions verbatim while citing no resolving evidence ref,
     * so adjudicate() records nothing from it.
     */
    public function test_seats_that_cannot_finish_or_cannot_defeat_are_not_seated(): void
    {
        $models = array_column($this->workersAiRoster(), 'model');

        foreach (['@cf/zai-org/glm-5.3', '@cf/moonshotai/kimi-k2.7-code'] as $timesOut) {
            $this->assertNotContains($timesOut, $models, sprintf('%s times out (HTTP 408) on every falsification trial.', $timesOut));
        }

        $this->assertNotContains(
            '@cf/openai/gpt-oss-120b',
            $models,
            'gpt-oss-120b produces zero countable defeats: reliable, and useless as a falsifier.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function workersAiMembers(): array
    {
        $roster = $this->workersAiRoster();

        return array_slice($roster, 1);
    }

    /** @return array<int, array<string, mixed>> */
    private function workersAiRoster(): array
    {
        putenv('BUDDY_COUNCIL_PROFILE=workers_ai');
        $_ENV['BUDDY_COUNCIL_PROFILE'] = 'workers_ai';

        try {
            $config = require config_path('buddy_agents.php');

            return array_merge([$config['council']['chairman']], $config['council']['members']);
        } finally {
            putenv('BUDDY_COUNCIL_PROFILE');
            unset($_ENV['BUDDY_COUNCIL_PROFILE']);
        }
    }
}
