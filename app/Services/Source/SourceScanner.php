<?php

namespace App\Services\Source;

use App\Repositories\Source\SourceRepository;

class SourceScanner
{
    public function __construct(
        private readonly SourceCatalog $catalog,
        private readonly SourceRepository $sources,
    ) {}

    /** @return array{discovered: int, changed: int, missing: int, skipped: int} */
    public function scan(): array
    {
        $supported = array_flip(config('phpwiki.supported_extensions'));
        $seen = [];
        $changed = 0;
        $skipped = 0;
        foreach ($this->catalog->roots() as $logicalRoot => $absoluteRoot) {
            if (! is_dir($absoluteRoot)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
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

                $relative = $logicalRoot.'/'.str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    substr($file->getPathname(), strlen($absoluteRoot) + 1),
                );
                try {
                    $relative = $this->catalog->assertSourcePath($relative);
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
