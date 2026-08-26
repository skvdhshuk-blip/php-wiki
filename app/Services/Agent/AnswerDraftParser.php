<?php

namespace App\Services\Agent;

use App\Entities\AnswerDraft;
use App\Entities\AnswerSection;
use App\Exceptions\AgentContractException;

class AnswerDraftParser
{
    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['type', 'sections'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['answer', 'clarification', 'insufficient_evidence']],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['heading', 'content', 'evidence_ids', 'inference', 'confidence'],
                        'properties' => [
                            'heading' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                            'evidence_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'pattern' => '^E[1-9][0-9]*$'],
                            ],
                            'inference' => ['type' => 'boolean'],
                            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        ],
                    ],
                ],
                'clarification_question' => ['type' => 'string'],
                'insufficient_reason' => ['type' => 'string'],
            ],
        ];
    }

    public function parse(string $text): AnswerDraft
    {
        $json = trim($text);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/is', $json, $match) === 1) {
            $json = trim($match[1]);
        }

        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AgentContractException('答案编排结果不是合法 JSON：'.$exception->getMessage(), $text);
        }
        if (! is_array($data)) {
            throw new AgentContractException('答案编排结果必须是 JSON 对象。', $text);
        }

        $type = is_string($data['type'] ?? null) ? $data['type'] : '';
        if (! in_array($type, ['answer', 'clarification', 'insufficient_evidence'], true)) {
            throw new AgentContractException("答案类型无效：{$type}", $text);
        }

        $sections = [];
        foreach (is_array($data['sections'] ?? null) ? $data['sections'] : [] as $section) {
            if (! is_array($section)) {
                throw new AgentContractException('答案 section 必须是对象。', $text);
            }
            $evidenceIds = is_array($section['evidence_ids'] ?? null)
                ? array_values(array_unique(array_filter(
                    $section['evidence_ids'],
                    static fn (mixed $id): bool => is_string($id),
                )))
                : [];
            $sections[] = new AnswerSection(
                heading: trim(is_string($section['heading'] ?? null) ? $section['heading'] : ''),
                content: trim(is_string($section['content'] ?? null) ? $section['content'] : ''),
                evidenceIds: $evidenceIds,
                inference: ($section['inference'] ?? false) === true,
                confidence: is_string($section['confidence'] ?? null) ? $section['confidence'] : '',
            );
        }

        return new AnswerDraft(
            type: $type,
            sections: $sections,
            clarificationQuestion: is_string($data['clarification_question'] ?? null)
                ? trim($data['clarification_question'])
                : null,
            insufficientReason: is_string($data['insufficient_reason'] ?? null)
                ? trim($data['insufficient_reason'])
                : null,
        );
    }
}
