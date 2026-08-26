<?php

namespace App\Services\Agent;

use App\Constants\AgentRunStatus;
use App\Models\AgentRun;
use App\Services\Wiki\WikiMarkdownRenderer;

class AgentRunActivityPresenter
{
    public function __construct(private readonly WikiMarkdownRenderer $markdown) {}

    /** @return array<string, mixed> */
    public function present(AgentRun $run): array
    {
        $events = $run->relationLoaded('events') ? $run->events : $run->events()->get();
        $partialText = '';
        $turn = 0;
        $thinking = false;
        $tools = [];
        $runningByName = [];
        $hasWarnings = false;
        $stage = $run->status === AgentRunStatus::Queued->value ? 'queued' : 'starting';
        $mode = null;
        $scopeReason = null;
        $evidence = [];
        $coverage = [];
        $gaps = [];
        $conflicts = [];
        $semanticWarnings = [];

        foreach ($events as $event) {
            $payload = $event->payloadData();
            if ($event->type === 'query_scoped') {
                $stage = 'planning';
                $mode = is_string($payload['mode'] ?? null) ? $payload['mode'] : null;
                $scopeReason = is_string($payload['reason'] ?? null) ? $payload['reason'] : null;
            } elseif ($event->type === 'plan_completed') {
                $stage = 'planned';
            } elseif ($event->type === 'retrieval_started') {
                $stage = 'retrieving';
            } elseif ($event->type === 'evidence_added') {
                $stage = 'reading';
                $evidence[] = $payload;
            } elseif ($event->type === 'coverage_updated') {
                $stage = 'composing';
                $coverage = is_array($payload['coverage'] ?? null) ? $payload['coverage'] : [];
                $gaps = is_array($payload['gaps'] ?? null) ? $payload['gaps'] : [];
                $conflicts = is_array($payload['conflicts'] ?? null) ? $payload['conflicts'] : [];
                $semanticWarnings = is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [];
                $hasWarnings = $hasWarnings || $semanticWarnings !== [];
            } elseif ($event->type === 'verification_started') {
                $stage = 'verifying';
            } elseif ($event->type === 'verification_failed') {
                $stage = 'repairing';
                $hasWarnings = true;
            } elseif ($event->type === 'answer_completed') {
                $stage = 'completed';
            } elseif ($event->type === 'turn_started') {
                $turn = (int) ($payload['turn'] ?? $turn);
                $thinking = false;
            } elseif ($event->type === 'thinking_started') {
                $thinking = true;
            } elseif ($event->type === 'text_delta') {
                $partialText .= (string) ($payload['text'] ?? '');
                $thinking = false;
            } elseif ($event->type === 'fallback_started') {
                $partialText = '';
                $thinking = false;
            } elseif ($event->type === 'tool_started') {
                $thinking = false;
                $index = count($tools);
                $name = (string) ($payload['name'] ?? 'unknown');
                $tools[] = [
                    'sequence' => $event->sequence,
                    'name' => $name,
                    'status' => 'running',
                    'input' => $payload['input'] ?? [],
                    'output_preview' => '',
                    'duration_ms' => null,
                    'is_error' => false,
                ];
                $runningByName[$name][] = $index;
            } elseif ($event->type === 'tool_completed') {
                $thinking = false;
                $name = (string) ($payload['name'] ?? 'unknown');
                $index = isset($runningByName[$name]) ? array_shift($runningByName[$name]) : null;
                if (($runningByName[$name] ?? []) === []) {
                    unset($runningByName[$name]);
                }
                if ($index === null) {
                    $index = count($tools);
                    $tools[] = [
                        'sequence' => $event->sequence,
                        'name' => $name,
                        'input' => [],
                    ];
                }
                $isError = ($payload['is_error'] ?? true) === true;
                $hasWarnings = $hasWarnings || $isError;
                $tools[$index] = array_merge($tools[$index], [
                    'status' => $isError ? 'error' : 'completed',
                    'output_preview' => (string) ($payload['output_preview'] ?? ''),
                    'duration_ms' => isset($payload['duration_ms']) ? (int) $payload['duration_ms'] : null,
                    'is_error' => $isError,
                ]);
            } elseif (in_array($event->type, ['run_finished', 'run_failed'], true)) {
                $thinking = false;
            }
        }

        $active = in_array($run->status, [
            AgentRunStatus::Queued->value,
            AgentRunStatus::Running->value,
            AgentRunStatus::Cancelling->value,
        ], true);

        return [
            'active' => $active,
            'status' => $run->status,
            'status_label' => $this->statusLabel($run->status),
            'turn' => max($turn, (int) $run->turns_used),
            'thinking' => $active && $thinking,
            'stage' => $stage,
            'stage_label' => $this->stageLabel($stage),
            'mode' => $mode,
            'mode_label' => $mode === 'research' ? '深度研究' : ($mode === 'lookup' ? '快速查找' : null),
            'scope_reason' => $scopeReason,
            'evidence' => $evidence,
            'evidence_count' => count($evidence),
            'coverage' => $coverage,
            'covered_count' => count(array_filter(
                $coverage,
                static fn (mixed $state): bool => in_array($state, ['covered', 'conflict'], true),
            )),
            'coverage_count' => count($coverage),
            'gaps' => $gaps,
            'conflicts' => $conflicts,
            'semantic_warnings' => $semanticWarnings,
            'partial_html' => $this->markdown->render($partialText),
            'response_html' => $this->markdown->render((string) $run->response_text),
            'tools' => $tools,
            'has_warnings' => $hasWarnings,
            'events' => $events,
        ];
    }

    private function stageLabel(string $stage): string
    {
        return match ($stage) {
            'queued' => '等待执行',
            'starting' => '正在启动',
            'planning' => '正在判断问题范围',
            'planned' => '检索计划已生成',
            'retrieving' => '正在检索 Wiki',
            'reading' => '正在整理证据',
            'composing' => '正在生成答案',
            'verifying' => '正在核验证据',
            'repairing' => '正在修正答案',
            'completed' => '答案已核验',
            default => $stage,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            AgentRunStatus::Queued->value => '等待执行',
            AgentRunStatus::Running->value => '执行中',
            AgentRunStatus::Cancelling->value => '正在取消',
            AgentRunStatus::Completed->value => '已完成',
            AgentRunStatus::Cancelled->value => '已取消',
            AgentRunStatus::Failed->value => '失败',
            default => $status,
        };
    }
}
