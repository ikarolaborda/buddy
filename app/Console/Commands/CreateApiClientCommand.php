<?php

namespace App\Console\Commands;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Services\ApiKeyService;
use Illuminate\Console\Command;

class CreateApiClientCommand extends Command
{
    protected $signature = 'buddy:client:create
        {name : Client name}
        {--project=buddy : Project the client belongs to}
        {--scopes=tasks:read,tasks:write : Comma-separated scopes}
        {--expires-days= : Optional key expiry in days}';

    protected $description = 'Create an API client and issue its first API key';

    public function handle(ApiKeyService $apiKeys): int
    {
        $scopes = [];

        foreach (explode(',', (string) $this->option('scopes')) as $scope) {
            $parsed = ApiScope::tryFrom(trim($scope));

            if ($parsed === null) {
                $this->error("Unknown scope: {$scope}");

                return self::FAILURE;
            }

            $scopes[] = $parsed;
        }

        $client = ApiClient::firstOrCreate([
            'name' => $this->argument('name'),
            'project' => (string) $this->option('project'),
        ]);

        $expiresAt = $this->option('expires-days') !== null
            ? now()->addDays((int) $this->option('expires-days'))
            : null;

        $issued = $apiKeys->issue($client, $scopes, $expiresAt);

        $this->info("Client #{$client->id} ({$client->name})");
        $this->line('API key (shown once, store it now):');
        $this->line($issued['plaintext']);

        return self::SUCCESS;
    }
}
