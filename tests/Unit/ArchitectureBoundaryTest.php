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

    public function test_the_contract_document_maps_every_clause_to_a_real_implementation(): void
    {
        $contract = file_get_contents(dirname(__DIR__, 2).'/AGENTS.md');

        $this->assertNotFalse($contract);
        $this->assertMatchesRegularExpression('/\| `app\/[^`]+\.php` \|/', $contract);

        preg_match_all('/`(app\/[^`]+\.php)`/', $contract, $matches);
        $this->assertNotSame([], $matches[1], '契约文档必须给出条款到实现的对应。');

        foreach (array_unique($matches[1]) as $path) {
            $this->assertFileExists(
                dirname(__DIR__, 2).'/'.$path,
                "AGENTS.md 指向了不存在的实现：{$path}",
            );
        }
    }

    public function test_model_system_prompts_are_not_embedded_in_application_code(): void
    {
        $violations = [];
        foreach ($this->phpFiles(app_path()) as $file) {
            $content = file_get_contents($file);
            $this->assertNotFalse($content);
            if (preg_match('/systemPrompt:\s*[\'\"]/', $content) === 1) {
                $violations[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $violations, '模型 system prompt 必须由 PromptRepository 读取。');
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
