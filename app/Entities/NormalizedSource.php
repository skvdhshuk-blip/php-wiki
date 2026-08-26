<?php

namespace App\Entities;

final readonly class NormalizedSource
{
    /**
     * @param  list<string>  $images
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $text,
        public array $images = [],
        public array $warnings = [],
    ) {}

    public function hasVisuals(): bool
    {
        return $this->images !== [];
    }
}
