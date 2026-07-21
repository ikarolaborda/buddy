<?php

namespace App\Enums;

enum ApiScope: string
{
    case TasksWrite = 'tasks:write';
    case TasksRead = 'tasks:read';
    case MemoryRead = 'memory:read';
    case MemoryWrite = 'memory:write';
    case Admin = 'admin';
}
