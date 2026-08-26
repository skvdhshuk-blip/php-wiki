<?php

namespace App\Services\Agent;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\RunOptions;

interface AgentRunner
{
    public function run(Agent $agent, string $prompt, RunOptions $options): QueryResult;
}
