<?php

namespace App\Services\Agent;

use App\Models\ChatMessage;
use App\Services\Wiki\WikiMarkdownRenderer;

class AgentAnswerPresenter
{
    public function __construct(private readonly WikiMarkdownRenderer $markdown) {}

    /**
     * @return array{
     *     type: string,
     *     type_label: string,
     *     type_tone: string,
     *     has_conflicts: bool,
     *     html: string,
     *     citations: list<array<string, string|null>>,
     *     suggestions: list<string>
     * }
     */
    public function present(ChatMessage $message): array
    {
        $citations = $this->citations($message);
        [$answerType, $hasConflicts] = $this->answerState($message);

        return [
            'type' => $answerType,
            'type_label' => $this->typeLabel($answerType, $hasConflicts),
            'type_tone' => $this->typeTone($answerType, $hasConflicts),
            'has_conflicts' => $hasConflicts,
            'html' => $this->markdown->render($this->inlineEvidence($message->content, $citations)),
            'citations' => $citations,
            'suggestions' => $this->suggestions($answerType, $hasConflicts),
        ];
    }

    /** @return list<array<string, string|null>> */
    private function citations(ChatMessage $message): array
    {
        $citations = [];
        foreach ($message->citationData() as $citation) {
            if (! is_array($citation)) {
                continue;
            }

            $evidenceId = $citation['evidence_id'] ?? null;
            if (! is_string($evidenceId) || preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,39}\z/', $evidenceId) !== 1) {
                continue;
            }

            $wikiPath = $this->string($citation['wiki_path'] ?? null);
            $rawPath = $this->string($citation['raw_path'] ?? null);
            $citations[] = [
                'evidence_id' => $evidenceId,
                'wiki_path' => $wikiPath,
                'wiki_url' => $wikiPath === null ? null : route('admin.wiki', ['path' => $wikiPath]),
                'raw_path' => $rawPath,
                'source_path' => $rawPath ?? $wikiPath ?? '未知来源',
                'locator' => $this->string($citation['locator'] ?? null) ?? '未提供定位',
                'quote' => $this->string($citation['quote'] ?? null) ?? '',
            ];
        }

        return $citations;
    }

    /** @param list<array<string, string|null>> $citations */
    private function inlineEvidence(string $markdown, array $citations): string
    {
        $markdown = preg_replace('/\n{2,}---\s*\n{2,}### 来源\s*\n.*\z/su', '', $markdown) ?? $markdown;
        $known = array_fill_keys(array_column($citations, 'evidence_id'), true);

        return preg_replace_callback(
            '/\[\^([A-Za-z][A-Za-z0-9_-]{0,39})\]/',
            static fn (array $match): string => isset($known[$match[1]])
                ? "[{$match[1]}](#evidence-{$match[1]})"
                : $match[0],
            $markdown,
        ) ?? $markdown;
    }

    /** @return array{0: string, 1: bool} */
    private function answerState(ChatMessage $message): array
    {
        $type = null;
        $hasConflicts = false;
        $run = $message->run;
        if ($run !== null) {
            $events = $run->relationLoaded('events') ? $run->events : $run->events()->get();
            foreach ($events as $event) {
                $payload = $event->payloadData();
                if ($event->type === 'answer_completed' && is_string($payload['answer_type'] ?? null)) {
                    $type = $payload['answer_type'];
                }
                if ($event->type === 'coverage_updated' && is_array($payload['conflicts'] ?? null)) {
                    $hasConflicts = $payload['conflicts'] !== [];
                }
            }
        }

        $type ??= match (true) {
            str_starts_with($message->content, '需要先确认一个问题：') => 'clarification',
            str_starts_with($message->content, '当前知识库证据不足') => 'insufficient_evidence',
            default => 'answer',
        };

        return [$type, $hasConflicts];
    }

    private function typeLabel(string $type, bool $hasConflicts): string
    {
        return match (true) {
            $type === 'clarification' => '需要澄清',
            $type === 'insufficient_evidence' => '证据不足',
            $hasConflicts => '回答含冲突证据',
            default => '已核验回答',
        };
    }

    private function typeTone(string $type, bool $hasConflicts): string
    {
        return match (true) {
            $type === 'clarification' => 'indigo',
            $type === 'insufficient_evidence', $hasConflicts => 'amber',
            default => 'green',
        };
    }

    /** @return list<string> */
    private function suggestions(string $type, bool $hasConflicts): array
    {
        if ($type === 'clarification') {
            return [];
        }
        if ($type === 'insufficient_evidence') {
            return [
                '要可靠回答刚才的问题，还需要补充哪些资料？',
                '换一组关键词继续检索刚才的问题。',
            ];
        }
        if ($hasConflicts) {
            return [
                '逐条比较这些冲突证据，并说明各自的时效性。',
                '当前结论还有哪些证据缺口？',
            ];
        }

        return [
            '基于这些来源，还有哪些相反证据？',
            '把刚才的结论按来源分组。',
            '当前结论还有哪些证据缺口？',
        ];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
