<?php

namespace Tests\Feature;

use App\Services\Agent\PromptRepository;
use RuntimeException;
use Tests\TestCase;

class PromptRepositoryTest extends TestCase
{
    public function test_every_prompt_referenced_by_the_agents_exists_and_is_not_empty(): void
    {
        $prompts = app(PromptRepository::class);

        foreach ([
            'vision-analyst', 'source-analyst', 'wiki-query', 'wiki-answer-composer',
            'wiki-semantic-lint', 'wiki-orchestrator', 'wiki-mapper', 'citation-auditor',
            'query-retrieval', 'query-answer', 'query-repair', 'ingest-orchestration',
            'doctor-system', 'doctor-request',
        ] as $name) {
            $this->assertNotSame('', $prompts->get($name), "提示词为空：{$name}");
        }
    }

    public function test_placeholders_are_replaced(): void
    {
        $rendered = app(PromptRepository::class)->render('query-retrieval', [
            'question' => '备份保留多久？',
            'plan' => '{"mode":"lookup"}',
        ]);

        $this->assertStringContainsString('备份保留多久？', $rendered);
        $this->assertStringContainsString('{"mode":"lookup"}', $rendered);
        $this->assertStringNotContainsString(':question', $rendered);
        $this->assertStringNotContainsString(':plan', $rendered);
    }

    public function test_the_ingest_prompt_builds_a_canonical_citation_shape(): void
    {
        $rendered = app(PromptRepository::class)->render('ingest-orchestration', [
            'path' => 'raw/note.md',
            'sha256' => str_repeat('a', 64),
        ]);

        $this->assertStringContainsString('[[source:raw/note.md|sha256:'.str_repeat('a', 64).'|lines:', $rendered);
    }

    public function test_version_changes_only_when_the_prompt_set_changes(): void
    {
        $version = app(PromptRepository::class)->version();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $version);
        $this->assertSame($version, app(PromptRepository::class)->version());
    }

    public function test_unknown_and_unsafe_names_are_refused(): void
    {
        $prompts = app(PromptRepository::class);

        $this->expectException(RuntimeException::class);
        $prompts->get('../../.env');
    }
}
