<?php

namespace App\Services\Application;

use App\Entities\SourceCitation;
use App\Models\ChatMessage;
use App\Models\WikiProposal;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Wiki\ProposalRepository;
use App\Services\Wiki\ChangeSetValidator;
use App\Services\Wiki\CitationValidator;
use App\Services\Wiki\SourceCitationCodec;
use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiWorkspace;
use Symfony\Component\Yaml\Yaml;

class ProposalDraftService
{
    public function __construct(
        private readonly ProposalRepository $proposals,
        private readonly AgentRunRepository $runs,
        private readonly ChangeSetValidator $validator,
        private readonly CitationValidator $citationValidator,
        private readonly SourceCitationCodec $citations,
        private readonly WikiPathGuard $paths,
        private readonly WikiWorkspace $workspace,
    ) {}

    public function fromVerifiedAnswer(ChatMessage $message): WikiProposal
    {
        if ($message->role !== 'assistant' || $message->run === null) {
            throw new \InvalidArgumentException('只有已完成的 Agent 回答可以保存为提案。');
        }

        $answerEvent = $this->runs->events($message->run, ['answer_completed'])->last();
        if ($answerEvent === null || ($answerEvent->payloadData()['answer_type'] ?? null) !== 'answer') {
            throw new \InvalidArgumentException('澄清或证据不足的回答不能保存为提案。');
        }

        $path = 'wiki/syntheses/answer-'.$message->id.'.md';
        $applied = $this->proposals->appliedForPath($path);
        if ($applied !== null) {
            throw new \InvalidArgumentException(
                "目标页面已由 Proposal {$applied->uuid} 应用，不能由旧回答覆盖。",
            );
        }
        if ($this->workspace->exists($path)) {
            throw new \InvalidArgumentException('目标页面已经应用，不能由旧回答覆盖。');
        }
        $existing = $this->proposals->pendingForPath($path);
        if ($existing !== null) {
            return $existing;
        }

        $evidence = $this->verifiedEvidence($message);
        $body = preg_replace('/\n{2,}---\s*\n{2,}### 来源\s*\n.*\z/su', '', $message->content) ?? $message->content;
        $used = [];
        $body = preg_replace_callback(
            '/\[\^([A-Za-z][A-Za-z0-9_-]{0,39})\]/',
            function (array $match) use ($evidence, &$used): string {
                if (! isset($evidence[$match[1]])) {
                    throw new \InvalidArgumentException("回答引用了未知 Evidence ID：{$match[1]}");
                }
                $used[$match[1]] = $evidence[$match[1]];

                return $this->citations->format($evidence[$match[1]]);
            },
            $body,
        ) ?? $body;
        if ($used === []) {
            throw new \InvalidArgumentException('正式回答没有绑定可核验的 Evidence ID。');
        }

        $sourceIds = array_values(array_unique(array_map(
            static fn (SourceCitation $citation): string => $citation->path,
            array_values($used),
        )));
        $frontmatter = Yaml::dump([
            'type' => 'wiki/synthesis',
            'status' => 'draft',
            'updated' => now()->toDateString(),
            'source_ids' => $sourceIds,
            'confidence' => 'medium',
        ], 4, 2);
        $content = "---\n{$frontmatter}---\n\n# 对话沉淀 {$message->id}\n\n".trim($body)."\n";
        $errors = $this->citationValidator->validatePage($path, $content);
        if ($errors !== []) {
            throw new \InvalidArgumentException('回答证据无法保存：'.implode('；', $errors));
        }

        return $this->proposals->transaction(function () use ($message, $path, $content): WikiProposal {
            $proposal = $this->proposals->createDraft($message->run, '把已核验回答保存为 Wiki synthesis');
            $this->proposals->putPage($proposal, $path, $content, null, '用户主动选择保存该回答。');
            $proposal = $this->proposals->reloadWithChanges($proposal);
            $errors = $this->validator->validate($proposal);
            if ($errors !== []) {
                throw new \InvalidArgumentException('回答无法形成有效 ChangeSet：'.implode('；', $errors));
            }
            $this->proposals->setValidation($proposal, []);

            return $this->proposals->reloadWithChanges($proposal);
        });
    }

    public function archivePage(string $path): WikiProposal
    {
        $path = $this->paths->assertManagedPath($path);
        if (in_array($path, ['AGENTS.md', 'wiki/index.md', 'wiki/log.md'], true)) {
            throw new \InvalidArgumentException('核心 Schema、索引和日志不能归档。');
        }
        $hash = $this->workspace->sha256($path) ?? throw new \InvalidArgumentException('页面不存在。');
        $destination = 'wiki/archive/'.now()->format('Ymd-His').'-'.basename($path);

        return $this->proposals->transaction(function () use ($path, $destination, $hash): WikiProposal {
            $proposal = $this->proposals->createDraft(null, "归档 {$path}");
            $this->proposals->archivePage($proposal, $path, $destination, $hash, '用户发起页面归档。');
            $proposal = $this->proposals->reloadWithChanges($proposal);
            $this->proposals->setValidation($proposal, $this->validator->validate($proposal));

            return $this->proposals->reloadWithChanges($proposal);
        });
    }

    /** @return array<string, SourceCitation> */
    private function verifiedEvidence(ChatMessage $message): array
    {
        $evidence = [];
        foreach ($message->citationData() as $item) {
            if (! is_array($item)
                || ! is_string($item['evidence_id'] ?? null)
                || preg_match('/\AE[1-9]\d*\z/', $item['evidence_id']) !== 1
                || ! is_string($item['raw_path'] ?? null)
                || ! is_string($item['raw_sha256'] ?? null)
                || ! is_string($item['locator'] ?? null)
                || ! array_key_exists('stale', $item)
                || $item['stale'] !== false) {
                throw new \InvalidArgumentException('回答包含缺失、陈旧或格式无效的结构化证据。');
            }

            $citation = new SourceCitation(
                $item['raw_path'],
                strtolower($item['raw_sha256']),
                $item['locator'],
            );
            $this->citations->format($citation);
            $errors = $this->citationValidator->validateSourceReference(
                $citation->path,
                $citation->sha256,
                $citation->locator,
            );
            if ($errors !== []) {
                throw new \InvalidArgumentException('回答证据已失效：'.implode('；', $errors));
            }
            if (isset($evidence[$item['evidence_id']]) && $evidence[$item['evidence_id']] != $citation) {
                throw new \InvalidArgumentException("Evidence ID 重复且身份不一致：{$item['evidence_id']}");
            }
            $evidence[$item['evidence_id']] = $citation;
        }
        if ($evidence === []) {
            throw new \InvalidArgumentException('回答没有可核验的结构化证据。');
        }

        return $evidence;
    }
}
