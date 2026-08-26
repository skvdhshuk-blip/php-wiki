<?php

namespace App\Entities;

use HaoCode\Sdk\QueryResult;

final readonly class AgentInvocationOutcome
{
    public function __construct(
        public QueryResult $result,
        public bool $fallbackUsed = false,
    ) {}
}
