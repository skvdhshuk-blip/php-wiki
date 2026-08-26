<?php

namespace App\Services\Agent;

use HaoCode\Sdk\Agent;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\Runner;
use HaoCode\Sdk\RunOptions;

class HaoAgentRunner implements AgentRunner
{
    public function run(Agent $agent, string $prompt, RunOptions $options): QueryResult
    {
        return Runner::run($agent, $prompt, $options);
    }
}
