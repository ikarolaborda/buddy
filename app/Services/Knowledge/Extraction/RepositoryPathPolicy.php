<?php

namespace App\Services\Knowledge\Extraction;

use SplFileInfo;

final class RepositoryPathPolicy
{
    private const EXCLUDED_DIRECTORIES = [
        '.git', '.idea', '.next', '.nuxt', '.turbo',
        '.pytest_cache', 'build', 'coverage', 'DerivedData', 'dist',
        'node_modules', 'obj', 'playwright-report', 'Pods', 'storage',
        'target', 'test-results', 'vendor',
    ];

    private const MAX_FILE_BYTES = 1_000_000;

    public function shouldIndex(SplFileInfo $file, string $relativePath): bool
    {
        if (! $file->isFile() || $file->isLink() || $file->getSize() > self::MAX_FILE_BYTES) {
            return false;
        }

        $segments = explode('/', $relativePath);
        $directories = array_slice($segments, 0, -1);

        if (array_intersect(self::EXCLUDED_DIRECTORIES, $segments) !== []) {
            return false;
        }

        foreach ($directories as $directory) {
            if (str_starts_with($directory, '.') && $directory !== '.github') {
                return false;
            }
        }

        return ! $this->isSensitivePath($relativePath);
    }

    public function isMarkdown(KnowledgeSourceFile $source): bool
    {
        return in_array($source->extension(), ['md', 'mdx', 'rst'], true);
    }

    public function isOpenApi(KnowledgeSourceFile $source): bool
    {
        return preg_match('/(?:openapi|swagger)/i', $source->path) === 1
            || preg_match('/^[\t ]*(?:openapi|swagger):\s*[\'\"]?[23]/mi', $source->contents) === 1;
    }

    public function isConfiguration(KnowledgeSourceFile $source): bool
    {
        $normalized = strtolower('/'.$source->path);

        return str_contains($normalized, '/config/')
            || str_contains($normalized, '/.github/workflows/')
            || in_array($source->basename(), [
                'app.json', 'composer.json', 'package.json', 'tsconfig.json',
                'vite.config.js', 'vite.config.ts', 'docker-compose.yml', 'docker-compose.yaml',
                '.env.example', '.env.sample', '.env.template', 'dockerfile', 'makefile',
            ], true);
    }

    public function isTest(KnowledgeSourceFile $source): bool
    {
        return preg_match('#(^|/)(tests?|spec|__tests__)/#i', $source->path) === 1
            || preg_match('/(?:test|spec)\.[A-Za-z0-9]+$/i', $source->path) === 1;
    }

    public function isCode(KnowledgeSourceFile $source): bool
    {
        return in_array($source->extension(), [
            'go', 'java', 'js', 'jsx', 'kt', 'kts', 'php', 'py', 'swift', 'ts', 'tsx',
        ], true);
    }

    private function isSensitivePath(string $path): bool
    {
        $basename = strtolower(basename($path));
        $isPrivateEnvironment = str_starts_with($basename, '.env')
            && ! in_array($basename, ['.env.example', '.env.sample', '.env.template'], true);

        return $isPrivateEnvironment
            || preg_match('/\.(?:key|pem|p12|pfx|sqlite|db)$/i', $basename) === 1
            || in_array($basename, ['composer.lock', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'], true);
    }
}
