<?php

namespace App\Contracts;

use App\Models\OutboxMessage;

interface OutboxHandler
{
    public function handle(OutboxMessage $message): void;
}
