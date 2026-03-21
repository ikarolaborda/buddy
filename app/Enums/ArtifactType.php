<?php

namespace App\Enums;

enum ArtifactType: string
{
    case Log = 'log';
    case TestOutput = 'test_output';
    case Stacktrace = 'stacktrace';
    case CodeSnippet = 'code_snippet';
    case Diff = 'diff';
    case Config = 'config';
    case Screenshot = 'screenshot';
    case Other = 'other';
}
