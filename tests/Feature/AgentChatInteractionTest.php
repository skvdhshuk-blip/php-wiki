<?php

namespace Tests\Feature;

use App\Livewire\AgentChatPage;
use App\Livewire\AgentRunActivity;
use App\Models\User;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Chat\ChatRepository;
use App\Services\Agent\AgentAnswerPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentChatInteractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_composer_declares_the_fixed_local_knowledge_scope(): void
    {
        Livewire::test(AgentChatPage::class)
            ->assertSee('本地 Wiki')
            ->assertSee('从 index.md 开始')
            ->assertSee('不使用 Web');
    }

    public function test_verified_answer_exposes_inline_evidence_source_panel_and_contextual_follow_ups(): void
    {
        $chats = app(ChatRepository::class);
        $runs = app(AgentRunRepository::class);
        $thread = $chats->createThread();
        $run = $runs->createQueued('query', '职场边界是什么？', threadId: $thread->id);
        $chats->addUserMessage($thread, '职场边界是什么？', $run);
        $runs->event($run, 'query_scoped', ['mode' => 'lookup', 'reason' => '单一事实']);
        $runs->event($run, 'answer_completed', [
            'answer_type' => 'answer',
            'citation_count' => 1,
            'sections' => [['heading' => '结论', 'evidence_ids' => ['E1']]],
        ]);
        $message = $chats->addAssistantMessage($run, "## 结论\n\n保持清晰边界。 [^E1]\n\n---\n\n### 来源\n\n[^E1]: `raw/career.md` · `lines:2-2` — 保持清晰边界。", [[
            'evidence_id' => 'E1',
            'raw_path' => 'raw/career.md',
            'wiki_path' => 'wiki/concepts/career.md',
            'locator' => 'lines:2-2',
            'quote' => '保持清晰边界是职场生存的重要原则。',
        ]]);
        $this->assertNotNull($message);
        $answer = app(AgentAnswerPresenter::class)->present($message->load('run.events'));
        $this->assertSame('raw/career.md', $answer['citations'][0]['source_path']);
        $this->assertSame('lines:2-2', $answer['citations'][0]['locator']);
        $this->assertSame('保持清晰边界是职场生存的重要原则。', $answer['citations'][0]['quote']);

        Livewire::test(AgentChatPage::class)
            ->assertSee('已核验回答')
            ->assertSeeHtml('href="#evidence-E1"')
            ->assertSee('来源 1')
            ->assertSee('证据来源')
            ->assertSee('基于这些来源，还有哪些相反证据？')
            ->call('useSuggestion', '基于这些来源，还有哪些相反证据？')
            ->assertSet('question', '基于这些来源，还有哪些相反证据？')
            ->assertDispatched('focus-agent-composer');
    }

    public function test_compact_chat_activity_uses_human_phases_instead_of_raw_tool_diagnostics(): void
    {
        $runs = app(AgentRunRepository::class);
        $run = $runs->createQueued('query', 'question');
        $runs->start($run);
        $runs->event($run, 'query_scoped', ['mode' => 'lookup', 'reason' => '单一事实']);
        $runs->event($run, 'retrieval_started', ['max_searches' => 2, 'max_reads' => 4]);
        $runs->event($run, 'tool_started', [
            'name' => 'ReadWikiPage',
            'input' => ['path' => 'wiki/index.md'],
        ]);
        $runs->event($run, 'tool_completed', [
            'name' => 'ReadWikiPage',
            'is_error' => false,
            'duration_ms' => 7,
            'output_preview' => '# Index',
        ]);

        Livewire::test(AgentRunActivity::class, ['runId' => $run->id, 'compact' => true])
            ->assertSee('正在检索 Wiki')
            ->assertSee('读取 Wiki 页面')
            ->assertSee('wiki/index.md')
            ->assertDontSee('ReadWikiPage')
            ->assertDontSee('输出预览')
            ->assertDontSee('# Index');
    }

    public function test_answer_cards_make_insufficient_clarification_conflict_and_cancel_states_explicit(): void
    {
        $chats = app(ChatRepository::class);
        $runs = app(AgentRunRepository::class);
        $thread = $chats->createThread();

        $insufficient = $runs->createQueued('query', '火星办公室在哪？', threadId: $thread->id);
        $chats->addUserMessage($thread, '火星办公室在哪？', $insufficient);
        $runs->event($insufficient, 'answer_completed', ['answer_type' => 'insufficient_evidence']);
        $chats->addAssistantMessage($insufficient, "当前知识库证据不足，无法可靠回答。\n\n没有找到相关资料。", []);

        $clarification = $runs->createQueued('query', '它什么时候开始？', threadId: $thread->id);
        $chats->addUserMessage($thread, '它什么时候开始？', $clarification);
        $runs->event($clarification, 'answer_completed', ['answer_type' => 'clarification']);
        $chats->addAssistantMessage($clarification, "需要先确认一个问题：\n\n你指的是哪项政策？", []);

        $conflict = $runs->createQueued('query', '新旧规定一致吗？', threadId: $thread->id);
        $chats->addUserMessage($thread, '新旧规定一致吗？', $conflict);
        $runs->event($conflict, 'coverage_updated', [
            'coverage' => ['Q1' => 'conflict'],
            'gaps' => [],
            'conflicts' => ['新旧规定的生效时间不同'],
            'warnings' => [],
        ]);
        $runs->event($conflict, 'answer_completed', ['answer_type' => 'answer']);
        $chats->addAssistantMessage($conflict, '两份资料的生效时间不同。', []);

        Livewire::test(AgentChatPage::class)
            ->assertSee('证据不足')
            ->assertSee('要可靠回答刚才的问题，还需要补充哪些资料？')
            ->assertSee('需要澄清')
            ->assertSee('回答含冲突证据')
            ->assertSee('逐条比较这些冲突证据，并说明各自的时效性。');

        $cancelled = $runs->createQueued('query', 'cancel me');
        $cancelled->update(['status' => 'cancelled', 'termination_reason' => 'cancelled']);

        Livewire::test(AgentRunActivity::class, ['runId' => $cancelled->id, 'compact' => true])
            ->assertSee('本次运行已取消，没有生成新的可核验回答。')
            ->assertDontSee('停止');
    }
}
