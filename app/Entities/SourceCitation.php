<?php

namespace App\Entities;

final readonly class SourceCitation
{
    public function __construct(
        public string $path,
        public string $sha256,
        public string $locator,
    ) {}
}
