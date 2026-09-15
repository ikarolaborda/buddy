<?php

namespace App\Console\Commands;

use App\Services\Council\CouncilClient;
use App\Services\Council\CouncilProfile;
use Illuminate\Console\Command;

class CouncilProbeCommand extends Command
{
    protected $signature = 'buddy:council-probe {profile=azure}';

    protected $description = 'Make one bounded live JSON request per council seat and verify its configured deployment';

    public function handle(CouncilClient $client): int
    {
        $name = (string) $this->argument('profile');
        $profile = CouncilProfile::requireConfigured($name);
        $members = [$profile['chairman'], ...$profile['members']];
        $replies = $client->forProfile($name)->askAll(
            $members,
            'This is a deployment connectivity probe. Return only a JSON object with ok set to true.',
            fn ($member) => 'Verify JSON output for this seat. Return {"ok":true}.',
        );

        $passed = true;
        foreach ($members as $member) {
            $reply = $replies[$member['key']];
            $ok = ($reply['json']['ok'] ?? null) === true;
            $passed = $passed && $ok;
            $this->line(json_encode([
                'seat' => $member['key'],
                'model' => $member['model'],
                'provider_profile' => $member['provider_profile'] ?? $name,
                'reasoning_effort' => $member['reasoning_effort'] ?? 'provider_default',
                'ok' => $ok,
                'usage' => $reply['usage'],
            ], JSON_THROW_ON_ERROR));
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
