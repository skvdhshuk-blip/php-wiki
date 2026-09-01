<?php

namespace App\Services\Agent\Tools;

use App\Services\Agent\QueryToolBudget;
use App\Services\Wiki\WikiSearchService;

class SearchWikiTool extends WikiSdkTool
{
    public function __construct(
        private readonly WikiSearchService $search,
        private readonly ?QueryToolBudget $budget = null,
    ) {}

    public function name(): string
    {
        return 'SearchWiki';
    }

    public function description(): string
    {
        return 'Search the approved local Markdown wiki. Returns paths, titles, snippets, and source IDs.';
    }

    public function parameters(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Search terms', 'required' => true],
            'limit' => ['type' => 'integer', 'description' => 'Maximum results, 1-20'],
        ];
    }

    public function handle(array $input): string
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            throw new \InvalidArgumentException('query must be a non-empty string.');
        }

        $this->budget?->admitSearch($query);
        $results = $this->search->search($query, (int) ($input['limit'] ?? 12));
        $this->budget?->recordSearchResults($results);

        // 检索得分只用于应用侧排序与预算决策，不进入模型输入：
        // 它不是证据强度，交给模型只会被当成可引用的事实。
        $visible = array_map(
            static fn (array $result): array => array_diff_key($result, ['score' => null]),
            $results,
        );

        return json_encode(
            $visible,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<string, mixed> $input */
    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
