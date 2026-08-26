<?php

namespace App\Services\Agent;

use App\Repositories\Agent\CoreAgentBenchmarkStateStore;
use App\Repositories\Source\SourceRepository;
use App\Services\Wiki\CitationValidator;
use App\Services\Wiki\WikiSearchService;
use App\Services\Wiki\WikiWorkspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CoreAgentBenchmarkWorkspace
{
    public function __construct(
        private readonly WikiWorkspace $workspace,
        private readonly SourceRepository $sources,
        private readonly WikiSearchService $search,
        private readonly CitationValidator $citations,
        private readonly CoreAgentBenchmarkStateStore $state,
    ) {}

    /**
     * Execute a benchmark against a disposable, repository-owned knowledge base.
     * All database writes and workspace files are rolled back afterwards.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public function within(callable $callback): mixed
    {
        $originalRoot = (string) config('phpwiki.root');
        $root = storage_path('framework/core-agent-benchmark-'.Str::uuid());

        return $this->state->rollbackOnly(function () use ($originalRoot, $root, $callback): mixed {
            try {
                config(['phpwiki.root' => $root]);
                $this->materialize($root);

                return $callback($root);
            } finally {
                config(['phpwiki.root' => $originalRoot]);
                $this->remove($root);
            }
        });
    }

    /** @return array{fixture_sha256: string, raw_files: int, wiki_files: int} */
    public function manifest(): array
    {
        $fixtureRoot = resource_path('core-agent/workspace');
        $files = collect(File::allFiles($fixtureRoot))
            ->sortBy(static fn (\SplFileInfo $file): string => $file->getRelativePathname())
            ->values();
        $hash = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($hash, str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())."\0");
            hash_update_file($hash, $file->getPathname());
        }

        return [
            'fixture_sha256' => hash_final($hash),
            'raw_files' => $files->filter(
                static fn (\SplFileInfo $file): bool => str_starts_with($file->getRelativePathname(), 'raw'.DIRECTORY_SEPARATOR),
            )->count(),
            'wiki_files' => $files->filter(
                static fn (\SplFileInfo $file): bool => str_starts_with($file->getRelativePathname(), 'wiki'.DIRECTORY_SEPARATOR),
            )->count(),
        ];
    }

    private function materialize(string $root): void
    {
        $this->workspace->initialize();
        $fixtureRoot = resource_path('core-agent/workspace');
        if (! is_dir($fixtureRoot)) {
            throw new \RuntimeException('Core Agent benchmark workspace fixture is missing.');
        }

        foreach (File::allFiles($fixtureRoot) as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
            if (str_ends_with($relative, '.base64')) {
                $relative = substr($relative, 0, -strlen('.base64'));
                $decoded = base64_decode(trim(File::get($file->getPathname())), true);
                if ($decoded === false) {
                    throw new \RuntimeException("Invalid base64 Core Agent fixture: {$relative}");
                }
                $destination = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                File::ensureDirectoryExists(dirname($destination));
                File::put($destination, $decoded);

                continue;
            }
            $destination = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            File::ensureDirectoryExists(dirname($destination));
            File::copy($file->getPathname(), $destination);
        }

        $hashes = [];
        foreach (File::files($root.'/raw') as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException('Core Agent fixture cannot contain symbolic links.');
            }
            $relative = 'raw/'.$file->getFilename();
            $sha256 = hash_file('sha256', $file->getPathname());
            if ($sha256 === false) {
                throw new \RuntimeException("Unable to hash Core Agent fixture source: {$relative}");
            }
            $hashes[$relative] = $sha256;
            $this->sources->recordScan(
                path: $relative,
                type: $file->getExtension() === 'png' ? 'image' : 'markdown',
                sha256: $sha256,
                size: $file->getSize(),
                mtime: $file->getMTime(),
            );
        }

        foreach ($this->workspace->markdownFiles() as $path) {
            $content = $this->renderHashes($this->workspace->read($path), $hashes);
            $this->workspace->atomicWrite($path, $content);
            $errors = $this->citations->validatePage($path, $content);
            if ($errors !== []) {
                throw new \RuntimeException("Invalid Core Agent fixture page {$path}: ".implode('; ', $errors));
            }
        }

        $this->search->rebuild();
    }

    /** @param array<string, string> $hashes */
    private function renderHashes(string $content, array $hashes): string
    {
        return preg_replace_callback(
            '/\{\{sha256:(raw\/[^}]+)}}/',
            static function (array $match) use ($hashes): string {
                $path = $match[1];
                if (! isset($hashes[$path])) {
                    throw new \RuntimeException("Unknown Core Agent fixture source placeholder: {$path}");
                }

                return $hashes[$path];
            },
            $content,
        ) ?? throw new \RuntimeException('Unable to render Core Agent fixture hashes.');
    }

    private function remove(string $root): void
    {
        $testingRoot = realpath(storage_path('framework'));
        $resolved = realpath($root);
        if ($testingRoot !== false
            && $resolved !== false
            && str_starts_with($resolved, $testingRoot.DIRECTORY_SEPARATOR.'core-agent-benchmark-')) {
            File::deleteDirectory($resolved);
        }
    }
}
