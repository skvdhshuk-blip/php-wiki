<?php

namespace App\Console\Commands;

use App\Services\Application\DoctorService;
use App\Services\Wiki\WikiPathGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;

class PhpWikiDoctorCommand extends Command
{
    protected $signature = 'php-wiki:doctor {--live : Call the configured model with an image and tool}';

    protected $description = 'Check local runtime and optionally the live visual Agent contract';

    public function handle(WikiPathGuard $paths, DoctorService $doctor): int
    {
        $finder = new ExecutableFinder;
        $checks = [
            'wiki_root_absolute' => str_starts_with($paths->root(), DIRECTORY_SEPARATOR),
            'sqlite_fts5' => $this->hasFts5(),
            'git' => $finder->find('git') !== null,
            'pdftotext' => $finder->find('pdftotext') !== null,
            'pdftoppm' => $finder->find('pdftoppm') !== null,
            'ffmpeg' => $finder->find('ffmpeg') !== null,
            'gd' => extension_loaded('gd'),
        ];
        foreach ($checks as $name => $passed) {
            $this->line(($passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>')." {$name}");
        }

        if (in_array(false, $checks, true)) {
            return self::FAILURE;
        }

        if ($this->option('live')) {
            try {
                $result = $doctor->live();
            } catch (\Throwable $exception) {
                $this->error('Live visual Agent contract failed: '.$exception->getMessage());

                return self::FAILURE;
            }
            $this->info('Live visual Agent contract passed.');
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }

    private function hasFts5(): bool
    {
        try {
            DB::select('SELECT fts5(?)', ['probe']);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
