<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArchitectureBoundaryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function applicationLayerDirectories(): iterable
    {
        yield 'Livewire' => ['app/Livewire'];
        yield 'Jobs' => ['app/Jobs'];
        yield 'Application services' => ['app/Services/Application'];
        yield 'Agent workflows' => ['app/Services/Agent'];
    }

    #[DataProvider('applicationLayerDirectories')]
    public function test_application_layers_do_not_query_eloquent_or_database_facades_directly(string $directory): void
    {
        $violations = [];
        foreach ($this->phpFiles(base_path($directory)) as $file) {
            $content = file_get_contents($file);
            $this->assertNotFalse($content);
            if (preg_match('/::query\s*\(|\bDB::|->(?:fresh|refresh|load|loadMissing)\s*\(/', $content)) {
                $violations[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $violations, '数据访问必须收敛到 Repository/Store。');
    }

    public function test_octane_composition_root_does_not_register_mutable_agent_singletons(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertNotFalse($provider);
        $this->assertDoesNotMatchRegularExpression('/singleton\s*\([^\n]*(Agent|Runner|Provider)/', $provider);
        $this->assertStringNotContainsString('SdkRuntime', $provider);
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
