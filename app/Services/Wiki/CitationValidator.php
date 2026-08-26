<?php

namespace App\Services\Wiki;

use App\Repositories\Source\SourceRepository;
use Symfony\Component\Yaml\Yaml;

class CitationValidator
{
    public function __construct(
        private readonly SourceRepository $sources,
        private readonly WikiPathGuard $paths,
    ) {}

    /** @return list<string> */
    public function validatePage(string $path, string $content): array
    {
        $errors = [];
        if (! in_array($path, ['wiki/index.md', 'wiki/log.md'], true)) {
            $errors = array_merge($errors, $this->validateFrontmatter($content));
        }

        preg_match_all('/\[\[source:([^|\]]+)\|sha256:([a-f0-9]{64})\|([^\]]+)\]\]/i', $content, $matches, PREG_SET_ORDER);
        $rawCitationCount = substr_count($content, '[[source:');
        if ($rawCitationCount !== count($matches)) {
            $errors[] = '存在格式不完整的 source 引用。';
        }

        foreach ($matches as $match) {
            $errors = array_merge(
                $errors,
                $this->validateSourceReference($match[1], strtolower($match[2]), $match[3]),
            );
        }

        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    public function validateSourceReference(string $path, string $sha256, string $locator): array
    {
        try {
            $sourcePath = $this->paths->assertRawPath($path);
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

        $absolute = $this->paths->absolute($sourcePath);
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
    private function validateFrontmatter(string $content): array
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
        foreach (['id', 'title', 'type', 'status', 'created_at', 'updated_at', 'source_ids', 'confidence'] as $field) {
            if (! array_key_exists($field, $frontmatter)) {
                $errors[] = "frontmatter 缺少字段：{$field}";
            }
        }
        if (isset($frontmatter['source_ids']) && ! is_array($frontmatter['source_ids'])) {
            $errors[] = 'frontmatter source_ids 必须是数组。';
        }
        if (isset($frontmatter['type']) && ! in_array($frontmatter['type'], ['source', 'concept', 'entity', 'synthesis', 'question'], true)) {
            $errors[] = 'frontmatter type 不在允许范围内。';
        }
        if (isset($frontmatter['confidence']) && ! in_array($frontmatter['confidence'], ['high', 'medium', 'low'], true)) {
            $errors[] = 'frontmatter confidence 不在允许范围内。';
        }

        return $errors;
    }
}
