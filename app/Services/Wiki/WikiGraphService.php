<?php

namespace App\Services\Wiki;

class WikiGraphService
{
    public function __construct(private readonly WikiWorkspace $workspace) {}

    /** @return array{nodes: list<array{id: string, label: string}>, edges: list<array{from: string, to: string}>} */
    public function graph(): array
    {
        $nodes = [];
        $edges = [];
        foreach ($this->workspace->markdownFiles() as $path) {
            $nodes[] = ['id' => $path, 'label' => basename($path, '.md')];
            preg_match_all('/\[\[page:([^\]]+)\]\]/', $this->workspace->read($path), $matches);
            foreach ($matches[1] as $target) {
                $edges[] = ['from' => $path, 'to' => $target];
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /** @return list<string> */
    public function backlinks(string $target): array
    {
        $backlinks = [];
        foreach ($this->workspace->markdownFiles() as $path) {
            if (str_contains($this->workspace->read($path), "[[page:{$target}]]")) {
                $backlinks[] = $path;
            }
        }

        return $backlinks;
    }
}
