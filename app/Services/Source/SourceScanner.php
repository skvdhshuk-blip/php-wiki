<?php

namespace App\Services\Source;

use App\Repositories\Source\SourceRepository;
use App\Services\Wiki\WikiPathGuard;

class SourceScanner
{
    public function __construct(
        private readonly WikiPathGuard $paths,
        private readonly SourceRepository $sources,
    ) {}

    /** @return array{discovered: int, changed: int, missing: int, skipped: int} */
    public function scan(): array
    {
        $rawRoot = $this->paths->root().DIRECTORY_SEPARATOR.'raw';
        if (! is_dir($rawRoot)) {
            return ['discovered' => 0, 'changed' => 0, 'missing' => 0, 'skipped' => 0];
        }

        $supported = array_flip(config('phpwiki.supported_extensions'));
        $seen = [];
        $changed = 0;
        $skipped = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rawRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                $skipped++;

                continue;
            }

            $extension = strtolower($file->getExtension());
            if (! isset($supported[$extension])) {
                $skipped++;

                continue;
            }

            $relative = 'raw/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($rawRoot) + 1));
            try {
                $relative = $this->paths->assertRawPath($relative);
            } catch (\InvalidArgumentException) {
                $skipped++;

                continue;
            }

            $sha256 = hash_file('sha256', $file->getPathname());
            if ($sha256 === false) {
                $skipped++;

                continue;
            }

            $previous = $this->sources->findByPath($relative)?->sha256;
            $this->sources->recordScan(
                path: $relative,
                type: $this->typeFor($extension),
                sha256: $sha256,
                size: (int) $file->getSize(),
                mtime: (int) $file->getMTime(),
            );
            $seen[] = $relative;
            if ($previous !== $sha256) {
                $changed++;
            }
        }

        $missing = $this->sources->markMissingExcept($seen);

        return [
            'discovered' => count($seen),
            'changed' => $changed,
            'missing' => $missing,
            'skipped' => $skipped,
        ];
    }

    private function typeFor(string $extension): string
    {
        return match ($extension) {
            'md' => 'markdown',
            'txt' => 'text',
            'html', 'htm' => 'html',
            'pdf' => 'pdf',
            default => 'image',
        };
    }
}
