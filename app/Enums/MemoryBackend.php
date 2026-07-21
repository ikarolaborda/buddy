<?php

namespace App\Enums;

enum MemoryBackend: string
{
    case Legacy = 'legacy';
    case Hub = 'hub';
    case Shadow = 'shadow';
}
