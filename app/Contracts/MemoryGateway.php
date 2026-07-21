<?php

namespace App\Contracts;

use App\DTOs\MemoryCandidate;
use App\DTOs\MemoryFeedback;
use App\DTOs\MemoryHealth;
use App\DTOs\MemoryQuery;
use App\DTOs\MemoryReceipt;
use App\DTOs\MemorySearchPage;

interface MemoryGateway
{
    public function search(MemoryQuery $query): MemorySearchPage;

    public function store(MemoryCandidate $candidate): ?MemoryReceipt;

    public function feedback(MemoryFeedback $feedback): void;

    public function health(): MemoryHealth;
}
