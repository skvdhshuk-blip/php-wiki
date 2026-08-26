<?php

namespace App\Services\Agent;

class EvidenceIdRegistry
{
    /** @var array<string, string> */
    private array $ids = [];

    private int $next = 1;

    public function idFor(string $identity): string
    {
        return $this->ids[$identity] ??= 'E'.$this->next++;
    }
}
