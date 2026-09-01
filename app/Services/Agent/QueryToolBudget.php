<?php

namespace App\Services\Agent;

use App\Entities\QueryPlan;

class QueryToolBudget
{
    private int $searches = 0;

    private int $reads = 0;

    private int $noNewEvidenceRounds = 0;

    /** @var array<string, true> */
    private array $seenSearchPaths = [];

    /** @var list<string> */
    private array $queries = [];

    public function __construct(private readonly QueryPlan $plan) {}

    public function admitSearch(string $query): void
    {
        if ($this->searches >= $this->plan->maxSearches) {
            throw new \RuntimeException("SearchWiki budget exhausted ({$this->searches}/{$this->plan->maxSearches}).");
        }
        if ($this->noNewEvidenceRounds >= 2) {
            throw new \RuntimeException('SearchWiki stopped after two rounds without new candidates.');
        }

        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $query)));
        if (in_array($normalized, $this->queries, true)) {
            throw new \RuntimeException('SearchWiki rejected an identical repeated query.');
        }

        $this->queries[] = $normalized;
        $this->searches++;
    }

    /**
     * @param  list<array{
     *     path: string, title: string, heading: string,
     *     snippet: string, source_ids: string, score: float
     * }>  $results
     */
    public function recordSearchResults(array $results): void
    {
        $added = 0;
        foreach ($results as $result) {
            $path = $result['path'];
            if ($path !== '' && ! isset($this->seenSearchPaths[$path])) {
                $this->seenSearchPaths[$path] = true;
                $added++;
            }
        }

        $this->noNewEvidenceRounds = $added === 0 ? $this->noNewEvidenceRounds + 1 : 0;
    }

    public function admitRead(): void
    {
        if ($this->reads >= $this->plan->maxReads) {
            throw new \RuntimeException("Knowledge read budget exhausted ({$this->reads}/{$this->plan->maxReads}).");
        }

        $this->reads++;
    }

    /** @return array{searches: int, reads: int, max_searches: int, max_reads: int, no_new_evidence_rounds: int, queries: list<string>} */
    public function usage(): array
    {
        return [
            'searches' => $this->searches,
            'reads' => $this->reads,
            'max_searches' => $this->plan->maxSearches,
            'max_reads' => $this->plan->maxReads,
            'no_new_evidence_rounds' => $this->noNewEvidenceRounds,
            'queries' => $this->queries,
        ];
    }
}
