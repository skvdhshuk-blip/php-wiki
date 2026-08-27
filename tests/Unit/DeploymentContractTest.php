<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class DeploymentContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function forwardedApplicationVariables(): iterable
    {
        foreach ([
            'PHP_WIKI_SOURCE_ROOTS',
            'PHP_WIKI_MAX_TURNS',
            'PHP_WIKI_MAX_TOKENS',
            'PHP_WIKI_MAX_BUDGET_USD',
            'PHP_WIKI_PDF_BATCH_SIZE',
            'PHP_WIKI_IMAGE_MAX_BYTES',
            'PHP_WIKI_IMAGE_MAX_EDGE',
        ] as $variable) {
            yield $variable => [$variable];
        }
    }

    #[DataProvider('forwardedApplicationVariables')]
    public function test_compose_forwards_declared_application_configuration(string $variable): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/compose.yaml');

        $this->assertNotFalse($compose);
        $this->assertMatchesRegularExpression(
            '/^\s{4}'.preg_quote($variable, '/').':\s+\$\{'.preg_quote($variable, '/').':-/m',
            $compose,
        );
    }

    public function test_ci_runtime_versions_and_compose_gate_match_the_project_contract(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/tests.yml');

        $this->assertNotFalse($workflow);
        $this->assertStringContainsString("php-version: '8.4'", $workflow);
        $this->assertStringContainsString("node-version: '24'", $workflow);
        $this->assertStringContainsString('docker compose config --quiet', $workflow);
    }

    public function test_every_container_receives_the_same_application_configuration_matrix(): void
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 2).'/compose.yaml');
        $this->assertIsArray($compose);

        foreach (['app', 'queue', 'scheduler', 'reverb', 'test'] as $service) {
            $environment = $compose['services'][$service]['environment'] ?? null;
            $this->assertIsArray($environment, "{$service} must define an environment matrix.");
            foreach (array_keys(iterator_to_array(self::forwardedApplicationVariables())) as $variable) {
                $this->assertArrayHasKey($variable, $environment, "{$service} does not receive {$variable}.");
            }
        }
    }

    public function test_unused_reverb_public_port_is_absent_from_application_config(): void
    {
        $config = file_get_contents(dirname(__DIR__, 2).'/config/phpwiki.php');

        $this->assertNotFalse($config);
        $this->assertStringNotContainsString("'reverb'", $config);
        $this->assertStringNotContainsString('public_port', $config);
    }

    public function test_setup_creates_sqlite_before_composer_discovers_packages(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $setup = $composer['scripts']['setup'] ?? null;

        $this->assertIsArray($setup);
        $installIndex = array_search('composer install', $setup, true);
        $databaseIndex = array_search(
            '@php -r "file_exists(\'database/database.sqlite\') || touch(\'database/database.sqlite\');"',
            $setup,
            true,
        );
        $this->assertNotFalse($installIndex);
        $this->assertNotFalse($databaseIndex);
        $this->assertLessThan($installIndex, $databaseIndex);
    }
}
