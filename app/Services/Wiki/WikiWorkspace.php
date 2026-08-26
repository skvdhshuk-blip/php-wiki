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
            'wiki/index.md' => "---\ntype: wiki/index\nstatus: active\nupdated: ".now()->toDateString()."\n---\n\n# PHP Wiki\n\n这是知识库的内容索引。Agent 会在提案获批时维护这里。\n",
            'wiki/log.md' => "---\ntype: wiki/log\nstatus: active\nupdated: ".now()->toDateString()."\n---\n\n# Wiki 变更日志\n\n此文件只追加获批提案的记录。\n",
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
        $template = <<<'MARKDOWN'
---
type: schema/llm-wiki
status: active
updated: 2026-08-26
---

# PHP Wiki Agent Contract

## 权威分层

- Source Catalog：配置允许的本地来源目录。来源使用相对 Wiki 根目录的稳定路径，任何 Agent 和应用流程都不得修改。
- `wiki/`：Agent 维护、用户审批的 Markdown 知识库。
- `AGENTS.md`：Wiki Schema 与工作流规则；修改同样必须经过提案审批。

## 页面 Schema

每个 Wiki 页面必须包含 YAML frontmatter：

```yaml
---
type: wiki/topic
status: active
updated: 2026-08-26
source_ids: [GetNote导入/example.md]
confidence: high|medium|low
---
```

## 引用

- 文本：`[[source:GetNote导入/note.md|sha256:<64 hex>|lines:10-20]]`
- PDF：`[[source:raw/book.pdf|sha256:<64 hex>|page:12]]`
- 图片：`[[source:raw/image.png|sha256:<64 hex>|region:左上角图表]]`
- Wiki 页面：`[[wiki/concepts/example]]`

引用必须绑定当前来源哈希；无法确认的事实要标注为推断，不得伪造来源。

## 变更规则

- Agent 只能提交完整页面内容作为提案，不能直接落盘。
- 禁止硬删除；废弃页面移动到 `wiki/archive/`。
- `wiki/log.md` 只追加，`wiki/index.md` 面向主题组织，不按时间堆砌。
MARKDOWN;

        return $template."\n\n".$this->schemaContractSection()."\n";
    }

    public function schemaContractSection(): string
    {
        return <<<'MARKDOWN'
## 8. PHP Wiki 应用执行契约

- Source Catalog 由应用配置明确允许的本地目录组成；这些目录及其中的原始资料只读，不得由 Agent、审批应用或 Git 提交流程修改。
- `[[GetNote导入/...]]` 等旧式 Obsidian 来源链接只用于导航与发现，不构成事实引用。正式引用必须使用 `[[source:路径|sha256:哈希|lines:范围]]`、`page:` 或 `region:` 定位。
- Ingest 必须把待处理来源修订编译成可审阅的 Wiki ChangeSet；Agent 只能调用 `ProposeWikiPage` 记录完整页面提案，用户批准后应用才可原子落盘并创建仅包含受管文件的 Git commit。
- Query 先读 `wiki/index.md`，但事实回答只能使用当前哈希有效的正式 source 引用或成功的 Source Catalog 原文摘录；只有旧链接、无来源 Wiki 文本或过期引用时必须报告证据缺口。
- `wiki/index.md`、`wiki/log.md` 与 Wiki 页面链接使用原生 Obsidian `[[wiki/path]]` 语法；废弃页面只归档到 `wiki/archive/`，不硬删除。
MARKDOWN;
    }
}
