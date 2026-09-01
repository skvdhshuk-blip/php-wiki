<?php

namespace App\Services\Agent;

use App\Entities\AgentToolInvocation;
use App\Entities\EvidenceBundle;
use App\Entities\EvidenceItem;
use App\Entities\QueryPlan;
use App\Exceptions\AgentContractException;
use App\Services\Source\SourceCatalog;
use App\Services\Wiki\CitationValidator;
use App\Services\Wiki\SourceCitationCodec;
use App\Services\Wiki\TextTokenizer;
use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiWorkspace;

class EvidenceBundleBuilder
{
    public function __construct(
        private readonly CitationValidator $citations,
        private readonly SourceCitationCodec $citationCodec,
        private readonly WikiPathGuard $paths,
        private readonly SourceCatalog $catalog,
        private readonly WikiWorkspace $workspace,
        private readonly TextTokenizer $tokenizer,
        private readonly PropositionAnalyzer $analyzer,
    ) {}

    /** @param list<AgentToolInvocation> $toolInvocations */
    public function build(
        QueryPlan $plan,
        array $toolInvocations,
        ?EvidenceIdRegistry $ids = null,
    ): EvidenceBundle {
        $ids ??= new EvidenceIdRegistry;
        $this->assertToolBudget($plan, $toolInvocations);
        $items = [];
        $warnings = [];
        $deduplicated = [];

        foreach ($toolInvocations as $invocation) {
            if ($invocation->isError) {
                $warnings[] = "{$invocation->name} 调用失败。";

                continue;
            }

            $candidates = match ($invocation->name) {
                'ReadWikiPage' => $this->fromWikiPage($invocation, $warnings),
                'ReadSourceExcerpt' => $this->fromSourceExcerpt($invocation, $warnings),
                default => [],
            };
            foreach ($candidates as $candidate) {
                $identity = $this->evidenceIdentity($candidate);
                if (isset($deduplicated[$identity])) {
                    continue;
                }
                $deduplicated[$identity] = true;
                $items[] = new EvidenceItem(
                    evidenceId: $ids->idFor($identity),
                    toolCallId: $candidate['tool_call_id'],
                    wikiPath: $candidate['wiki_path'],
                    wikiHash: $candidate['wiki_hash'],
                    rawPath: $candidate['raw_path'],
                    rawSha256: $candidate['raw_sha256'],
                    locator: $candidate['locator'],
                    quote: $candidate['quote'],
                    claimHint: $candidate['claim_hint'],
                    stale: false,
                    confidence: $candidate['confidence'],
                );
            }
        }

        [$coverage, $gaps, $conflicts, $conflictEvidence] = $this->coverage($plan, $items);

        return new EvidenceBundle(
            items: $items,
            coverage: $coverage,
            gaps: $gaps,
            conflicts: $conflicts,
            warnings: array_values(array_unique($warnings)),
            conflictEvidence: $conflictEvidence,
        );
    }

    /**
     * @param  list<AgentToolInvocation>  $invocations
     */
    private function assertToolBudget(QueryPlan $plan, array $invocations): void
    {
        $searches = count(array_filter(
            $invocations,
            static fn (AgentToolInvocation $call): bool => $call->name === 'SearchWiki' && ! $call->isError,
        ));
        $reads = count(array_filter(
            $invocations,
            static fn (AgentToolInvocation $call): bool => ! $call->isError
                && in_array($call->name, ['ReadWikiPage', 'ReadSourceExcerpt'], true),
        ));
        if ($searches > $plan->maxSearches || $reads > $plan->maxReads) {
            throw new AgentContractException(
                "Agent exceeded query tool budget: searches={$searches}/{$plan->maxSearches}, reads={$reads}/{$plan->maxReads}.",
            );
        }
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array{tool_call_id: string, wiki_path: string, wiki_hash: string, raw_path: ?string, raw_sha256: ?string, locator: string, quote: string, claim_hint: string, confidence: string}>
     */
    private function fromWikiPage(AgentToolInvocation $invocation, array &$warnings): array
    {
        try {
            $envelope = json_decode($invocation->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $warnings[] = "{$invocation->callId} 的 Wiki 页面 envelope 不是合法 JSON。";

            return [];
        }
        if (! is_array($envelope)
            || ! is_string($envelope['path'] ?? null)
            || ! is_string($envelope['sha256'] ?? null)
            || ! is_string($envelope['content'] ?? null)) {
            $warnings[] = "{$invocation->callId} 的 Wiki 页面 envelope 缺少身份或内容。";

            return [];
        }

        $path = (string) ($invocation->input['path'] ?? '');
        try {
            $path = $this->paths->assertManagedPath($path);
        } catch (\InvalidArgumentException $exception) {
            $warnings[] = "{$invocation->callId} 返回了无效 Wiki 路径：{$exception->getMessage()}";

            return [];
        }

        $content = $envelope['content'];
        $wikiHash = hash('sha256', $content);
        if ($envelope['path'] !== $path || ! hash_equals($wikiHash, strtolower($envelope['sha256']))) {
            $warnings[] = "{$invocation->callId} 的 Wiki 页面 envelope 身份不一致。";

            return [];
        }
        if (! $this->workspace->exists($path) || $this->workspace->sha256($path) !== $wikiHash) {
            $warnings[] = "{$path} 在读取后发生变化，相关证据已隔离。";

            return [];
        }

        $matches = $this->citationCodec->matches($content);
        $hasRawCitations = str_contains($content, '[[source:');
        if ($hasRawCitations && $this->citationCodec->countMarkers($content) !== count($matches)) {
            $warnings[] = "{$path} 包含格式不完整的 source 引用，相关 Wiki 陈述已隔离。";
        }
        $candidates = [];
        foreach ($matches as $match) {
            $citation = $match['citation'];
            $rawPath = $citation->path;
            $sha256 = $citation->sha256;
            $locator = $citation->locator;
            $errors = $this->citations->validateSourceReference($rawPath, $sha256, $locator);
            if ($errors !== []) {
                foreach ($errors as $error) {
                    $warnings[] = $error;
                }

                continue;
            }

            $claim = $this->lineAtOffset($content, $match['offset']);
            $quote = str_starts_with($locator, 'lines:')
                ? $this->readLineQuote($rawPath, $locator)
                : $this->cleanClaim($claim);
            if ($quote === '') {
                $warnings[] = "{$rawPath}|{$locator} 没有可引用原文。";

                continue;
            }
            $candidates[] = [
                'tool_call_id' => $invocation->callId,
                'wiki_path' => $path,
                'wiki_hash' => $wikiHash,
                'raw_path' => $rawPath,
                'raw_sha256' => $sha256,
                'locator' => $locator,
                'quote' => $quote,
                'claim_hint' => $this->cleanClaim($claim),
                'confidence' => 'high',
            ];
        }

        if ($candidates === [] && ! $hasRawCitations && $path !== 'AGENTS.md') {
            $warnings[] = "{$path} 没有可追溯到 Source Catalog 的引用，不能作为事实证据。";
        }

        return $candidates;
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array{tool_call_id: string, wiki_path: string, wiki_hash: string, raw_path: ?string, raw_sha256: ?string, locator: string, quote: string, claim_hint: string, confidence: string}>
     */
    private function fromSourceExcerpt(AgentToolInvocation $invocation, array &$warnings): array
    {
        try {
            $envelope = json_decode($invocation->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $warnings[] = "{$invocation->callId} 的来源摘录 envelope 不是合法 JSON。";

            return [];
        }
        if (! is_array($envelope)
            || ! is_string($envelope['raw_path'] ?? null)
            || ! is_string($envelope['raw_sha256'] ?? null)
            || ! is_string($envelope['locator'] ?? null)
            || ! is_string($envelope['quote'] ?? null)) {
            $warnings[] = "{$invocation->callId} 的来源摘录 envelope 缺少证据字段。";

            return [];
        }

        $rawPath = $envelope['raw_path'];
        $sha256 = strtolower($envelope['raw_sha256']);
        $start = max(1, (int) ($invocation->input['start_line'] ?? 1));
        $end = max($start, (int) ($invocation->input['end_line'] ?? $start));
        $locator = "lines:{$start}-{$end}";
        if (($invocation->input['path'] ?? null) !== $rawPath || $envelope['locator'] !== $locator) {
            $warnings[] = "{$invocation->callId} 的来源摘录 envelope 身份不一致。";

            return [];
        }
        $errors = $this->citations->validateSourceReference($rawPath, $sha256, $locator);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $warnings[] = $error;
            }

            return [];
        }

        $quote = trim($envelope['quote']);
        if ($quote === '') {
            return [];
        }

        return [[
            'tool_call_id' => $invocation->callId,
            'wiki_path' => '',
            'wiki_hash' => '',
            'raw_path' => $rawPath,
            'raw_sha256' => $sha256,
            'locator' => $locator,
            'quote' => mb_substr($quote, 0, 1600),
            'claim_hint' => mb_substr($quote, 0, 500),
            'confidence' => 'high',
        ]];
    }

    /**
     * @param  list<EvidenceItem>  $items
     * @return array{array<string, 'covered'|'gap'|'conflict'>, list<string>, list<string>, array<string, list<string>>}
     */
    private function coverage(QueryPlan $plan, array $items): array
    {
        $coverage = [];
        $gaps = [];
        $conflicts = [];
        $conflictEvidence = [];
        foreach ($plan->subquestions as $index => $subquestion) {
            $key = 'Q'.($index + 1);
            $matching = array_values(array_filter(
                $items,
                fn (EvidenceItem $item): bool => $this->matches($subquestion, $item)
                    && ! $this->statesKnowledgeAbsence($item),
            ));
            if ($matching === []) {
                $coverage[$key] = 'gap';
                $gaps[] = $subquestion;

                continue;
            }

            $combined = mb_strtolower(implode(' ', array_map(
                static fn (EvidenceItem $item): string => $item->claimHint.' '.$item->quote,
                $matching,
            )));
            if (count($matching) > 1 && (
                preg_match('/冲突|矛盾|不一致|相反|conflict|contradict/u', $combined) === 1
                || $this->hasContradictoryValues($matching)
            )) {
                $coverage[$key] = 'conflict';
                $conflicts[] = $subquestion;
                $conflictEvidence[$key] = array_map(
                    static fn (EvidenceItem $item): string => $item->evidenceId,
                    $matching,
                );
            } else {
                $coverage[$key] = 'covered';
            }
        }

        return [$coverage, $gaps, $conflicts, $conflictEvidence];
    }

    /**
     * 两条证据是否真的互相矛盾。
     *
     * 按「整条证据里出现的数字集合不同」判定会把并列事实误判成冲突：
     * 「日志保留 30 天」和「最多导出 10 份」谈的根本不是一件事。
     * 因此先剥掉数字得到命题骨架，只有骨架相同——即在说同一件事——
     * 而数值或肯否不同，才算冲突。
     *
     * @param  list<EvidenceItem>  $items
     */
    private function hasContradictoryValues(array $items): bool
    {
        $clauses = [];
        foreach ($items as $index => $item) {
            foreach ($this->analyzer->clauses($item->claimHint.'。'.$item->quote) as $clause) {
                $clauses[] = ['owner' => $index, 'clause' => $clause];
            }
        }

        foreach ($clauses as $left) {
            foreach ($clauses as $right) {
                if ($left['owner'] === $right['owner']) {
                    continue;
                }
                if (! $this->analyzer->samePropositions($left['clause']['tokens'], $right['clause']['tokens'])) {
                    continue;
                }
                if ($left['clause']['polarity'] !== $right['clause']['polarity']) {
                    return true;
                }
                if ($this->analyzer->statesDifferentQuantities($left['clause'], $right['clause'])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matches(string $question, EvidenceItem $item): bool
    {
        $evidenceText = $item->claimHint.' '.$item->quote;
        $haystack = mb_strtolower($item->wikiPath.' '.$evidenceText);
        $tokens = $this->tokens($question);
        $matched = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $matched++;
            }
        }

        if ($tokens === []) {
            return false;
        }

        $minimum = count($tokens) <= 2 ? 1 : max(2, (int) ceil(count($tokens) * 0.2));
        if ($this->asksForChapter($question) && $this->containsChapter($evidenceText)) {
            return true;
        }

        if ($matched < $minimum) {
            return false;
        }

        return ! $this->asksForExplanation($question) || $this->containsExplanation($evidenceText);
    }

    private function asksForExplanation(string $question): bool
    {
        return preg_match('/为什么|为何|何以|原因是什么|why\b|reason\b/iu', $question) === 1;
    }

    private function containsExplanation(string $evidence): bool
    {
        return preg_match(
            '/因为|由于|因此|所以|从而|否则|以免|意味着|导致|原因(?:是|在于)|'
            .'\bbecause\b|\bdue\s+to\b|\btherefore\b|\bso\s+that\b|\bleads?\s+to\b/iu',
            $evidence,
        ) === 1;
    }

    private function asksForChapter(string $question): bool
    {
        return preg_match('/哪一章|第几章|哪章|which\s+chapter/iu', $question) === 1;
    }

    private function containsChapter(string $evidence): bool
    {
        return preg_match('/来自章节|第[一二三四五六七八九十百\d]+章|\bchapter\s+\w+/iu', $evidence) === 1;
    }

    private function statesKnowledgeAbsence(EvidenceItem $item): bool
    {
        $text = mb_strtolower($item->claimHint.' '.$item->quote);

        return preg_match(
            '/不属于(?:本|该)?知识库|知识库[^。.!?]{0,40}未(?:记录|收录|列出)|'
            .'not\s+(?:documented|listed|recorded|present)\b|outside\s+(?:the\s+)?knowledge\s+base/iu',
            $text,
        ) === 1;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        return $this->tokenizer->queryTokens($text);
    }

    private function lineAtOffset(string $content, int $offset): string
    {
        $start = strrpos(substr($content, 0, $offset), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($content, "\n", $offset);

        return substr($content, $start, $end === false ? null : $end - $start);
    }

    private function cleanClaim(string $claim): string
    {
        foreach ($this->citationCodec->matches($claim) as $match) {
            $claim = str_replace($match['markdown'], '', $claim);
        }
        $claim = preg_replace('/\s+/u', ' ', strip_tags($claim)) ?? $claim;

        return trim(mb_substr($claim, 0, 800), " \t\n\r\0\x0B#-*>");
    }

    private function readLineQuote(string $rawPath, string $locator): string
    {
        if (! preg_match('/^lines:(\d+)-(\d+)$/', $locator, $match)) {
            return '';
        }
        $lines = file($this->catalog->absolute($rawPath), FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }
        $start = max(1, (int) $match[1]);
        $end = max($start, (int) $match[2]);

        return trim(mb_substr(implode("\n", array_slice($lines, $start - 1, $end - $start + 1)), 0, 1600));
    }

    /**
     * @param  array{tool_call_id: string, wiki_path: string, wiki_hash: string, raw_path: ?string, raw_sha256: ?string, locator: string, quote: string, claim_hint: string, confidence: string}  $candidate
     */
    private function evidenceIdentity(array $candidate): string
    {
        if ($candidate['raw_path'] !== null && $candidate['raw_sha256'] !== null) {
            return implode('|', [
                'source',
                $candidate['raw_path'],
                $candidate['raw_sha256'],
                $candidate['locator'],
            ]);
        }

        return implode('|', [
            'wiki',
            $candidate['wiki_path'],
            $candidate['wiki_hash'],
            $candidate['locator'],
            $candidate['quote'],
        ]);
    }
}
