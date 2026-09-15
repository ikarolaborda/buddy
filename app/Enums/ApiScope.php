<?php

namespace App\Enums;

enum ApiScope: string
{
    case TasksWrite = 'tasks:write';
    case TasksRead = 'tasks:read';
    case MemoryRead = 'memory:read';
    case MemoryWrite = 'memory:write';
    case InterventionsExecute = 'interventions:execute';
    case DiagnosticsCapture = 'diagnostics:capture';
    case Admin = 'admin';
}
