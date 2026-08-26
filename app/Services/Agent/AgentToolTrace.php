<?php

namespace App\Services\Agent;

use App\Entities\AgentToolInvocation;
use HaoCode\Tools\ToolResult;

class AgentToolTrace
{
    /** @var array<string, list<array{call_id: string, input: array<string, mixed>}>> */
    private array $pending = [];

    /** @var list<AgentToolInvocation> */
    private array $completed = [];

    public function __construct(
        private readonly string $runUuid,
        private int $sequence = 0,
    ) {}

    /** @param array<string, mixed> $input */
    public function started(string $name, array $input): string
    {
        $callId = $this->runUuid.':tool:'.(++$this->sequence);
        $this->pending[$name][] = ['call_id' => $callId, 'input' => $input];

        return $callId;
    }

    public function completed(string $name, ToolResult $result): string
    {
        $pending = isset($this->pending[$name]) ? array_shift($this->pending[$name]) : null;
        if (($this->pending[$name] ?? []) === []) {
            unset($this->pending[$name]);
        }
        $pending ??= [
            'call_id' => $this->runUuid.':tool:'.(++$this->sequence),
            'input' => [],
        ];

        $this->completed[] = new AgentToolInvocation(
            callId: $pending['call_id'],
            name: $name,
            input: $pending['input'],
            output: $result->output,
            isError: $result->isError,
        );

        return $pending['call_id'];
    }

    /** @return list<AgentToolInvocation> */
    public function completedInvocations(): array
    {
        return $this->completed;
    }
}
