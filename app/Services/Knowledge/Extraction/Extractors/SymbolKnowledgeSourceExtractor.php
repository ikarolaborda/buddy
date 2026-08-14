<?php

namespace App\Services\Knowledge\Extraction\Extractors;

use App\Services\Knowledge\Extraction\Contracts\KnowledgeSourceExtractor;
use App\Services\Knowledge\Extraction\ExtractedKnowledgeChunk;
use App\Services\Knowledge\Extraction\KnowledgeSourceFile;
use App\Services\Knowledge\Extraction\RepositoryPathPolicy;
use Illuminate\Support\Str;

final readonly class SymbolKnowledgeSourceExtractor implements KnowledgeSourceExtractor
{
    /**
     * @var array<int, SymbolPattern>
     */
    private array $patterns;

    public function __construct(
        private RepositoryPathPolicy $pathPolicy,
    ) {
        $this->patterns = [
            new SymbolPattern(
                '/^\s*(?:(?:public|protected|private|static|final|abstract|async|export|open|override)\s+)*(class|interface|trait|enum|struct|protocol)\s+([A-Za-z_][A-Za-z0-9_]*)[^\n{]*/mi',
                nameGroup: 2,
                kindGroup: 1,
            ),
            new SymbolPattern(
                '/^\s*(?:(?:public|protected|private|static|final|abstract|async|export|open|override|suspend)\s+)*(function|func|fun|def)\s+([A-Za-z_][A-Za-z0-9_]*)\s*\([^\n{]*\)[^\n{]*/mi',
                nameGroup: 2,
                kindGroup: 1,
            ),
            new SymbolPattern(
                '/^\s*(?:export\s+)?(?:const|let|var)\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:async\s*)?\([^\n]*?\)\s*=>/mi',
                nameGroup: 1,
                defaultKind: 'function',
            ),
            new SymbolPattern(
                '/^\s*func\s+(?:\([^\n)]*\)\s*)?([A-Za-z_][A-Za-z0-9_]*)\s*\([^\n{]*/mi',
                nameGroup: 1,
                defaultKind: 'function',
            ),
            new SymbolPattern(
                '/\b(?:it|test|describe)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/mi',
                nameGroup: 1,
                defaultKind: 'test',
            ),
        ];
    }

    public function supports(KnowledgeSourceFile $source): bool
    {
        return $this->pathPolicy->isTest($source) || $this->pathPolicy->isCode($source);
    }

    public function extract(KnowledgeSourceFile $source): array
    {
        $chunks = [];
        $contentType = $this->pathPolicy->isTest($source) ? 'test_contract' : 'code_symbol';

        foreach ($this->patterns as $pattern) {
            preg_match_all(
                $pattern->expression,
                $source->contents,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            foreach ($matches as $match) {
                $name = (string) ($match[$pattern->nameGroup][0] ?? $match[0][0]);
                $kind = $pattern->kindGroup === null
                    ? $pattern->defaultKind
                    : (string) ($match[$pattern->kindGroup][0] ?? $pattern->defaultKind);
                $line = $source->lineNumberAt((int) $match[0][1]);

                $chunks[] = new ExtractedKnowledgeChunk(
                    anchor: Str::slug($name).'-'.$line,
                    title: $name,
                    body: trim((string) $match[0][0]),
                    contentType: $contentType,
                    symbolKind: $kind,
                    symbolName: $name,
                    line: $line,
                    tags: [$kind],
                );
            }
        }

        $unique = [];
        foreach ($chunks as $chunk) {
            $unique[$chunk->anchor] = $chunk;
        }

        return array_values($unique);
    }
}
