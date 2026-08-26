<?php

namespace App\Entities;

final readonly class AgentToolInvocation
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $callId,
        public string $name,
        public array $input,
        public string $output,
        public bool $isError,
    ) {}
}
