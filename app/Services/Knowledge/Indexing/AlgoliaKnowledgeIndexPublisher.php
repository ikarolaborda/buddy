<?php

namespace App\Services\Knowledge\Indexing;

use Algolia\AlgoliaSearch\Api\SearchClient;

final readonly class AlgoliaKnowledgeIndexPublisher
{
    public function __construct(
        private KnowledgeIndexSettings $settings,
    ) {}

    public function publish(EcosystemKnowledgeIndex $index): void
    {
        $applicationId = (string) config('buddy.knowledge.algolia.application_id');
        $writeKey = (string) config('buddy.knowledge.algolia.write_key');
        if ($applicationId === '' || $writeKey === '') {
            throw new \InvalidArgumentException(
                'ALGOLIA_APPLICATION_ID and ALGOLIA_WRITE_API_KEY are required for a live index operation.',
            );
        }

        $client = SearchClient::create($applicationId, $writeKey);
        $settingsResponse = $client->setSettings($index->name, $this->settings->toArray());
        $settingsTaskId = $settingsResponse['taskID'] ?? null;
        if ($settingsTaskId !== null) {
            $client->waitForTask($index->name, $settingsTaskId);
        }

        $client->replaceAllObjects($index->name, $index->records);
    }
}
