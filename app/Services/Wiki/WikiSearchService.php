<?php

namespace App\Services\Wiki;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

class WikiSearchService
{
    public function __construct(private readonly WikiWorkspace $workspace) {}

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
                DB::table('wiki_search_entries')->insert([
                    'path' => $path,
                    'title' => $this->title($content, $path),
                    'content' => $content,
                    'source_ids' => implode(',', $this->sourceIds($content)),
                ]);
                $count++;
            }

            return $count;
        });
    }

    /** @return list<array{path: string, title: string, snippet: string, source_ids: string}> */
    public function search(string $query, int $limit = 12): array
    {
        if (DB::getDriverName() !== 'sqlite') {
            return [];
        }

        $match = $this->ftsQuery($query);
        if ($match === '') {
            return [];
        }

        $rows = DB::select(
            "SELECT path, title, snippet(wiki_search_entries, 2, '', '', ' … ', 32) AS snippet, source_ids
             FROM wiki_search_entries
             WHERE wiki_search_entries MATCH ?
             ORDER BY bm25(wiki_search_entries)
             LIMIT ?",
            [$match, max(1, min($limit, 50))],
        );

        return array_values(array_map(static function (object $row): array {
            $values = get_object_vars($row);

            return [
                'path' => is_string($values['path'] ?? null) ? $values['path'] : '',
                'title' => is_string($values['title'] ?? null) ? $values['title'] : '',
                'snippet' => is_string($values['snippet'] ?? null) ? $values['snippet'] : '',
                'source_ids' => is_string($values['source_ids'] ?? null) ? $values['source_ids'] : '',
            ];
        }, $rows));
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
        $tokens = preg_split('/[^\p{L}\p{N}_-]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_slice(array_unique($tokens), 0, 12);

        return implode(' OR ', array_map(
            static fn (string $token): string => '"'.str_replace('"', '""', $token).'"',
            $tokens,
        ));
    }
}
