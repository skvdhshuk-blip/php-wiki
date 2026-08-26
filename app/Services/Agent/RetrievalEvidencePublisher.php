<?php

namespace App\Services\Agent;

use App\Entities\AgentToolInvocation;
use App\Entities\EvidenceBundle;
use App\Entities\QueryPlan;
use App\Models\AgentRun;
use App\Repositories\Agent\AgentRunRepository;

class RetrievalEvidencePublisher
{
    private ?EvidenceBundle $evidence = null;

    /** @var array<string, true> */
    private array $publishedEvidenceIds = [];

    private readonly EvidenceIdRegistry $ids;

    public function __construct(
        private readonly QueryPlan $plan,
        private readonly AgentRun $run,
        private readonly QueryToolBudget $budget,
        private readonly EvidenceBundleBuilder $builder,
        private readonly AgentRunRepository $runs,
    ) {
        $this->ids = new EvidenceIdRegistry;
    }

    /** @param list<AgentToolInvocation> $toolInvocations */
    public function __invoke(array $toolInvocations): void
    {
        $this->evidence = $this->builder->build($this->plan, $toolInvocations, $this->ids);
        foreach ($this->evidence->items as $item) {
            if (isset($this->publishedEvidenceIds[$item->evidenceId])) {
                continue;
            }
            $this->publishedEvidenceIds[$item->evidenceId] = true;
            $this->runs->event($this->run, 'evidence_added', $item->toArray());
        }
        $this->runs->event($this->run, 'coverage_updated', [
            'coverage' => $this->evidence->coverage,
            'gaps' => $this->evidence->gaps,
            'conflicts' => $this->evidence->conflicts,
            'conflict_evidence' => $this->evidence->conflictEvidence,
            'warnings' => $this->evidence->warnings,
            'evidence_count' => count($this->evidence->items),
            'tool_budget' => $this->budget->usage(),
        ]);
    }

    public function latest(): ?EvidenceBundle
    {
        return $this->evidence;
    }
}
