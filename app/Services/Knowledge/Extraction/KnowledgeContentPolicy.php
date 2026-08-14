<?php

namespace App\Services\Knowledge\Extraction;

final class KnowledgeContentPolicy
{
    public function assertSafe(KnowledgeSourceFile $source): void
    {
        preg_match_all(
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----\s+([A-Za-z0-9+\/=\r\n]+)\s+-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
            $source->contents,
            $matches,
        );

        foreach ($matches[1] ?? [] as $payload) {
            if (strlen((string) preg_replace('/\s+/', '', $payload)) >= 128) {
                throw new \RuntimeException("Private key material detected in {$source->path}; indexing aborted.");
            }
        }
    }

    public function sanitize(string $value): string
    {
        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i' => 'Bearer [REDACTED]',
            '/\b(api[_-]?key|client[_-]?secret|password|secret|token)\b\s*[:=]\s*[\'\"]?[^\s\'\"`,;]+/i' => '$1=[REDACTED]',
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/' => '[REDACTED_JWT]',
            '/\b[A-Fa-f0-9]{40,}\b/' => '[REDACTED_TOKEN]',
            '/\b[A-Za-z0-9+\/_-]{48,}={0,2}\b/' => '[REDACTED_TOKEN]',
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => '[REDACTED_EMAIL]',
        ];

        return trim((string) preg_replace(array_keys($patterns), array_values($patterns), $value));
    }
}
