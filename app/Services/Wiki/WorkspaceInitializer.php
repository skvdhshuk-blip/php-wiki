<?php

namespace App\Services\Wiki;

class WorkspaceInitializer
{
    public function __construct(
        private readonly WikiWorkspace $workspace,
        private readonly GitWorkspaceService $git,
        private readonly WikiSearchService $search,
    ) {}

    /** @return array{created: list<string>, commit: string|null} */
    public function initialize(): array
    {
        $created = $this->workspace->initialize();
        $this->git->ensureRepository();

        $commit = null;
        if ($created !== []) {
            $commit = $this->git->commitPaths($created, 'chore: initialize PHP Wiki workspace');
        }

        $this->search->rebuild();

        return ['created' => $created, 'commit' => $commit];
    }
}
