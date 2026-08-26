<?php

namespace App\Services\Application;

use App\Jobs\IngestSourceJob;
use App\Jobs\QueryWikiJob;
use App\Jobs\SemanticLintJob;
use App\Models\AgentRun;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\WikiProposal;
use App\Models\WikiSource;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Agent\QueryRunStore;
use App\Repositories\Wiki\ProposalRepository;
use App\Services\Wiki\ChangeSetValidator;
use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiWorkspace;

class AgentRunDispatchService
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly QueryRunStore $queryRuns,
        private readonly ProposalRepository $proposals,
        private readonly ChangeSetValidator $validator,
        private readonly WikiPathGuard $paths,
        private readonly WikiWorkspace $workspace,
    ) {}

    public function ingest(WikiSource $source): AgentRun
    {
        $active = $this->runs->activeForSource($source->id);
        if ($active !== null) {
            return $active;
        }

        $run = $this->runs->createQueued('ingest', sourceId: $source->id);
        IngestSourceJob::dispatch($run->id, $source->id);

        return $run;
    }

    public function query(ChatThread $thread, string $question): AgentRun
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('问题不能为空。');
        }

        $run = $this->queryRuns->create($thread, $question);
        QueryWikiJob::dispatch($run->id)->afterCommit();

        return $run;
    }

    public function semanticLint(): AgentRun
    {
        $run = $this->runs->createQueued('semantic_lint', '审计当前 Wiki 的语义健康度。');
        SemanticLintJob::dispatch($run->id);

        return $run;
    }

    public function cancel(AgentRun $run): void
    {
        $this->runs->requestCancellation($run);
    }

    public function saveAnswerAsProposal(ChatMessage $message): WikiProposal
    {
        if ($message->role !== 'assistant') {
            throw new \InvalidArgumentException('只有 Agent 回答可以保存为提案。');
        }

        preg_match_all('/\[\[source:([^|\]]+)\|/', $message->content, $matches);
        $sourceIds = array_values(array_unique($matches[1]));
        $date = now()->toDateString();
        $path = 'wiki/syntheses/answer-'.$message->id.'.md';
        $frontmatterSources = $sourceIds === []
            ? '[]'
            : '['.implode(', ', array_map(static fn (string $item): string => '"'.addslashes($item).'"', $sourceIds)).']';
        $content = <<<MARKDOWN
        ---
        type: wiki/synthesis
        status: draft
        updated: {$date}
        source_ids: {$frontmatterSources}
        confidence: medium
---

# 对话沉淀 {$message->id}

{$message->content}
MARKDOWN;

        $proposal = $this->proposals->createDraft($message->run, '把 Agent 回答保存为 Wiki synthesis');
        $this->proposals->putPage($proposal, $path, $content, null, '用户主动选择保存该回答。');
        $proposal = $this->proposals->reloadWithChanges($proposal);
        $this->proposals->setValidation($proposal, $this->validator->validate($proposal));

        return $this->proposals->reloadWithChanges($proposal);
    }

    public function proposeArchive(string $path): WikiProposal
    {
        $path = $this->paths->assertManagedPath($path);
        if (in_array($path, ['AGENTS.md', 'wiki/index.md', 'wiki/log.md'], true)) {
            throw new \InvalidArgumentException('核心 Schema、索引和日志不能归档。');
        }
        $hash = $this->workspace->sha256($path) ?? throw new \InvalidArgumentException('页面不存在。');
        $destination = 'wiki/archive/'.now()->format('Ymd-His').'-'.basename($path);
        $proposal = $this->proposals->createDraft(null, "归档 {$path}");
        $this->proposals->archivePage($proposal, $path, $destination, $hash, '用户发起页面归档。');
        $proposal = $this->proposals->reloadWithChanges($proposal);
        $this->proposals->setValidation($proposal, $this->validator->validate($proposal));

        return $this->proposals->reloadWithChanges($proposal);
    }
}
