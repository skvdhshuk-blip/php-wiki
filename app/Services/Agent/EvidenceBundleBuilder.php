<?php

namespace App\Services\Agent;

use App\Entities\AgentToolInvocation;
use App\Entities\EvidenceBundle;
use App\Entities\EvidenceItem;
use App\Entities\QueryPlan;
use App\Exceptions\AgentContractException;
use App\Services\Wiki\CitationValidator;
use App\Services\Wiki\WikiPathGuard;
use App\Services\Wiki\WikiWorkspace;

class EvidenceBundleBuilder
{
    public function __construct(
        private readonly CitationValidator $citations,
        private readonly WikiPathGuard $paths,
        private readonly WikiWorkspace $workspace,
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
                $identity = implode('|', [
                    $candidate['tool_call_id'],
                    $candidate['wiki_path'],
                    $candidate['raw_path'] ?? '',
                    $candidate['raw_sha256'] ?? '',
                    $candidate['locator'],
                    $candidate['quote'],
                ]);
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
            static fn (AgentToolInvocation $call): bool => $call->name === 'SearchWiki',
        ));
        $reads = count(array_filter(
            $invocations,
            static fn (AgentToolInvocation $call): bool => in_array($call->name, ['ReadWikiPage', 'ReadSourceExcerpt'], true),
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

        preg_match_all(
            '/\[\[source:([^|\]]+)\|sha256:([a-f0-9]{64})\|([^\]]+)\]\]/i',
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        $hasRawCitations = str_contains($content, '[[source:');
        if ($hasRawCitations && substr_count($content, '[[source:') !== count($matches)) {
            $warnings[] = "{$path} 包含格式不完整的 source 引用，相关 Wiki 陈述已隔离。";
        }
        $candidates = [];
        foreach ($matches as $match) {
            $rawPath = $match[1][0];
            $sha256 = strtolower($match[2][0]);
            $locator = $match[3][0];
            $errors = $this->citations->validateSourceReference($rawPath, $sha256, $locator);
            if ($errors !== []) {
                foreach ($errors as $error) {
                    $warnings[] = $error;
                }

                continue;
            }

            $claim = $this->lineAtOffset($content, $match[0][1]);
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

        if ($candidates !== [] || $path === 'AGENTS.md' || $hasRawCitations) {
            return $candidates;
        }

        [$locator, $quote] = $this->wikiExcerpt($content);
        if ($quote === '') {
            return [];
        }

        return [[
            'tool_call_id' => $invocation->callId,
            'wiki_path' => $path,
            'wiki_hash' => $wikiHash,
            'raw_path' => null,
            'raw_sha256' => null,
            'locator' => $locator,
            'quote' => $quote,
            'claim_hint' => $quote,
            'confidence' => 'low',
        ]];
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
                fn (EvidenceItem $item): bool => $this->matches($subquestion, $item),
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

    /** @param list<EvidenceItem> $items */
    private function hasContradictoryValues(array $items): bool
    {
        $valueSets = [];
        $polarities = [];
        foreach ($items as $item) {
            $text = mb_strtolower($item->claimHint.' '.$item->quote);
            preg_match_all('/\b\d+(?:\.\d+)?\b/u', $text, $matches);
            if ($matches[0] !== []) {
                $values = array_values(array_unique($matches[0]));
                sort($values);
                $valueSets[] = implode(',', $values);
            }
            $polarities[] = preg_match('/不得|禁止|无需|不允许|不能|\bnot\b|\bnever\b|\bprohibit/iu', $text) === 1;
        }

        if (count(array_unique($valueSets)) > 1) {
            return true;
        }

        return in_array(true, $polarities, true) && in_array(false, $polarities, true);
    }

    private function matches(string $question, EvidenceItem $item): bool
    {
        $haystack = mb_strtolower($item->wikiPath.' '.$item->claimHint.' '.$item->quote);
        foreach ($this->tokens($question) as $token) {
            if (str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $normalized = mb_strtolower($text);
        preg_match_all('/[a-z0-9_-]{2,}|[\p{Han}]{2,}/u', $normalized, $matches);
        $tokens = [];
        foreach ($matches[0] as $chunk) {
            if (preg_match('/^[\p{Han}]+$/u', $chunk) === 1) {
                for ($index = 0; $index < mb_strlen($chunk) - 1; $index++) {
                    $tokens[] = mb_substr($chunk, $index, 2);
                }
            } else {
                $tokens[] = $chunk;
            }
        }

        $stop = ['什么', '怎么', '如何', '为何', '请问', '哪些', '是否', '以及', '并且', '同时'];

        return array_values(array_diff(array_unique($tokens), $stop));
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
        $claim = preg_replace('/\[\[source:[^\]]+\]\]/u', '', $claim) ?? $claim;
        $claim = preg_replace('/\s+/u', ' ', strip_tags($claim)) ?? $claim;

        return trim(mb_substr($claim, 0, 800), " \t\n\r\0\x0B#-*>");
    }

    private function readLineQuote(string $rawPath, string $locator): string
    {
        if (! preg_match('/^lines:(\d+)-(\d+)$/', $locator, $match)) {
            return '';
        }
        $lines = file($this->paths->absolute($rawPath), FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }
        $start = max(1, (int) $match[1]);
        $end = max($start, (int) $match[2]);

        return trim(mb_substr(implode("\n", array_slice($lines, $start - 1, $end - $start + 1)), 0, 1600));
    }

    /** @return array{string, string} */
    private function wikiExcerpt(string $content): array
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $selected = [];
        $first = null;
        $last = null;
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || $line === '---' || str_starts_with($line, '#')) {
                continue;
            }
            $first ??= $index + 1;
            $last = $index + 1;
            $selected[] = $line;
            if (mb_strlen(implode(' ', $selected)) >= 800) {
                break;
            }
        }

        return [
            'lines:'.($first ?? 1).'-'.($last ?? 1),
            trim(mb_substr(implode(' ', $selected), 0, 1200)),
        ];
    }
}
