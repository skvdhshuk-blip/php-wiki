<?php

namespace App\Services\Agent;

use App\Models\WikiProposal;
use App\Repositories\Source\SourceRepository;
use App\Repositories\Wiki\ProposalRepository;
use App\Services\Agent\Tools\ProposeWikiPageTool;
use App\Services\Agent\Tools\ReadSourceExcerptTool;
use App\Services\Agent\Tools\ReadWikiPageTool;
use App\Services\Agent\Tools\SearchWikiTool;
use App\Services\Source\SourceCatalog;
use App\Services\Source\SourceLinkResolver;
use App\Services\Wiki\CitationValidator;
use App\Services\Wiki\SourceCitationCodec;
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
        private readonly SourceCatalog $catalog,
        private readonly SourceLinkResolver $sourceLinks,
        private readonly SourceRepository $sources,
        private readonly ProposalRepository $proposals,
        private readonly SourceCitationCodec $citations,
        private readonly CitationValidator $citationValidator,
        private readonly PromptRepository $prompts,
    ) {}

    public function visionAnalyst(): Agent
    {
        return $this->agent(
            name: 'vision-analyst',
            prompt: $this->prompts->get('vision-analyst'),
        );
    }

    public function sourceAnalyst(): Agent
    {
        return $this->agent(
            name: 'source-analyst',
            prompt: $this->prompts->get('source-analyst'),
        );
    }

    public function queryAgent(?QueryToolBudget $budget = null): Agent
    {
        $tools = $this->readTools($budget);

        return $this->agent(
            name: 'wiki-query',
            prompt: $this->prompts->get('wiki-query'),
            allowedTools: array_map(static fn (SdkTool $tool): string => $tool->name(), $tools),
            tools: $tools,
        );
    }

    public function answerAgent(): Agent
    {
        return $this->agent(
            name: 'wiki-answer-composer',
            prompt: $this->prompts->get('wiki-answer-composer'),
        );
    }

    public function semanticLintAgent(): Agent
    {
        $tools = $this->readTools();

        return $this->agent(
            name: 'wiki-semantic-lint',
            prompt: $this->prompts->get('wiki-semantic-lint'),
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
            prompt: $this->prompts->get('wiki-mapper'),
            allowedTools: $readNames,
        );
        $auditor = $this->agent(
            name: 'citation-auditor',
            prompt: $this->prompts->get('citation-auditor'),
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
            prompt: $this->prompts->get('wiki-orchestrator'),
            allowedTools: array_map(static fn (SdkTool $tool): string => $tool->name(), $tools),
            tools: $tools,
        );
    }

    /** @return list<SdkTool> */
    private function readTools(?QueryToolBudget $budget = null): array
    {
        return [
            new SearchWikiTool($this->search, $budget),
            new ReadWikiPageTool(
                $this->paths,
                $this->workspace,
                $this->sourceLinks,
                $this->citations,
                $this->citationValidator,
                $budget,
            ),
            new ReadSourceExcerptTool($this->catalog, $this->sources, $budget),
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
