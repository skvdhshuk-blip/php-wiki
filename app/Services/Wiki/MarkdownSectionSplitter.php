<?php

namespace App\Services\Wiki;

/**
 * 按标题把 Markdown 页面切成小节。
 *
 * 整页作为单条检索记录时，长页面的 bm25 会被无关内容稀释，
 * 摘要也只能截取开头。按小节建索引让相关度与摘要都落在真正命中的段落上。
 */
class MarkdownSectionSplitter
{
    /**
     * @return list<array{heading: string, body: string}>
     */
    public function split(string $content): array
    {
        $lines = preg_split('/\R/u', $this->stripFrontmatter($content)) ?: [];

        $sections = [];
        $heading = '';
        $body = [];
        $fence = null;

        foreach ($lines as $line) {
            $fence = $this->trackFence($line, $fence);

            if ($fence === null && preg_match('/^\s{0,3}#{1,6}\s+(.*\S)\s*$/u', $line, $match) === 1) {
                $sections[] = ['heading' => $heading, 'body' => implode("\n", $body)];
                $heading = $match[1];
                $body = [];

                continue;
            }

            $body[] = $line;
        }

        $sections[] = ['heading' => $heading, 'body' => implode("\n", $body)];

        $sections = array_values(array_filter(
            $sections,
            static fn (array $section): bool => trim($section['heading'].$section['body']) !== '',
        ));

        return $sections === [] ? [['heading' => '', 'body' => '']] : $sections;
    }

    /**
     * 围栏代码块内部的 `#` 是内容而不是标题。
     */
    private function trackFence(string $line, ?string $fence): ?string
    {
        if (preg_match('/^\s{0,3}(`{3,}|~{3,})/u', $line, $match) !== 1) {
            return $fence;
        }

        $marker = $match[1];
        if ($fence === null) {
            return $marker;
        }

        return $marker[0] === $fence[0] && strlen($marker) >= strlen($fence) ? null : $fence;
    }

    private function stripFrontmatter(string $content): string
    {
        return preg_replace('/\A---\R.*?\R---\R/s', '', $content) ?? $content;
    }
}
