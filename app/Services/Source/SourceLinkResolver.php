<?php

namespace App\Services\Source;

use App\Repositories\Source\SourceRepository;

class SourceLinkResolver
{
    public function __construct(
        private readonly SourceCatalog $catalog,
        private readonly SourceRepository $sources,
    ) {}

    /** @return list<array{path: string, sha256: string, type: string}> */
    public function candidates(string $content): array
    {
        preg_match_all('/\[\[([^\]]+)\]\]/u', $content, $matches);
        $candidates = [];
        foreach ($matches[1] as $reference) {
            $path = $this->catalog->resolveReference($reference);
            if ($path === null || isset($candidates[$path])) {
                continue;
            }
            $source = $this->sources->findByPath($path);
            if ($source === null || ! is_string($source->sha256)) {
                continue;
            }
            $candidates[$path] = [
                'path' => $path,
                'sha256' => $source->sha256,
                'type' => $source->type,
            ];
        }

        return array_values($candidates);
    }
}
