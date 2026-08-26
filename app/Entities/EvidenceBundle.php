<?php

namespace App\Entities;

final readonly class EvidenceBundle
{
    /**
     * @param  list<EvidenceItem>  $items
     * @param  array<string, 'covered'|'gap'|'conflict'>  $coverage
     * @param  list<string>  $gaps
     * @param  list<string>  $conflicts
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $items,
        public array $coverage,
        public array $gaps = [],
        public array $conflicts = [],
        public array $warnings = [],
    ) {}

    public function find(string $evidenceId): ?EvidenceItem
    {
        foreach ($this->items as $item) {
            if ($item->evidenceId === $evidenceId) {
                return $item;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function evidenceIds(): array
    {
        return array_map(static fn (EvidenceItem $item): string => $item->evidenceId, $this->items);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (EvidenceItem $item): array => $item->toArray(), $this->items),
            'coverage' => $this->coverage,
            'gaps' => $this->gaps,
            'conflicts' => $this->conflicts,
            'warnings' => $this->warnings,
        ];
    }
}
