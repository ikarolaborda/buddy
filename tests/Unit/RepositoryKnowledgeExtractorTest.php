<?php

namespace Tests\Unit;

use App\Services\Knowledge\RepositoryKnowledgeExtractor;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RepositoryKnowledgeExtractorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/buddy-knowledge-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory.'/docs');
        File::ensureDirectoryExists($this->directory.'/app');
        File::ensureDirectoryExists($this->directory.'/tests');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_extracts_contracts_and_symbols_without_indexing_source_bodies_or_secrets(): void
    {
        File::put($this->directory.'/docs/ADR-001.md', "# Search contract\nUse the shared index.\n\n## Access\ntoken=super-secret-value\n");
        File::put($this->directory.'/app/Example.php', <<<'PHP'
<?php
class Example
{
    public function visible(string $name): string
    {
        return 'SOURCE_BODY_MUST_NOT_BE_INDEXED';
    }
}
PHP);
        File::put($this->directory.'/tests/ExampleTest.php', <<<'PHP'
<?php
class ExampleTest
{
    public function test_visible_contract(): void {}
}
PHP);
        File::put($this->directory.'/.env.example', "ALGOLIA_APPLICATION_ID=example\nPASSWORD=do-not-index\n");
        File::put($this->directory.'/.env', "REAL_SECRET=never\n");

        $records = app(RepositoryKnowledgeExtractor::class)->extract(
            $this->directory,
            'buddy',
            'buddy',
            str_repeat('a', 40),
            'staging',
            'https://github.com/ikarolaborda/buddy',
        );
        $serialized = json_encode($records, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($records);
        $this->assertStringContainsString('Search contract', $serialized);
        $this->assertStringContainsString('visible', $serialized);
        $this->assertStringContainsString('ALGOLIA_APPLICATION_ID', $serialized);
        $this->assertStringNotContainsString('SOURCE_BODY_MUST_NOT_BE_INDEXED', $serialized);
        $this->assertStringNotContainsString('super-secret-value', $serialized);
        $this->assertStringNotContainsString('do-not-index', $serialized);
        $this->assertStringNotContainsString('REAL_SECRET', $serialized);
        $this->assertStringContainsString('[REDACTED]', $serialized);
        $this->assertTrue(collect($records)->every(fn (array $record): bool => $record['visibility'] === 'internal'));
    }

    public function test_private_key_material_aborts_the_extraction(): void
    {
        $privateKey = '-----BEGIN '.'PRIVATE KEY-----'."\n".str_repeat('A', 256)."\n".'-----END PRIVATE KEY-----';
        File::put($this->directory.'/docs/unsafe.md', $privateKey);

        $this->expectException(\RuntimeException::class);

        app(RepositoryKnowledgeExtractor::class)->extract(
            $this->directory,
            'buddy',
            'buddy',
            'revision',
            'staging',
        );
    }
}
