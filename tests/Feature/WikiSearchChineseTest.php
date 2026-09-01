<?php

namespace Tests\Feature;

use App\Services\Wiki\TextTokenizer;
use App\Services\Wiki\WikiSearchService;
use App\Services\Wiki\WikiWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class WikiSearchChineseTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
        app(WikiWorkspace::class)->initialize();
    }

    protected function tearDown(): void
    {
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_chinese_question_recalls_the_matching_page(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite(
            'wiki/concepts/remote-work.md',
            "# 远程办公申请\n\n远程办公需要提前三天提交申请单。\n",
        );
        $workspace->atomicWrite(
            'wiki/concepts/expense.md',
            "# 报销流程\n\n报销单据需要在次月十日之前提交。\n",
        );

        $search = app(WikiSearchService::class);
        $search->rebuild();

        $results = $search->search('远程办公申请需要提前多少天提交');

        $this->assertNotSame([], $results, '中文查询必须能够召回内容。');
        $this->assertSame('wiki/concepts/remote-work.md', $results[0]['path']);
    }

    public function test_chinese_query_does_not_require_an_exact_sentence_match(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite(
            'wiki/concepts/backup.md',
            "# 备份策略\n\n数据库每天凌晨执行一次全量备份。\n",
        );

        $search = app(WikiSearchService::class);
        $search->rebuild();

        // 查询词与正文没有任何一段完全相同的连续汉字串，只共享局部片段。
        $results = $search->search('全量备份');

        $this->assertSame('wiki/concepts/backup.md', $results[0]['path'] ?? null);
    }

    public function test_latin_search_still_works(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite('wiki/concepts/alpha.md', "# Alpha\n\nDurable old index token.\n");

        $search = app(WikiSearchService::class);
        $search->rebuild();

        $this->assertSame('wiki/concepts/alpha.md', $search->search('Durable')[0]['path']);
    }

    public function test_tokenizer_splits_han_runs_into_bigrams_and_keeps_latin_words(): void
    {
        $tokenizer = app(TextTokenizer::class);

        $this->assertSame('远程 程办 办公 vpn', $tokenizer->indexText('远程办公 VPN'));
        $this->assertSame(['远程', '程办', '办公', 'vpn'], $tokenizer->queryTokens('远程办公 VPN'));
    }

    public function test_tokenizer_drops_stop_words_on_the_query_side_only(): void
    {
        $tokenizer = app(TextTokenizer::class);

        $this->assertContains('什么', explode(' ', $tokenizer->indexText('什么')));
        $this->assertNotContains('什么', $tokenizer->queryTokens('什么'));
    }

    public function test_matching_section_drives_the_snippet_and_heading(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite(
            'wiki/concepts/handbook.md',
            "# 员工手册\n\n## 请假流程\n\n请假需要提前两天在系统里提交。\n\n"
            ."## 报销流程\n\n报销单据需要在次月十日之前提交。\n",
        );

        $search = app(WikiSearchService::class);
        $search->rebuild();

        $results = $search->search('报销单据什么时候提交');

        $this->assertSame('wiki/concepts/handbook.md', $results[0]['path']);
        $this->assertSame('员工手册', $results[0]['title']);
        $this->assertSame('报销流程', $results[0]['heading']);
        $this->assertStringContainsString('次月十日', $results[0]['snippet']);
        $this->assertStringNotContainsString('请假', $results[0]['snippet']);
    }

    public function test_one_page_occupies_a_single_result_slot(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite(
            'wiki/concepts/multi.md',
            "# 多节页面\n\n## 甲节\n\n数据备份策略说明。\n\n## 乙节\n\n数据备份保留周期说明。\n",
        );

        $search = app(WikiSearchService::class);
        $search->rebuild();

        $paths = array_column($search->search('数据备份'), 'path');

        $this->assertSame($paths, array_unique($paths), '同一页面不应占用多个结果位。');
    }

    public function test_section_heavy_page_cannot_consume_other_page_result_slots(): void
    {
        $workspace = app(WikiWorkspace::class);
        $crowded = "# Crowded page\n\n";
        for ($index = 0; $index < 65; $index++) {
            $crowded .= "## Section {$index}\n\nneedle\n\n";
        }
        $workspace->atomicWrite('wiki/concepts/crowded.md', $crowded);
        for ($index = 0; $index < 15; $index++) {
            $workspace->atomicWrite(
                sprintf('wiki/concepts/other-%02d.md', $index),
                "# Other {$index}\n\nneedle\n",
            );
        }

        $search = app(WikiSearchService::class);
        $search->rebuild();
        $paths = array_column($search->search('needle', 12), 'path');

        $this->assertCount(12, $paths);
        $this->assertCount(12, array_unique($paths));
    }

    public function test_a_transposed_query_is_rescued_by_the_existing_headings(): void
    {
        // 中段颠倒会打断查询的全部 bigram，这才是纠错真正需要救的场景。
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite(
            'wiki/concepts/remote.md',
            "# 远程办公\n\n申请需要提前三天提交。\n",
        );

        $search = app(WikiSearchService::class);
        $search->rebuild();

        $this->assertSame('wiki/concepts/remote.md', $search->search('远办程公')[0]['path'] ?? null);
    }

    public function test_an_unrelated_query_is_not_rescued_into_a_false_match(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite('wiki/concepts/remote.md', "# 远程办公\n\n申请需要提前三天提交。\n");

        $search = app(WikiSearchService::class);
        $search->rebuild();

        $this->assertSame([], $search->search('数据库分区裁剪'));
    }

    public function test_a_query_that_already_matches_is_never_rewritten(): void
    {
        $workspace = app(WikiWorkspace::class);
        $workspace->atomicWrite('wiki/concepts/a.md', "# 数据备份\n\n每天凌晨执行全量备份。\n");

        $search = app(WikiSearchService::class);
        $search->rebuild();

        $this->assertSame('wiki/concepts/a.md', $search->search('数据备份')[0]['path']);
    }
}
