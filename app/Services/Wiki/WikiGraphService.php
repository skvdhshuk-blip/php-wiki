<?php

namespace App\Services\Wiki;

class WikiGraphService
{
    public function __construct(
        private readonly WikiWorkspace $workspace,
        private readonly WikiLinkParser $links,
    ) {}

    /** @return array{nodes: list<array{id: string, label: string}>, edges: list<array{from: string, to: string}>} */
    public function graph(): array
    {
        $nodes = [];
        $edges = [];
        foreach ($this->workspace->markdownFiles() as $path) {
            $nodes[] = ['id' => $path, 'label' => basename($path, '.md')];
            foreach ($this->links->targets($this->workspace->read($path)) as $target) {
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
            if ($this->links->contains($this->workspace->read($path), $target)) {
                $backlinks[] = $path;
            }
        }

        return $backlinks;
    }
}
