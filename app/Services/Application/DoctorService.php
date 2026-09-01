<?php

namespace App\Services\Application;

use App\Services\Agent\ModelAccessPolicy;
use App\Services\Agent\PromptRepository;
use App\Services\Agent\Tools\DoctorPingTool;
use App\Services\Wiki\WikiPathGuard;
use HaoCode\Contracts\RunTerminationReason;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\Runner;
use HaoCode\Sdk\RunOptions;
use HaoCode\Tools\ToolResult;
use RuntimeException;

class DoctorService
{
    public function __construct(
        private readonly ModelAccessPolicy $modelAccess,
        private readonly WikiPathGuard $paths,
        private readonly PromptRepository $prompts,
    ) {}

    /** @return array<string, mixed> */
    public function live(): array
    {
        $this->modelAccess->assertAllowed();
        $image = storage_path('app/private/php-wiki-doctor.jpg');
        $this->makeImage($image);
        $started = [];
        $completed = [];
        $tool = new DoctorPingTool;
        $apiKey = trim((string) config('phpwiki.model.api_key'));
        $agent = new Agent(
            name: 'php-wiki-doctor',
            model: (string) config('phpwiki.model.name'),
            apiKey: $apiKey,
            baseUrl: (string) config('phpwiki.model.base_url'),
            providerType: (string) config('phpwiki.model.provider'),
            maxTokens: 1024,
            maxTurns: 4,
            systemPrompt: $this->prompts->get('doctor-system'),
            permissionMode: 'bypass_permissions',
            allowedTools: [$tool->name()],
            tools: [$tool],
            contextPreset: 'generic',
        );

        try {
            $result = Runner::run($agent, $this->prompts->get('doctor-request'), new RunOptions(
                onToolStart: static function (string $name) use (&$started): void {
                    $started[] = $name;
                },
                onToolComplete: static function (string $name, ToolResult $result) use (&$completed): void {
                    $completed[$name] = ! $result->isError;
                },
                images: [$image],
                cwd: $this->paths->root(),
            ));
        } finally {
            @unlink($image);
        }

        if ($result->terminationReason !== RunTerminationReason::Normal
            || trim($result->text) === ''
            || ! in_array('DoctorPing', $started, true)
            || ($completed['DoctorPing'] ?? false) !== true) {
            throw new RuntimeException('Live doctor 未满足视觉、工具调用、正常终止和非空输出契约。');
        }

        return [
            'model' => config('phpwiki.model.name'),
            'termination_reason' => $result->terminationReason->value,
            'text' => $result->text,
            'tool_called' => true,
            'cost' => $result->cost,
        ];
    }

    private function makeImage(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $image = imagecreatetruecolor(96, 96);
        $magenta = imagecolorallocate($image, 220, 40, 180);
        $black = imagecolorallocate($image, 0, 0, 0);
        if ($magenta === false || $black === false) {
            imagedestroy($image);
            throw new RuntimeException('无法创建 doctor 测试图片颜色。');
        }
        imagefill($image, 0, 0, $magenta);
        imagesetthickness($image, 8);
        imageline($image, 8, 8, 88, 88, $black);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }
}
