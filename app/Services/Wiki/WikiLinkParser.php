<?php

namespace App\Services\Wiki;

class WikiLinkParser
{
    /** @return list<string> */
    public function targets(string $content): array
    {
        preg_match_all('/\[\[(wiki\/[^\]|#]+)(?:#[^\]|]+)?(?:\|[^\]]+)?\]\]/u', $content, $matches);

        $targets = [];
        foreach ($matches[1] as $target) {
            $target = trim($target);
            if (! str_ends_with(strtolower($target), '.md')) {
                $target .= '.md';
            }
            $targets[] = $target;
        }

        return array_values(array_unique($targets));
    }

    public function link(string $path): string
    {
        return '[['.preg_replace('/\.md$/i', '', $path).']]';
    }

    public function contains(string $content, string $path): bool
    {
        return in_array($path, $this->targets($content), true);
    }
}
