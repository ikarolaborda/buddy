<?php

namespace App\Enums;

enum ProblemType: string
{
    case Bug = 'bug';
    case TestFailure = 'test_failure';
    case Performance = 'performance';
    case Architecture = 'architecture';
    case Integration = 'integration';
    case Configuration = 'configuration';
    case Security = 'security';
    case PromptRefinement = 'prompt_refinement';
    case Ambiguous = 'ambiguous';
    case Other = 'other';
}
