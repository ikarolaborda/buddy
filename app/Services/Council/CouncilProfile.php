<?php

namespace App\Services\Council;

use Illuminate\Validation\ValidationException;

final class CouncilProfile
{
    public static function resolve(?string $name = null): array
    {
        $active = (array) config('buddy_agents.council');
        $name ??= $active['profile'];

        if (! in_array($name, $active['profiles'], true)) {
            throw ValidationException::withMessages(['profile' => 'Unknown council profile.']);
        }

        if ($name === $active['profile']) {
            return $active;
        }

        return array_replace($active, config('buddy_agents.council.rosters')[$name], ['profile' => $name]);
    }

    public static function requireConfigured(string $name): array
    {
        $profile = self::resolve($name);
        $providers = [$name];
        foreach ([$profile['chairman'], ...$profile['members']] as $member) {
            $providers[] = $member['provider_profile'] ?? $name;
        }

        foreach (array_unique($providers) as $provider) {
            $transport = self::resolve($provider);
            if (! config($transport['credential']) || ! filter_var($transport['base_url'], FILTER_VALIDATE_URL)) {
                throw ValidationException::withMessages(['profile' => 'Council provider endpoint or credential is not configured: '.$provider.'.']);
            }
        }

        return $profile;
    }
}
