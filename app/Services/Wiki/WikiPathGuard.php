<?php

namespace App\Services\Wiki;

use InvalidArgumentException;
use RuntimeException;

class WikiPathGuard
{
    public function root(): string
    {
        $configured = (string) config('phpwiki.root');
        if ($configured === '' || ! str_starts_with($configured, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('PHP_WIKI_ROOT 必须是绝对路径。');
        }

        return rtrim($configured, DIRECTORY_SEPARATOR);
    }

    public function assertManagedPath(string $path): string
    {
        $path = $this->normalizeRelative($path);
        $managed = $path === 'AGENTS.md'
            || (str_starts_with($path, 'wiki/') && str_ends_with(strtolower($path), '.md'));

        if (! $managed) {
            throw new InvalidArgumentException('Agent 只能修改 AGENTS.md 或 wiki/ 下的 Markdown 文件。');
        }

        $this->assertNoSymlinkTraversal($path);

        return $path;
    }

    public function absolute(string $relative): string
    {
        $relative = $this->normalizeRelative($relative);

        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function normalizeRelative(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('路径包含非法空字节。');
        }

        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) {
            throw new InvalidArgumentException('路径必须是非空相对路径。');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('路径不能包含空段、. 或 ..。');
            }
        }

        return implode('/', $segments);
    }

    private function assertNoSymlinkTraversal(string $relative): void
    {
        $cursor = $this->root();
        foreach (explode('/', $relative) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($cursor)) {
                throw new InvalidArgumentException('路径不能穿过符号链接。');
            }
        }

        $nearest = $cursor;
        while (! file_exists($nearest)) {
            $parent = dirname($nearest);
            if ($parent === $nearest) {
                throw new InvalidArgumentException('无法解析目标路径。');
            }
            $nearest = $parent;
        }

        $this->assertContainedRealPath($nearest);
    }

    private function assertContainedRealPath(string $path): void
    {
        $rootReal = realpath($this->root());
        $pathReal = realpath($path);
        if ($rootReal === false || $pathReal === false) {
            throw new InvalidArgumentException('工作区或目标路径无法解析。');
        }

        if ($pathReal !== $rootReal && ! str_starts_with($pathReal, $rootReal.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('路径逃逸出 Wiki 工作区。');
        }
    }
}
