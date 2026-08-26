<?php

namespace App\Services\Agent\Tools;

use App\Repositories\Source\SourceRepository;
use App\Services\Agent\QueryToolBudget;
use App\Services\Wiki\WikiPathGuard;

class ReadSourceExcerptTool extends WikiSdkTool
{
    public function __construct(
        private readonly WikiPathGuard $paths,
        private readonly SourceRepository $sources,
        private readonly ?QueryToolBudget $budget = null,
    ) {}

    public function name(): string
    {
        return 'ReadSourceExcerpt';
    }

    public function description(): string
    {
        return 'Read a bounded raw-source excerpt. Returns a JSON evidence candidate with path, SHA-256, locator, and numbered quote.';
    }

    public function parameters(): array
    {
        return [
            'path' => ['type' => 'string', 'description' => 'Registered raw/... path', 'required' => true],
            'start_line' => ['type' => 'integer', 'description' => 'First line, starting at 1', 'required' => true],
            'end_line' => ['type' => 'integer', 'description' => 'Last line, at most 200 lines later', 'required' => true],
        ];
    }

    public function handle(array $input): string
    {
        $this->budget?->admitRead();
        $path = $this->paths->assertRawPath((string) ($input['path'] ?? ''));
        $source = $this->sources->findByPath($path);
        if ($source === null) {
            throw new \InvalidArgumentException("Source is not registered: {$path}");
        }
        if (! in_array($source->type, ['markdown', 'text', 'html'], true)) {
            throw new \InvalidArgumentException('PDF and image sources are available only through evidence produced by VisionAnalystAgent.');
        }

        $start = max(1, (int) ($input['start_line'] ?? 1));
        $end = max($start, (int) ($input['end_line'] ?? $start));
        if ($end - $start > 199) {
            throw new \InvalidArgumentException('A source excerpt may contain at most 200 lines.');
        }

        $lines = file($this->paths->absolute($path), FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Unable to read source: {$path}");
        }

        $slice = array_slice($lines, $start - 1, $end - $start + 1, true);
        $numbered = [];
        foreach ($slice as $number => $line) {
            $numbered[] = ($number + 1).': '.$line;
        }

        return json_encode([
            'raw_path' => $path,
            'raw_sha256' => $source->sha256,
            'locator' => "lines:{$start}-{$end}",
            'quote' => implode("\n", $numbered),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $input */
    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
