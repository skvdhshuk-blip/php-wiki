<?php

namespace App\Entities;

final readonly class AnswerSection
{
    /** @param list<string> $evidenceIds */
    public function __construct(
        public string $heading,
        public string $content,
        public array $evidenceIds,
        public bool $inference,
        public string $confidence,
    ) {}
}
