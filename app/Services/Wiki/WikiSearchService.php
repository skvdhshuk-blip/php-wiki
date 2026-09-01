<?php

namespace App\Services\Wiki;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

class WikiSearchService
{
    public function __construct(
        private readonly WikiWorkspace $workspace,
        private readonly TextTokenizer $tokenizer,
        private readonly MarkdownSectionSplitter $sections,
        private readonly AnchorInformationScorer $anchors,
        private readonly QueryVariantGenerator $variants,
    ) {}

    public function rebuild(): int
    {
        if (DB::getDriverName() !== 'sqlite') {
            return 0;
        }

        return DB::transaction(function (): int {
            DB::table('wiki_search_entries')->delete();
            $count = 0;

            foreach ($this->workspace->markdownFiles() as $path) {
                $content = $this->workspace->read($path);
                $title = $this->title($content, $path);
                $sourceIds = implode(',', $this->sourceIds($content));

                foreach ($this->sections->split($content) as $index => $section) {
                    DB::table('wiki_search_entries')->insert([
                        'path' => $path,
                        'anchor' => 's'.$index,
                        'title' => $title,
                        'heading' => $section['heading'],
                        'content' => $section['body'],
                        'tokens' => $this->tokenizer->indexText(
                            $title."\n".$section['heading']."\n".$section['body'],
                        ),
                        'source_ids' => $sourceIds,
                    ]);
                }
                $count++;
            }

            return $count;
        });
    }

    /** bm25 与锚点覆盖度的融合权重；bm25 负责召回，覆盖度负责区分度。 */
    private const LEXICAL_WEIGHT = 0.4;

    private const COVERAGE_WEIGHT = 0.6;

    /**
     * @return list<array{
     *     path: string, title: string, heading: string,
     *     snippet: string, source_ids: string, score: float
     * }>
     */
    public function search(string $query, int $limit = 12): array
    {
        $results = $this->runSearch($query, $limit);
        if ($results !== []) {
            return $results;
        }

        // 原查询完全没有召回时，才用库里已有的标题纠正一次错字。
        $variants = $this->variants->variants($query, $this->headings());
        if (count($variants['automatic']) !== 1 || $variants['ambiguous'] !== []) {
            return $results;
        }

        return $this->runSearch($variants['automatic'][0], $limit);
    }

    /**
     * 库里真实存在的标题词汇，作为纠错的唯一候选来源。
     *
     * @return list<string>
     */
    private function headings(): array
    {
        if (DB::getDriverName() !== 'sqlite') {
            return [];
        }

        $rows = DB::select(
            'SELECT DISTINCT title, heading FROM wiki_search_entries LIMIT 2000',
        );

        $phrases = [];
        foreach ($rows as $row) {
            $values = get_object_vars($row);
            foreach (['title', 'heading'] as $key) {
                $phrase = is_string($values[$key] ?? null) ? trim($values[$key]) : '';
                if ($phrase !== '') {
                    $phrases[$phrase] = true;
                }
            }
        }

        return array_keys($phrases);
    }

    /**
     * @return list<array{
     *     path: string, title: string, heading: string,
     *     snippet: string, source_ids: string, score: float
     * }>
     */
    private function runSearch(string $query, int $limit): array
    {
        if (DB::getDriverName() !== 'sqlite') {
            return [];
        }

        $match = $this->ftsQuery($query);
        if ($match === '') {
            return [];
        }

        $limit = max(1, min($limit, 50));

        // 索引以小节为单位，但候选预算以页面计数：先选出每页 bm25 最好的
        // 小节，再按锚点覆盖度重排，避免长页面在 SQL LIMIT 前占满名额。
        $rows = DB::select(
            "WITH matches AS MATERIALIZED (
                 SELECT path, title, heading,
                        snippet(wiki_search_entries, 4, '', '', ' … ', 32) AS snippet,
                        content, source_ids,
                        bm25(wiki_search_entries) AS lexical
                 FROM wiki_search_entries
                 WHERE wiki_search_entries MATCH ?
             ), ranked AS (
                 SELECT *, ROW_NUMBER() OVER (PARTITION BY path ORDER BY lexical) AS page_rank
                 FROM matches
             )
             SELECT path, title, heading, snippet, content, source_ids, lexical
             FROM ranked
             WHERE page_rank = 1
             ORDER BY lexical
             LIMIT ?",
            [$match, min(200, $limit * 5)],
        );

        $candidates = array_map(
            static fn (object $row): array => get_object_vars($row),
            $rows,
        );
        if ($candidates === []) {
            return [];
        }

        $coverage = $this->anchors->score($query, array_map(
            static fn (array $row): string => implode("\n", [
                is_string($row['title'] ?? null) ? $row['title'] : '',
                is_string($row['heading'] ?? null) ? $row['heading'] : '',
                is_string($row['content'] ?? null) ? $row['content'] : '',
            ]),
            $candidates,
        ));
        $lexical = $this->normalizeLexicalScores(array_map(
            static fn (array $row): float => is_numeric($row['lexical'] ?? null) ? (float) $row['lexical'] : 0.0,
            $candidates,
        ));

        $scored = [];
        foreach ($candidates as $index => $row) {
            $scored[$index] = self::LEXICAL_WEIGHT * $lexical[$index]
                + self::COVERAGE_WEIGHT * $coverage[$index];
        }
        arsort($scored);

        $results = [];
        foreach (array_keys($scored) as $index) {
            $row = $candidates[$index];
            $path = is_string($row['path'] ?? null) ? $row['path'] : '';
            if ($path === '' || isset($results[$path])) {
                continue;
            }

            $results[$path] = [
                'path' => $path,
                'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
                'heading' => is_string($row['heading'] ?? null) ? $row['heading'] : '',
                'snippet' => is_string($row['snippet'] ?? null) ? $row['snippet'] : '',
                'source_ids' => is_string($row['source_ids'] ?? null) ? $row['source_ids'] : '',
                'score' => round($scored[$index], 4),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return array_values($results);
    }

    /**
     * FTS5 的 bm25 越负越相关，这里映射成候选集内的 [0,1] 相对分。
     *
     * @param  array<array-key, float>  $values
     * @return array<array-key, float>
     */
    private function normalizeLexicalScores(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $best = min($values);
        $worst = max($values);
        if ($worst - $best <= 0.0) {
            return array_map(static fn (): float => 1.0, $values);
        }

        return array_map(
            static fn (float $value): float => ($worst - $value) / ($worst - $best),
            $values,
        );
    }

    /** @return list<string> */
    private function sourceIds(string $content): array
    {
        if (! preg_match('/\A---\R(.*?)\R---\R/s', $content, $match)) {
            return [];
        }

        try {
            $frontmatter = Yaml::parse($match[1]);
        } catch (\Throwable) {
            return [];
        }

        $ids = is_array($frontmatter) ? ($frontmatter['source_ids'] ?? []) : [];

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    private function title(string $content, string $path): string
    {
        if (preg_match('/\A---\R(.*?)\R---\R/s', $content, $match)) {
            try {
                $frontmatter = Yaml::parse($match[1]);
                if (is_array($frontmatter) && is_string($frontmatter['title'] ?? null)) {
                    return $frontmatter['title'];
                }
            } catch (\Throwable) {
                // Fall through to the first heading.
            }
        }

        if (preg_match('/^#\s+(.+)$/m', $content, $match)) {
            return trim($match[1]);
        }

        return basename($path, '.md');
    }

    private function ftsQuery(string $query): string
    {
        $tokens = $this->tokenizer->queryTokens($query, 24);

        return implode(' OR ', array_map(
            static fn (string $token): string => '"'.str_replace('"', '""', $token).'"',
            $tokens,
        ));
    }
}
