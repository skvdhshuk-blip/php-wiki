<?php

namespace App\Services\Wiki;

use RuntimeException;

class WikiWorkspace
{
    private const WIKI_DIRECTORIES = [
        'raw',
        'wiki',
        'wiki/sources',
        'wiki/concepts',
        'wiki/entities',
        'wiki/syntheses',
        'wiki/questions',
        'wiki/archive',
    ];

    public function __construct(private readonly WikiPathGuard $paths) {}

    /** @return list<string> newly created managed files */
    public function initialize(): array
    {
        $root = $this->paths->root();
        if (! is_dir($root) && ! mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new RuntimeException("无法创建 Wiki 工作区：{$root}");
        }

        foreach (self::WIKI_DIRECTORIES as $directory) {
            $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);
            if (! is_dir($absolute) && ! mkdir($absolute, 0755, true) && ! is_dir($absolute)) {
                throw new RuntimeException("无法创建目录：{$directory}");
            }
        }

        $created = [];
        $templates = [
            'AGENTS.md' => $this->schemaTemplate(),
            'wiki/index.md' => "# PHP Wiki\n\n这是知识库的内容索引。Agent 会在提案获批时维护这里。\n",
            'wiki/log.md' => "# Wiki 变更日志\n\n此文件只追加获批提案的记录。\n",
        ];

        foreach ($templates as $path => $content) {
            $absolute = $this->paths->absolute($path);
            if (! file_exists($absolute)) {
                $this->atomicWrite($path, $content);
                $created[] = $path;
            }
        }

        return $created;
    }

    public function read(string $path): string
    {
        $absolute = $this->paths->absolute($path);
        $content = @file_get_contents($absolute);
        if ($content === false) {
            throw new RuntimeException("无法读取文件：{$path}");
        }

        return $content;
    }

    public function exists(string $path): bool
    {
        return is_file($this->paths->absolute($path));
    }

    public function sha256(string $path): ?string
    {
        $absolute = $this->paths->absolute($path);

        if (! is_file($absolute)) {
            return null;
        }

        $hash = hash_file('sha256', $absolute);

        return $hash === false ? null : $hash;
    }

    public function atomicWrite(string $path, string $content): void
    {
        $path = $this->paths->assertManagedPath($path);
        $absolute = $this->paths->absolute($path);
        $directory = dirname($absolute);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("无法创建目录：{$directory}");
        }

        $temporary = tempnam($directory, '.php-wiki-');
        if ($temporary === false) {
            throw new RuntimeException("无法创建临时文件：{$path}");
        }

        try {
            if (file_put_contents($temporary, $content, LOCK_EX) === false) {
                throw new RuntimeException("无法写入临时文件：{$path}");
            }
            if (! rename($temporary, $absolute)) {
                throw new RuntimeException("无法原子替换文件：{$path}");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function remove(string $path): void
    {
        $path = $this->paths->assertManagedPath($path);
        $absolute = $this->paths->absolute($path);
        if (is_file($absolute) && ! unlink($absolute)) {
            throw new RuntimeException("无法移除 Wiki 文件：{$path}");
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withApplyLock(callable $callback): mixed
    {
        $lockPath = $this->paths->root().'/.git/php-wiki-apply.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('无法获取 Wiki 工作区写锁。');
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<string> */
    public function markdownFiles(): array
    {
        $root = $this->paths->absolute('wiki/index.md');
        $wikiRoot = dirname($root);
        if (! is_dir($wikiRoot)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($wikiRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink() && strtolower($file->getExtension()) === 'md') {
                $files[] = 'wiki/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($wikiRoot) + 1));
            }
        }

        sort($files);

        return $files;
    }

    private function schemaTemplate(): string
    {
        return <<<'MARKDOWN'
# PHP Wiki Agent Contract

## 权威分层

- `raw/`：用户拥有的原始资料，任何 Agent 和应用流程都不得修改。
- `wiki/`：Agent 维护、用户审批的 Markdown 知识库。
- `AGENTS.md`：Wiki Schema 与工作流规则；修改同样必须经过提案审批。

## 页面 Schema

除 `wiki/index.md` 与 `wiki/log.md` 外，每个页面必须包含 YAML frontmatter：

```yaml
---
id: stable-kebab-id
title: 页面标题
type: source|concept|entity|synthesis|question
status: active|draft|archived
created_at: 2026-08-26
updated_at: 2026-08-26
source_ids: [raw/example.pdf]
confidence: high|medium|low
---
```

## 引用

- 文本：`[[source:raw/note.md|sha256:<64 hex>|lines:10-20]]`
- PDF：`[[source:raw/book.pdf|sha256:<64 hex>|page:12]]`
- 图片：`[[source:raw/image.png|sha256:<64 hex>|region:左上角图表]]`
- Wiki 页面：`[[page:wiki/concepts/example.md]]`

引用必须绑定当前来源哈希；无法确认的事实要标注为推断，不得伪造来源。

## 变更规则

- Agent 只能提交完整页面内容作为提案，不能直接落盘。
- 禁止硬删除；废弃页面移动到 `wiki/archive/`。
- `wiki/log.md` 只追加，`wiki/index.md` 面向主题组织，不按时间堆砌。
MARKDOWN;
    }
}
