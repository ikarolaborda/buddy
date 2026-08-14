<?php

namespace App\Services\Knowledge;

use App\Contracts\EcosystemKnowledgeGateway;
use App\DTOs\EcosystemKnowledgePage;
use App\DTOs\EcosystemKnowledgeQuery;

class NullEcosystemKnowledgeGateway implements EcosystemKnowledgeGateway
{
    public function search(EcosystemKnowledgeQuery $query): EcosystemKnowledgePage
    {
        return EcosystemKnowledgePage::degraded('ecosystem knowledge is disabled');
    }
}
