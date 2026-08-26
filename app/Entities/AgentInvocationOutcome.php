<?php

namespace App\Entities;

use HaoCode\Sdk\QueryResult;

final readonly class AgentInvocationOutcome
{
    /** @param list<AgentToolInvocation> $toolInvocations */
    public function __construct(
        public QueryResult $result,
        public bool $fallbackUsed = false,
        public array $toolInvocations = [],
    ) {}
}
