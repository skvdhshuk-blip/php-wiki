<?php

namespace App\Services\Agent\Tools;

class DoctorPingTool extends WikiSdkTool
{
    public function name(): string
    {
        return 'DoctorPing';
    }

    public function description(): string
    {
        return 'Return a harmless diagnostic acknowledgement. Call exactly once during a live doctor check.';
    }

    public function parameters(): array
    {
        return [
            'value' => ['type' => 'string', 'description' => 'Diagnostic value', 'required' => true],
        ];
    }

    public function handle(array $input): string
    {
        return 'pong:'.(string) ($input['value'] ?? '');
    }

    /** @param array<string, mixed> $input */
    public function isReadOnly(array $input): bool
    {
        return true;
    }
}
