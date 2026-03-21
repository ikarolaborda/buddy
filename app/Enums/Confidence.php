<?php

namespace App\Enums;

enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case None = 'none';
}
