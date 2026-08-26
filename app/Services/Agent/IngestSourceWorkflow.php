<?php

namespace App\Services\Agent;

use App\Exceptions\AgentContractException;
use App\Models\AgentRun;
use App\Models\WikiSource;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Source\SourceRepository;
use App\Repositories\Wiki\ProposalRepository;
use App\Services\Source\SourceNormalizer;
use App\Services\Wiki\ChangeSetValidator;
use App\Services\Wiki\WikiWorkspace;
use HaoCode\Contracts\RunTerminationReason;
use HaoCode\Sdk\QueryResult;

class IngestSourceWorkflow
{
    public function __construct(
        private readonly ModelAccessPolicy $modelAccess,
        private readonly WikiWorkspace $workspace,
        private readonly SourceNormalizer $normalizer,
        private readonly WikiAgentFactory $agents,
        private readonly AgentExecutionService $execution,
        private readonly AgentToolContract $toolContract,
        private readonly AgentRunRepository $runs,
        private readonly SourceRepository $sources,
        private readonly ProposalRepository $proposals,
        private readonly ChangeSetValidator $validator,
    ) {}

    public function execute(AgentRun $run, WikiSource $source): void
    {
        $this->modelAccess->assertAllowed();
        $this->workspace->initialize();
        $this->sources->markProcessing($source);
        $normalized = $this->normalizer->normalize($source);
        $this->sources->updateWarnings($source, $normalized->warnings);

        $evidence = [];
        try {
            foreach ($this->visualBatches($source, $normalized->images) as $batch) {
                $outcome = $this->execution->invoke(
                    $run,
                    $this->agents->visionAnalyst(),
                    $batch['prompt'],
                    $batch['images'],
                );
                if ($outcome->result->terminationReason === RunTerminationReason::Cancelled) {
                    $this->cancel($run, $source, $outcome->result);

                    return;
                }
                $evidence[] = $outcome->result->text;
            }
        } catch (\Throwable $exception) {
            if ($normalized->hasVisuals()) {
                $this->sources->markBlockedModelCapability($source);
            }
            throw $exception;
        }

        foreach ($this->textChunks($source, $normalized->text) as $index => $chunk) {
            $outcome = $this->execution->invoke(
                $run,
                $this->agents->sourceAnalyst(),
                "source={$source->path}\nsha256={$source->sha256}\ntext_chunk=".($index + 1)."\n\n{$chunk}",
            );
            if ($outcome->result->terminationReason === RunTerminationReason::Cancelled) {
                $this->cancel($run, $source, $outcome->result);

                return;
            }
            $evidence[] = $outcome->result->text;
        }

        if ($evidence === []) {
            throw new AgentContractException('来源没有可供 Agent 分析的文字或视觉证据。');
        }

        $proposal = $this->proposals->createDraft($run, "摄取 {$source->path} 第 {$source->revision} 版");
        $prompt = <<<PROMPT
请把以下待处理来源修订收敛进 Wiki。来源={$source->path}，sha256={$source->sha256}。

本次运行只会处理尚未被当前 Wiki 修订正式吸收的来源。旧式 `[[{$source->path}]]` 链接和 ReadWikiPage 返回的 source_candidates 只是导航线索，不是已验证引用，也不能证明本次修订已经摄取。
你必须至少调用一次 ProposeWikiPage：若知识已存在，就更新最小的现有页面，为相关陈述补上 `[[source:{$source->path}|sha256:{$source->sha256}|lines:<start>-<end>]]` 形式的规范引用；确有独立知识时才新建页面。不得以“已有旧链接”或“无需改动”为由跳过提案。

PROMPT;
        foreach ($evidence as $index => $item) {
            $prompt .= '## Evidence '.($index + 1)."\n\n{$item}\n\n";
        }

        try {
            $outcome = $this->execution->invoke($run, $this->agents->orchestrator($proposal), $prompt);
            if ($outcome->result->terminationReason === RunTerminationReason::Cancelled) {
                $this->proposals->invalidateDraft($proposal, '运行已取消，未形成可审批 ChangeSet。');
                $this->cancel($run, $source, $outcome->result);

                return;
            }

            $this->assertToolContract($run);
            $proposal = $this->proposals->reloadWithChanges($proposal);
            $errors = $this->validator->validate($proposal);
            $this->proposals->setValidation($proposal, $errors);
            if ($errors !== []) {
                throw new AgentContractException("提案未通过确定性验证：\n".implode("\n", $errors));
            }

            $this->sources->markReady($source);
            $this->runs->completeWorkflow($run, $outcome->result, $outcome->fallbackUsed);
        } catch (\Throwable $exception) {
            $this->proposals->invalidateDraft($proposal, '关联 Agent 运行未形成有效 ChangeSet。');

            throw $exception;
        }
    }

    /**
     * @param  list<string>  $images
     * @return list<array{prompt: string, images: list<string>}>
     */
    private function visualBatches(WikiSource $source, array $images): array
    {
        $batchSize = max(1, (int) config('phpwiki.visual.pdf_batch_size'));
        $artifacts = $this->sources->artifacts($source)->values();
        $batches = [];
        foreach (array_chunk($images, $batchSize) as $batchIndex => $batch) {
            $offset = $batchIndex * $batchSize;
            $labels = [];
            foreach ($batch as $index => $image) {
                $artifact = $artifacts->get($offset + $index);
                $locator = $artifact?->page !== null
                    ? 'page:'.$artifact->page
                    : 'region:attachment-'.($offset + $index + 1);
                $labels[] = 'attachment '.($index + 1)." => {$source->path}|sha256:{$source->sha256}|{$locator}";
            }
            $batches[] = [
                'prompt' => "分析这些直接附加的视觉证据。\n".implode("\n", $labels),
                'images' => $batch,
            ];
        }

        return $batches;
    }

    /** @return list<string> */
    private function textChunks(WikiSource $source, string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        if ($source->type === 'pdf') {
            $chunks = [];
            for ($offset = 0, $length = strlen($text); $offset < $length; $offset += strlen($chunk)) {
                $chunk = mb_strcut($text, $offset, 30_000, 'UTF-8');
                if ($chunk === '') {
                    break;
                }
                $chunks[] = $chunk;
            }

            return $chunks;
        }

        $chunks = [];
        $current = '';
        foreach (preg_split('/\R/u', $text) ?: [] as $index => $line) {
            $numbered = ($index + 1).': '.$line."\n";
            if (strlen($current) + strlen($numbered) > 30_000 && $current !== '') {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $numbered;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function assertToolContract(AgentRun $run): void
    {
        $events = $this->toolContract->assertLifecycleComplete($run);
        $started = $events->where('type', 'tool_started');
        $completed = $events->where('type', 'tool_completed');
        foreach ($completed as $event) {
            if (($event->payloadData()['is_error'] ?? true) === true) {
                $name = (string) ($event->payloadData()['name'] ?? 'unknown');
                throw new AgentContractException("工具执行失败：{$name}");
            }
        }

        foreach (['MapKnowledge', 'AuditKnowledge', 'ProposeWikiPage'] as $name) {
            if (! $events->contains(fn ($event): bool => $event->type === 'tool_started' && ($event->payloadData()['name'] ?? null) === $name)) {
                throw new AgentContractException("必需工具未调用：{$name}");
            }
            $completion = $events->first(fn ($event): bool => $event->type === 'tool_completed' && ($event->payloadData()['name'] ?? null) === $name);
            $payload = $completion?->payloadData() ?? [];
            if ($completion === null || ($payload['is_error'] ?? true) || trim((string) ($payload['output_preview'] ?? '')) === '') {
                throw new AgentContractException("工具没有产出可用结果：{$name}");
            }
        }
    }

    private function cancel(AgentRun $run, WikiSource $source, QueryResult $result): void
    {
        $this->sources->markPending($source);
        $this->runs->completeWorkflow($run, $result);
    }
}
