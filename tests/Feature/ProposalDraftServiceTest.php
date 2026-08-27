<?php

namespace Tests\Feature;

use App\Constants\ProposalStatus;
use App\Models\ChatMessage;
use App\Models\WikiSource;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Chat\ChatRepository;
use App\Repositories\Wiki\ProposalRepository;
use App\Services\Application\ProposalDraftService;
use App\Services\Source\SourceScanner;
use App\Services\Wiki\WikiWorkspace;
use App\Services\Wiki\WorkspaceInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class ProposalDraftServiceTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
        app(WorkspaceInitializer::class)->initialize();
        File::put($this->wikiRoot.'/raw/note.md', "alpha evidence\nbeta evidence\n");
        app(SourceScanner::class)->scan();
    }

    protected function tearDown(): void
    {
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_verified_answer_uses_structured_citations_and_reuses_open_proposal(): void
    {
        $message = $this->answer(
            'answer',
            "## 结论\n\nAlpha 已核验。 [^E1]\n\n---\n\n### 来源\n\n[^E1]: display-only footnote",
            [$this->citation('E1')],
        );

        $first = app(ProposalDraftService::class)->fromVerifiedAnswer($message);
        $second = app(ProposalDraftService::class)->fromVerifiedAnswer($message->fresh());
        $content = (string) $first->changes->sole()->content;
        $sha = WikiSource::query()->sole()->sha256;

        $this->assertSame($first->id, $second->id);
        $this->assertSame(ProposalStatus::Pending->value, $first->status);
        $this->assertStringContainsString('source_ids:', $content);
        $this->assertStringContainsString('- raw/note.md', $content);
        $this->assertStringContainsString("[[source:raw/note.md|sha256:{$sha}|lines:1-1]]", $content);
        $this->assertStringNotContainsString('[^E1]', $content);
        $this->assertStringNotContainsString('### 来源', $content);
        $this->assertStringNotContainsString('display-only footnote', $content);
    }

    public function test_clarification_and_insufficient_answers_are_rejected(): void
    {
        foreach (['clarification', 'insufficient_evidence'] as $type) {
            try {
                app(ProposalDraftService::class)->fromVerifiedAnswer(
                    $this->answer($type, 'not a verified answer', []),
                );
                $this->fail("Expected {$type} rejection.");
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('不能保存为提案', $exception->getMessage());
            }
        }
    }

    public function test_unknown_evidence_id_is_rejected_without_creating_proposal(): void
    {
        $message = $this->answer('answer', 'Unknown [^E2]', [$this->citation('E1')]);

        try {
            app(ProposalDraftService::class)->fromVerifiedAnswer($message);
            $this->fail('Expected unknown Evidence ID rejection.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('未知 Evidence ID', $exception->getMessage());
        }

        $this->assertDatabaseCount('wiki_proposals', 0);
    }

    public function test_stale_source_evidence_is_rejected(): void
    {
        $message = $this->answer('answer', 'Alpha [^E1]', [$this->citation('E1')]);
        File::put($this->wikiRoot.'/raw/note.md', "changed\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('回答证据已失效');
        app(ProposalDraftService::class)->fromVerifiedAnswer($message);
    }

    public function test_existing_applied_target_is_not_overwritten(): void
    {
        $message = $this->answer('answer', 'Alpha [^E1]', [$this->citation('E1')]);
        app(WikiWorkspace::class)->atomicWrite(
            'wiki/syntheses/answer-'.$message->id.'.md',
            "---\ntype: wiki/synthesis\nstatus: active\nupdated: 2026-08-27\n---\n\n# Existing\n",
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('目标页面已经应用');
        app(ProposalDraftService::class)->fromVerifiedAnswer($message);
    }

    public function test_applied_database_record_blocks_recreation_when_target_file_is_missing(): void
    {
        $message = $this->answer('answer', 'Alpha [^E1]', [$this->citation('E1')]);
        $path = 'wiki/syntheses/answer-'.$message->id.'.md';
        $repository = app(ProposalRepository::class);
        $applied = $repository->createDraft(null, 'historical applied proposal');
        $repository->putPage($applied, $path, 'historical content', null, 'historical change');
        $repository->setValidation($applied, []);
        $applied->update(['status' => ProposalStatus::Applied->value]);

        try {
            app(ProposalDraftService::class)->fromVerifiedAnswer($message);
            $this->fail('Expected applied database record to block recreation.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString($applied->uuid, $exception->getMessage());
        }

        $this->assertDatabaseCount('wiki_proposals', 1);
    }

    /** @param list<array<string, mixed>> $citations */
    private function answer(string $type, string $content, array $citations): ChatMessage
    {
        $chats = app(ChatRepository::class);
        $runs = app(AgentRunRepository::class);
        $thread = $chats->createThread();
        $run = $runs->createQueued('query', 'question', threadId: $thread->id);
        $runs->event($run, 'answer_completed', ['answer_type' => $type]);

        return $chats->addAssistantMessage($run, $content, $citations)
            ?? throw new \RuntimeException('Assistant message was not created.');
    }

    /** @return array<string, mixed> */
    private function citation(string $evidenceId): array
    {
        return [
            'evidence_id' => $evidenceId,
            'raw_path' => 'raw/note.md',
            'raw_sha256' => WikiSource::query()->sole()->sha256,
            'locator' => 'lines:1-1',
            'stale' => false,
        ];
    }
}
