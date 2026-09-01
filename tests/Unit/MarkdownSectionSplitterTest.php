<?php

namespace Tests\Unit;

use App\Services\Wiki\MarkdownSectionSplitter;
use PHPUnit\Framework\TestCase;

class MarkdownSectionSplitterTest extends TestCase
{
    private MarkdownSectionSplitter $splitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->splitter = new MarkdownSectionSplitter;
    }

    public function test_splits_on_headings_and_keeps_the_preamble(): void
    {
        $sections = $this->splitter->split(
            "引言段落。\n\n# 一级标题\n\n正文甲。\n\n## 二级标题\n\n正文乙。\n"
        );

        $this->assertCount(3, $sections);
        $this->assertSame('', $sections[0]['heading']);
        $this->assertStringContainsString('引言段落', $sections[0]['body']);
        $this->assertSame('一级标题', $sections[1]['heading']);
        $this->assertStringContainsString('正文甲', $sections[1]['body']);
        $this->assertStringNotContainsString('正文乙', $sections[1]['body']);
        $this->assertSame('二级标题', $sections[2]['heading']);
    }

    public function test_strips_frontmatter(): void
    {
        $sections = $this->splitter->split("---\ntitle: 标题\n---\n# 正文标题\n\n内容。\n");

        $this->assertCount(1, $sections);
        $this->assertSame('正文标题', $sections[0]['heading']);
        $this->assertStringNotContainsString('title: 标题', $sections[0]['body']);
    }

    public function test_does_not_split_inside_fenced_code_blocks(): void
    {
        $sections = $this->splitter->split(
            "# 配置说明\n\n```sh\n# 这是注释而不是标题\necho hi\n```\n\n结束。\n"
        );

        $this->assertCount(1, $sections);
        $this->assertSame('配置说明', $sections[0]['heading']);
        $this->assertStringContainsString('这是注释而不是标题', $sections[0]['body']);
    }

    public function test_empty_page_still_yields_one_section(): void
    {
        $this->assertSame(
            [['heading' => '', 'body' => '']],
            $this->splitter->split("---\ntitle: 空页\n---\n"),
        );
    }
}
