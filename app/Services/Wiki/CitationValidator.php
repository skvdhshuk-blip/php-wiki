<?php

namespace App\Services\Wiki;

use App\Repositories\Source\SourceRepository;
use App\Services\Source\SourceCatalog;
use Symfony\Component\Yaml\Yaml;

class CitationValidator
{
    public function __construct(
        private readonly SourceRepository $sources,
        private readonly SourceCatalog $catalog,
        private readonly SourceCitationCodec $codec,
    ) {}

    /** @return list<string> */
    public function validatePage(string $path, string $content): array
    {
        $errors = $this->validateFrontmatter($path, $content);
        if ($path === 'AGENTS.md') {
            return $errors;
        }

        $citations = $this->codec->all($content);
        if ($this->codec->countMarkers($content) !== count($citations)) {
            $errors[] = '存在格式不完整的 source 引用。';
        }

        foreach ($citations as $citation) {
            $errors = array_merge(
                $errors,
                $this->validateSourceReference($citation->path, $citation->sha256, $citation->locator),
            );
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    public function validateSourceReference(string $path, string $sha256, string $locator): array
    {
        try {
            $sourcePath = $this->catalog->assertSourcePath($path);
        } catch (\InvalidArgumentException $exception) {
            return ["无效来源路径 {$path}：{$exception->getMessage()}"];
        }

        $source = $this->sources->findByPath($sourcePath);
        if ($source === null) {
            return ["来源未登记：{$sourcePath}"];
        }

        $errors = [];
        if (! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            $errors[] = "来源哈希格式无效：{$sourcePath}";
        } elseif (! is_string($source->sha256) || ! hash_equals($source->sha256, $sha256)) {
            $errors[] = "来源哈希已过期：{$sourcePath}";
        }

        $absolute = $this->catalog->absolute($sourcePath);
        $currentHash = is_file($absolute) ? hash_file('sha256', $absolute) : false;
        if ($currentHash === false || ! hash_equals($sha256, $currentHash)) {
            $errors[] = "来源文件已变化，引用必须重新扫描：{$sourcePath}";
        }
        if (! preg_match('/^(lines:[1-9]\d*-[1-9]\d*|page:[1-9]\d*|region:.+)$/u', $locator)) {
            $errors[] = "引用定位格式无效：{$sourcePath}|{$locator}";
        }
        if ($source->type === 'image' && ! str_starts_with($locator, 'region:')) {
            $errors[] = "图片引用必须包含 region：{$sourcePath}";
        }
        if ($source->type === 'pdf' && ! str_starts_with($locator, 'page:')) {
            $errors[] = "PDF 引用必须包含 page：{$sourcePath}";
        }
        if (in_array($source->type, ['markdown', 'text', 'html'], true)
            && ! str_starts_with($locator, 'lines:')) {
            $errors[] = "文本引用必须包含 lines：{$sourcePath}";
        }
        if (preg_match('/^lines:(\d+)-(\d+)$/', $locator, $lineMatch) === 1) {
            $start = (int) $lineMatch[1];
            $end = (int) $lineMatch[2];
            $lineCount = is_file($absolute) ? count(file($absolute) ?: []) : 0;
            if ($start > $end || $end > $lineCount) {
                $errors[] = "文本引用行号越界：{$sourcePath}|{$locator}";
            }
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    private function validateFrontmatter(string $path, string $content): array
    {
        if (! preg_match('/\A---\R(.*?)\R---\R/s', $content, $match)) {
            return ['页面缺少 YAML frontmatter。'];
        }

        try {
            $frontmatter = Yaml::parse($match[1]);
        } catch (\Throwable $exception) {
            return ['YAML frontmatter 无法解析：'.$exception->getMessage()];
        }

        if (! is_array($frontmatter)) {
            return ['YAML frontmatter 必须是对象。'];
        }

        $errors = [];
        foreach (['type', 'status', 'updated'] as $field) {
            if (! array_key_exists($field, $frontmatter)) {
                $errors[] = "frontmatter 缺少字段：{$field}";
            }
        }
        if (isset($frontmatter['source_ids']) && ! is_array($frontmatter['source_ids'])) {
            $errors[] = 'frontmatter source_ids 必须是数组。';
        }
        if (isset($frontmatter['source_ids']) && is_array($frontmatter['source_ids'])) {
            foreach ($frontmatter['source_ids'] as $sourceId) {
                if (! is_string($sourceId) || $this->sources->findByPath($sourceId) === null) {
                    $errors[] = 'frontmatter source_ids 包含未登记来源。';
                }
            }
        }
        if (isset($frontmatter['type'])) {
            $validType = $path === 'AGENTS.md'
                ? $frontmatter['type'] === 'schema/llm-wiki'
                : is_string($frontmatter['type']) && preg_match('/^wiki\/[a-z0-9][a-z0-9-]*$/', $frontmatter['type']) === 1;
            if (! $validType) {
                $errors[] = $path === 'AGENTS.md'
                    ? 'AGENTS.md frontmatter type 必须使用 schema/llm-wiki。'
                    : 'frontmatter type 必须使用 wiki/<kind>。';
            }
        }
        if (isset($frontmatter['status'])
            && ! in_array($frontmatter['status'], ['active', 'draft', 'permanent', 'archived'], true)) {
            $errors[] = 'frontmatter status 不在允许范围内。';
        }
        if (isset($frontmatter['updated'])
            && ! (is_int($frontmatter['updated']) && $frontmatter['updated'] > 0)
            && (! is_string($frontmatter['updated']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $frontmatter['updated']) !== 1)) {
            $errors[] = 'frontmatter updated 必须使用 YYYY-MM-DD。';
        }
        if (isset($frontmatter['confidence']) && ! in_array($frontmatter['confidence'], ['high', 'medium', 'low'], true)) {
            $errors[] = 'frontmatter confidence 不在允许范围内。';
        }

        return $errors;
    }
}
