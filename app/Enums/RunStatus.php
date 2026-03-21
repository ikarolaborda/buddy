<?php

namespace App\Enums;

enum RunStatus: string
{
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';
}
