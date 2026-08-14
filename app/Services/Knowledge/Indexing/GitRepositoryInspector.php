<?php

namespace App\Services\Knowledge\Indexing;

use Symfony\Component\Process\Process;

final class GitRepositoryInspector
{
    public function revision(string $path): string
    {
        return $this->execute($path, ['rev-parse', 'HEAD']);
    }

    public function sourceUrl(string $path): ?string
    {
        $remote = $this->execute($path, ['remote', 'get-url', 'origin']);
        if (preg_match('#^git@(?:[^:]+:)?github\.com:(.+)$#', $remote, $match) === 1) {
            return 'https://github.com/'.preg_replace('/\.git$/', '', $match[1]);
        }

        if (str_starts_with($remote, 'https://github.com/')) {
            return preg_replace('/\.git$/', '', $remote);
        }

        return null;
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function execute(string $path, array $arguments): string
    {
        $process = new Process(['git', '-C', $path, ...$arguments]);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }
}
