<?php

namespace App\Services\Wiki;

use RuntimeException;
use Symfony\Component\Process\Process;

class GitWorkspaceService
{
    public function __construct(private readonly WikiPathGuard $paths) {}

    public function ensureRepository(): void
    {
        $root = $this->paths->root();
        if (! is_dir($root.DIRECTORY_SEPARATOR.'.git')) {
            $this->run(['git', 'init']);
        }

        if (! $this->succeeds(['git', 'config', '--get', 'user.name'])) {
            $this->run(['git', 'config', 'user.name', 'PHP Wiki']);
        }
        if (! $this->succeeds(['git', 'config', '--get', 'user.email'])) {
            $this->run(['git', 'config', 'user.email', 'php-wiki@localhost']);
        }
    }

    /** @param list<string> $paths */
    public function commitPaths(array $paths, string $message): string
    {
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            throw new RuntimeException('没有可提交的 Wiki 路径。');
        }

        $this->run(['git', 'add', '--', ...$paths]);
        $status = $this->run(['git', 'status', '--porcelain=v1', '--', ...$paths])->getOutput();
        if (trim($status) !== '') {
            $this->run(['git', 'commit', '--no-verify', '--only', '-m', $message, '--', ...$paths]);
        }

        return $this->head() ?? throw new RuntimeException('Git 提交后无法读取 HEAD。');
    }

    public function head(): ?string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], $this->paths->root());
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    public function findCommitByMessage(string $message): ?string
    {
        $process = new Process([
            'git',
            'log',
            '--all',
            '--format=%H%x09%s',
            '--fixed-strings',
            '--grep='.$message,
        ], $this->paths->root());
        $process->setTimeout(60);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            [$hash, $subject] = array_pad(explode("\t", $line, 2), 2, null);
            if (is_string($hash) && is_string($subject) && $subject === $message) {
                return $hash;
            }
        }

        return null;
    }

    public function containsCommit(string $commitHash): bool
    {
        return $this->succeeds(['git', 'merge-base', '--is-ancestor', $commitHash, 'HEAD']);
    }

    /** @param list<string> $paths */
    public function rewindLastCommit(string $expectedHead, string $parent, array $paths): void
    {
        if ($this->head() !== $expectedHead) {
            throw new RuntimeException('Git HEAD 已变化，拒绝自动回退。');
        }

        $this->run(['git', 'reset', '--soft', $parent]);
        $this->run(['git', 'reset', '--mixed', $parent, '--', ...$paths]);
    }

    /** @param list<string> $paths */
    public function unstagePaths(string $parent, array $paths): void
    {
        $this->run(['git', 'reset', '--mixed', $parent, '--', ...$paths]);
    }

    /** @param list<string> $command */
    private function succeeds(array $command): bool
    {
        $process = new Process($command, $this->paths->root());
        $process->run();

        return $process->isSuccessful();
    }

    /** @param list<string> $command */
    private function run(array $command): Process
    {
        $process = new Process($command, $this->paths->root());
        $process->setTimeout(60);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process;
    }
}
