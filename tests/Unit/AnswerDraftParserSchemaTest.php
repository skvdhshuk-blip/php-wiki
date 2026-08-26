<?php

namespace Tests\Unit;

use App\Services\Agent\AnswerDraftParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnswerDraftParserSchemaTest extends TestCase
{
    #[DataProvider('typeSchemas')]
    public function test_type_specific_schema_closes_invalid_answer_shapes(
        string $type,
        int $minimumSections,
        int $maximumSections,
        ?string $requiredReason,
    ): void {
        $schema = (new AnswerDraftParser)->schema($type);

        $this->assertSame([$type], $schema['properties']['type']['enum']);
        $this->assertSame($minimumSections, $schema['properties']['sections']['minItems'] ?? 0);
        $this->assertSame($maximumSections, $schema['properties']['sections']['maxItems'] ?? PHP_INT_MAX);
        $this->assertSame(1, $schema['properties']['sections']['items']['properties']['evidence_ids']['minItems']);
        if ($requiredReason !== null) {
            $this->assertContains($requiredReason, $schema['required']);
        }
    }

    /** @return iterable<string, array{string, int, int, ?string}> */
    public static function typeSchemas(): iterable
    {
        yield 'answer' => ['answer', 1, PHP_INT_MAX, null];
        yield 'clarification' => ['clarification', 0, 0, 'clarification_question'];
        yield 'insufficient evidence' => ['insufficient_evidence', 0, 0, 'insufficient_reason'];
    }
}
