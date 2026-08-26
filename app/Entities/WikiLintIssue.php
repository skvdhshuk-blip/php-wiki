<?php

namespace App\Entities;

final readonly class WikiLintIssue
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $path,
        public string $message,
    ) {}

    /** @return array{severity: string, code: string, path: string, message: string} */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'code' => $this->code,
            'path' => $this->path,
            'message' => $this->message,
        ];
    }
}
