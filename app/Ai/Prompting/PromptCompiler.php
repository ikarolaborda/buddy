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

    /**
     * @param  array<string, string>  $overrides  candidate module text keyed by
     *                                            module ID — used by the CIL
     *                                            replay engine, never by live
     *                                            traffic
     */
    public function compile(string $agentKey, ?BuddyTask $task = null, array $overrides = []): PromptBundle
    {
        $moduleIds = self::CORE_MODULES;

        if ($task !== null) {
            $moduleIds = array_merge($moduleIds, $this->router->domainsFor($task));
        }

        $moduleIds[] = 'agents/'.$agentKey;

        $moduleHashes = [];
        $texts = [];

        foreach ($moduleIds as $moduleId) {
            $text = $overrides[$moduleId] ?? $this->registry->module($moduleId);
            $texts[] = $text;
            $moduleHashes[$moduleId] = isset($overrides[$moduleId])
                ? hash('sha256', $text)
                : $this->registry->hash($moduleId);
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
