<?php

namespace App\Contracts;

use App\DTOs\EcosystemKnowledgePage;
use App\DTOs\EcosystemKnowledgeQuery;

interface EcosystemKnowledgeGateway
{
    public function search(EcosystemKnowledgeQuery $query): EcosystemKnowledgePage;
}
