<?php

namespace App\Services\Agent;

use App\Entities\AnswerDraft;
use App\Entities\AnswerSection;
use App\Entities\EvidenceBundle;
use App\Exceptions\AgentContractException;
use App\Models\AgentRun;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Chat\ChatRepository;
use App\Services\Wiki\WikiWorkspace;
use HaoCode\Sdk\QueryResult;

class QueryWikiWorkflow
{
    public function __construct(
        private readonly ModelAccessPolicy $modelAccess,
        private readonly WikiWorkspace $workspace,
        private readonly QueryPlanningService $planning,
        private readonly WikiAgentFactory $agents,
        private readonly AgentExecutionService $execution,
        private readonly AgentToolContract $toolContract,
        private readonly EvidenceBundleBuilder $evidenceBuilder,
        private readonly AnswerDraftParser $answerParser,
        private readonly AnswerVerifier $answerVerifier,
        private readonly AnswerRenderer $answerRenderer,
        private readonly AgentRunRepository $runs,
        private readonly ChatRepository $chats,
    ) {}

    public function execute(AgentRun $run): void
    {
        $this->modelAccess->assertAllowed();
        $this->workspace->initialize();
        $plan = $this->planning->plan((string) $run->prompt);
        $this->runs->event($run, 'query_scoped', [
            'mode' => $plan->mode,
            'reason' => $plan->reason,
            'wiki_scopes' => $plan->wikiScopes,
        ]);
        $this->runs->event($run, 'plan_completed', $plan->toArray());

        $budget = new QueryToolBudget($plan);
        $this->runs->event($run, 'retrieval_started', [
            'mode' => $plan->mode,
            'max_searches' => $plan->maxSearches,
            'max_reads' => $plan->maxReads,
        ]);
        $retrieval = $this->execution->invoke(
            $run,
            $this->agents->queryAgent($budget),
            $this->retrievalPrompt((string) $run->prompt, $plan->toArray()),
            emitText: false,
        );

        $toolEvents = $this->toolContract->assertLifecycleComplete($run);
        if (! $this->toolContract->hasSuccessfulTool($toolEvents, [
            'ReadWikiPage',
            'SearchWiki',
            'ReadSourceExcerpt',
        ])) {
            throw new AgentContractException(
                'Agent 未完成任何一次成功的知识读取，回答不能标记为完成。',
                $retrieval->result->text,
            );
        }

        $evidence = $this->evidenceBuilder->build($plan, $retrieval->toolInvocations);
        foreach ($evidence->items as $item) {
            $this->runs->event($run, 'evidence_added', $item->toArray());
        }
        $this->runs->event($run, 'coverage_updated', [
            'coverage' => $evidence->coverage,
            'gaps' => $evidence->gaps,
            'conflicts' => $evidence->conflicts,
            'warnings' => $evidence->warnings,
            'evidence_count' => count($evidence->items),
            'tool_budget' => $budget->usage(),
        ]);

        $answer = $this->execution->invoke(
            $run,
            $this->agents->answerAgent(),
            $this->answerPrompt((string) $run->prompt, $plan->toArray(), $evidence->toArray()),
            allowTextFallback: true,
            emitText: false,
            responseSchema: $this->answerParser->schema(),
        );
        $fallbackUsed = $answer->fallbackUsed;

        $this->runs->event($run, 'verification_started', [
            'evidence_count' => count($evidence->items),
            'attempt' => 1,
        ]);
        [$draft, $errors] = $this->parseAndVerify($answer->result->text, $evidence);
        if ($errors !== []) {
            $this->runs->event($run, 'verification_failed', ['errors' => $errors, 'attempt' => 1]);
            $answer = $this->execution->invoke(
                $run,
                $this->agents->answerAgent(),
                $this->repairPrompt(
                    (string) $run->prompt,
                    $plan->toArray(),
                    $evidence->toArray(),
                    $answer->result->text,
                    $errors,
                ),
                allowTextFallback: true,
                emitText: false,
                responseSchema: $this->answerParser->schema(),
            );
            $fallbackUsed = $fallbackUsed || $answer->fallbackUsed;
            $this->runs->event($run, 'verification_started', [
                'evidence_count' => count($evidence->items),
                'attempt' => 2,
            ]);
            [$draft, $errors] = $this->parseAndVerify($answer->result->text, $evidence);
            if ($errors !== []) {
                $this->runs->event($run, 'verification_failed', ['errors' => $errors, 'attempt' => 2]);
                throw new AgentContractException(
                    '答案在一次修正后仍未通过证据验证：'.implode('；', $errors),
                    $answer->result->text,
                );
            }
        }

        if ($draft === null) {
            throw new AgentContractException('答案验证没有产生可渲染结果。', $answer->result->text);
        }

        $rendered = $this->answerRenderer->render($draft, $evidence);
        $citations = $this->answerRenderer->citations($draft, $evidence);
        $finalResult = new QueryResult(
            text: $rendered,
            usage: $answer->result->usage,
            cost: $answer->result->cost,
            sessionId: $answer->result->sessionId,
            turnsUsed: $answer->result->turnsUsed,
            terminationReason: $answer->result->terminationReason,
        );
        $this->runs->event($run, 'answer_completed', [
            'answer_type' => $draft->type,
            'section_count' => count($draft->sections),
            'citation_count' => count($citations),
            'sections' => array_map(static fn (AnswerSection $section): array => [
                'heading' => $section->heading,
                'evidence_ids' => $section->evidenceIds,
                'inference' => $section->inference,
                'confidence' => $section->confidence,
            ], $draft->sections),
        ]);
        $this->chats->addAssistantMessage(
            $run,
            $rendered,
            $citations,
        );
        $this->runs->completeWorkflow($run, $finalResult, $fallbackUsed);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function retrievalPrompt(string $question, array $plan): string
    {
        return "用户问题：\n{$question}\n\nQueryPlan：\n".$this->json($plan)
            ."\n\n严格按计划检索。先读 wiki/index.md；只输出检索摘要，不要生成最终答案。";
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $evidence
     */
    private function answerPrompt(string $question, array $plan, array $evidence): string
    {
        return "用户问题：\n{$question}\n\nQueryPlan：\n".$this->json($plan)
            ."\n\nEvidenceBundle（唯一证据源）：\n".$this->json($evidence)
            ."\n\n只输出符合 schema 的 JSON。证据不足或问题有实质歧义时使用对应 type。";
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $errors
     */
    private function repairPrompt(
        string $question,
        array $plan,
        array $evidence,
        string $draft,
        array $errors,
    ): string {
        return $this->answerPrompt($question, $plan, $evidence)
            ."\n\n上一次草稿：\n{$draft}\n\n确定性验证错误：\n- ".implode("\n- ", $errors)
            ."\n\n只修正这些错误；仍不得新增 Evidence ID。";
    }

    /**
     * @return array{?AnswerDraft, list<string>}
     */
    private function parseAndVerify(string $text, EvidenceBundle $evidence): array
    {
        try {
            $draft = $this->answerParser->parse($text);
        } catch (AgentContractException $exception) {
            return [null, [$exception->getMessage()]];
        }

        return [$draft, $this->answerVerifier->verify($draft, $evidence)];
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
