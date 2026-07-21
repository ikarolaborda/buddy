<?php

namespace App\Ai\Prompting;

use App\Models\BuddyTask;

class PromptCompiler
{
    protected const CORE_MODULES = [
        'core/identity',
        'core/epistemic-discipline',
        'core/security-boundaries',
        'core/memory-policy',
        'core/decision-policy',
        'core/output-contract',
    ];

    public function __construct(
        protected PromptRegistry $registry,
        protected PromptModuleRouter $router,
    ) {}

    public function compile(string $agentKey, ?BuddyTask $task = null): PromptBundle
    {
        $moduleIds = self::CORE_MODULES;

        if ($task !== null) {
            $moduleIds = array_merge($moduleIds, $this->router->domainsFor($task));
        }

        $moduleIds[] = 'agents/'.$agentKey;

        $moduleHashes = [];
        $texts = [];

        foreach ($moduleIds as $moduleId) {
            $texts[] = $this->registry->module($moduleId);
            $moduleHashes[$moduleId] = $this->registry->hash($moduleId);
        }

        $text = implode("\n\n---\n\n", $texts);

        return new PromptBundle(
            agent: $agentKey,
            moduleIds: $moduleIds,
            moduleHashes: $moduleHashes,
            text: $text,
            contentHash: hash('sha256', $text),
        );
    }
}
