<?php

namespace App\Entities;

final readonly class EvidenceItem
{
    public function __construct(
        public string $evidenceId,
        public string $toolCallId,
        public string $wikiPath,
        public string $wikiHash,
        public ?string $rawPath,
        public ?string $rawSha256,
        public string $locator,
        public string $quote,
        public string $claimHint,
        public bool $stale,
        public string $confidence,
    ) {
        if (! preg_match('/^E[1-9]\d*$/', $evidenceId)) {
            throw new \InvalidArgumentException("Invalid evidence ID: {$evidenceId}");
        }
        if (! in_array($confidence, ['high', 'medium', 'low'], true)) {
            throw new \InvalidArgumentException("Invalid evidence confidence: {$confidence}");
        }
        if ($quote === '') {
            throw new \InvalidArgumentException('Evidence quote cannot be empty.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'evidence_id' => $this->evidenceId,
            'tool_call_id' => $this->toolCallId,
            'wiki_path' => $this->wikiPath,
            'wiki_revision_or_hash' => $this->wikiHash,
            'raw_path' => $this->rawPath,
            'raw_sha256' => $this->rawSha256,
            'locator' => $this->locator,
            'quote' => $this->quote,
            'claim_hint' => $this->claimHint,
            'stale' => $this->stale,
            'confidence' => $this->confidence,
        ];
    }
}
