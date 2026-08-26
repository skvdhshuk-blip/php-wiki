<?php

namespace App\Entities;

final readonly class QueryPlan
{
    public const LOOKUP = 'lookup';

    public const RESEARCH = 'research';

    /**
     * @param  list<string>  $subquestions
     * @param  list<string>  $queries
     * @param  list<string>  $wikiScopes
     * @param  list<string>  $stopConditions
     */
    public function __construct(
        public string $mode,
        public array $subquestions,
        public array $queries,
        public array $wikiScopes,
        public int $maxSearches,
        public int $maxReads,
        public array $stopConditions,
        public string $reason,
        public bool $requiresClarification = false,
        public ?string $ambiguityReason = null,
    ) {
        if (! in_array($mode, [self::LOOKUP, self::RESEARCH], true)) {
            throw new \InvalidArgumentException("Unsupported query mode: {$mode}");
        }
        if ($subquestions === [] || $queries === []) {
            throw new \InvalidArgumentException('Query plan requires subquestions and search queries.');
        }

        $expectedSearches = $mode === self::LOOKUP ? 2 : 4;
        $expectedReads = $mode === self::LOOKUP ? 4 : 12;
        if ($maxSearches !== $expectedSearches || $maxReads !== $expectedReads) {
            throw new \InvalidArgumentException('Query plan tool budgets do not match the selected mode.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'subquestions' => $this->subquestions,
            'queries' => $this->queries,
            'wiki_scopes' => $this->wikiScopes,
            'max_searches' => $this->maxSearches,
            'max_reads' => $this->maxReads,
            'stop_conditions' => $this->stopConditions,
            'reason' => $this->reason,
            'requires_clarification' => $this->requiresClarification,
            'ambiguity_reason' => $this->ambiguityReason,
        ];
    }
}
