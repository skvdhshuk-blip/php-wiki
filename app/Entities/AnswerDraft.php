<?php

namespace App\Entities;

final readonly class AnswerDraft
{
    /** @param list<AnswerSection> $sections */
    public function __construct(
        public string $type,
        public array $sections = [],
        public ?string $clarificationQuestion = null,
        public ?string $insufficientReason = null,
    ) {}
}
