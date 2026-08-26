<?php

namespace App\Services\Agent;

use App\Models\WikiProposal;
use App\Repositories\Source\SourceRepository;
use App\Repositories\Wiki\ProposalRepository;
use App\Services\Agent\Tools\ProposeWikiPageTool;
use App\Services\Agent\Tools\ReadSourceExcerptTool;
use App\Services\Agent\Tools\ReadWikiPageTool;
use App\Services\Agent\Tools\SearchWikiTool;
use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiSearchService;
use App\Services\Wiki\WikiWorkspace;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\SdkTool;

class WikiAgentFactory
{
    public function __construct(
        private readonly WikiSearchService $search,
        private readonly WikiWorkspace $workspace,
        private readonly WikiPathGuard $paths,
        private readonly SourceRepository $sources,
        private readonly ProposalRepository $proposals,
    ) {}

    public function visionAnalyst(): Agent
    {
        return $this->agent(
            name: 'vision-analyst',
            prompt: <<<'PROMPT'
你是 PHP Wiki 的视觉证据分析 Agent。图片已直接附在本次用户消息中，你确实可以观察它们。
逐图或逐页提取文字、图表、结构、实体、关系、异常和不确定性。每条结论必须绑定用户消息给出的来源路径、SHA-256、页码或图片区域。
不要提出 Wiki 修改，不要猜测不可见内容。输出紧凑、可供下游 Agent 使用的中文证据清单。
PROMPT,
        );
    }

    public function sourceAnalyst(): Agent
    {
        return $this->agent(
            name: 'source-analyst',
            prompt: <<<'PROMPT'
你是 PHP Wiki 的来源分析 Agent。分析用户提供的原始文本，提取事实、概念、实体、关系、冲突和开放问题。
所有事实都必须保留用户消息中的 raw 路径、SHA-256 与行号或 PDF 页码。不得伪造引用，不要直接提出文件写入。
输出供 Wiki 编排 Agent 使用的中文证据清单。
PROMPT,
        );
    }

    public function queryAgent(): Agent
    {
        $tools = $this->readTools();

        return $this->agent(
            name: 'wiki-query',
            prompt: <<<'PROMPT'
你是个人知识库问答 Agent。先读取 wiki/index.md，再按需搜索并读取 Wiki 页面或原始文字摘录。
只根据工具返回的已批准知识回答；区分事实与推断。关键结论后保留原始 `[[source:...]]` 引用。
找不到依据时直说，不得用常识填空。最终必须输出非空中文回答。
PROMPT,
            allowedTools: array_map(static fn (SdkTool $tool): string => $tool->name(), $tools),
            tools: $tools,
        );
    }

    public function semanticLintAgent(): Agent
    {
        $tools = $this->readTools();

        return $this->agent(
            name: 'wiki-semantic-lint',
            prompt: <<<'PROMPT'
你是 Wiki 语义审计 Agent。结合确定性 Lint 结果、index.md 和相关页面，识别矛盾、重复、失去来源支持的结论与知识缺口。
只读，不得提出直接写入。输出按严重程度排序的中文问题清单，关键判断保留来源引用。
PROMPT,
            allowedTools: array_map(static fn (SdkTool $tool): string => $tool->name(), $tools),
            tools: $tools,
        );
    }

    public function orchestrator(WikiProposal $proposal): Agent
    {
        $readTools = $this->readTools();
        $readNames = array_map(static fn (SdkTool $tool): string => $tool->name(), $readTools);
        $mapper = $this->agent(
            name: 'wiki-mapper',
            prompt: '把证据映射到现有 Wiki 结构。先读 index 和 AGENTS.md，给出最小页面集合、合并位置和需要保留的引用。不得写文件。',
            allowedTools: $readNames,
        );
        $auditor = $this->agent(
            name: 'citation-auditor',
            prompt: '审计证据与拟议知识的引用、矛盾、不确定性和遗漏。只输出可执行审计意见，不得写文件。',
            allowedTools: $readNames,
        );
        $tools = [
            ...$readTools,
            $mapper->asTool('MapKnowledge', 'Map source evidence into the smallest coherent set of wiki pages.'),
            $auditor->asTool('AuditKnowledge', 'Audit source evidence and planned wiki changes for citation and contradiction risks.'),
            new ProposeWikiPageTool($proposal, $this->proposals, $this->paths),
        ];

        return $this->agent(
            name: 'wiki-orchestrator',
            prompt: <<<'PROMPT'
你是 PHP Wiki 的总编排 Agent。必须先调用 MapKnowledge，再调用 AuditKnowledge；然后读取 AGENTS.md 和必要的 Wiki 页面。
基于证据维护一个持续演化、可引用的 Markdown Wiki，而不是生成孤立摘要。只做必要修改：新知识优先合并到现有页面，确有独立概念时才新建页面。
每个要修改的文件必须调用一次 ProposeWikiPage，提交完整内容、当前页面 SHA-256（新页面留空）和理由。禁止直接写文件，禁止修改 raw/。
最终回答要总结已记录的提案；即使工具调用成功，最终文本也必须非空。
PROMPT,
            allowedTools: array_map(static fn (SdkTool $tool): string => $tool->name(), $tools),
            tools: $tools,
        );
    }

    /** @return list<SdkTool> */
    private function readTools(): array
    {
        return [
            new SearchWikiTool($this->search),
            new ReadWikiPageTool($this->paths, $this->workspace),
            new ReadSourceExcerptTool($this->paths, $this->sources),
        ];
    }

    /**
     * @param  list<string>  $allowedTools
     * @param  list<SdkTool>  $tools
     */
    private function agent(string $name, string $prompt, array $allowedTools = [], array $tools = []): Agent
    {
        $apiKey = trim((string) config('phpwiki.model.api_key'));

        return new Agent(
            name: $name,
            model: (string) config('phpwiki.model.name'),
            apiKey: $apiKey !== '' ? $apiKey : null,
            baseUrl: (string) config('phpwiki.model.base_url'),
            providerType: (string) config('phpwiki.model.provider'),
            maxTokens: (int) config('phpwiki.model.max_tokens'),
            maxTurns: (int) config('phpwiki.model.max_turns'),
            systemPrompt: $prompt,
            permissionMode: 'bypass_permissions',
            allowedTools: $allowedTools,
            tools: $tools,
            ephemeral: true,
            contextPreset: 'generic',
        );
    }
}
