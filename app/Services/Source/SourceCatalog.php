<?php

namespace App\Services\Source;

use App\Services\Wiki\WikiPathGuard;
use InvalidArgumentException;
use RuntimeException;

class SourceCatalog
{
    public function __construct(private readonly WikiPathGuard $paths) {}

    /** @return array<string, string> logical root => absolute root */
    public function roots(): array
    {
        $configured = config('phpwiki.source_roots', ['raw']);
        if (! is_array($configured) || $configured === []) {
            throw new RuntimeException('PHP_WIKI_SOURCE_ROOTS 至少需要一个只读来源目录。');
        }

        $roots = [];
        foreach ($configured as $value) {
            if (! is_string($value)) {
                throw new RuntimeException('PHP_WIKI_SOURCE_ROOTS 只能包含相对目录。');
            }

            $root = $this->paths->normalizeRelative($value);
            if ($root === 'AGENTS.md' || $root === 'wiki' || str_starts_with($root, 'wiki/')) {
                throw new RuntimeException("来源目录不能覆盖应用管理区：{$root}");
            }
            if (isset($roots[$root])) {
                continue;
            }
            foreach (array_keys($roots) as $existing) {
                if (str_starts_with($root.'/', $existing.'/') || str_starts_with($existing.'/', $root.'/')) {
                    throw new RuntimeException("来源目录不能重叠：{$existing} 与 {$root}");
                }
            }

            $absolute = $this->paths->absolute($root);
            if (is_link($absolute)) {
                throw new RuntimeException("来源根目录不能是符号链接：{$root}");
            }
            $roots[$root] = $absolute;
        }

        return $roots;
    }

    public function assertSourcePath(string $path): string
    {
        $path = $this->paths->normalizeRelative($path);
        $root = $this->matchingRoot($path);
        if ($root === null) {
            throw new InvalidArgumentException('来源文件不在允许的 Source Catalog 目录中。');
        }

        $absolute = $this->paths->absolute($path);
        if (! is_file($absolute) || is_link($absolute)) {
            throw new InvalidArgumentException('来源文件不存在或是符号链接。');
        }

        $rootReal = realpath($this->roots()[$root]);
        $fileReal = realpath($absolute);
        if ($rootReal === false || $fileReal === false
            || ! str_starts_with($fileReal, $rootReal.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('来源文件逃逸出配置的来源目录。');
        }

        $cursor = $this->paths->root();
        foreach (explode('/', $path) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($cursor)) {
                throw new InvalidArgumentException('来源路径不能穿过符号链接。');
            }
        }

        return $path;
    }

    public function absolute(string $path): string
    {
        return $this->paths->absolute($this->assertSourcePath($path));
    }

    public function resolveReference(string $reference): ?string
    {
        $reference = rawurldecode(trim(explode('|', explode('#', $reference, 2)[0], 2)[0]));
        $candidates = [$reference];
        if (pathinfo($reference, PATHINFO_EXTENSION) === '') {
            $candidates[] = $reference.'.md';
        }

        foreach ($candidates as $candidate) {
            try {
                return $this->assertSourcePath($candidate);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }

    private function matchingRoot(string $path): ?string
    {
        foreach (array_keys($this->roots()) as $root) {
            if (str_starts_with($path, $root.'/')) {
                return $root;
            }
        }

        return null;
    }
}
