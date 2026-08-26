<?php

namespace App\Services\Agent;

use App\Models\AgentRun;
use App\Repositories\Agent\AgentRunRepository;
use HaoCode\Tools\ToolResult;

class AgentRunEventBuffer
{
    private const TEXT_FLUSH_BYTES = 256;

    private const TEXT_FLUSH_NANOSECONDS = 100_000_000;

    private string $text = '';

    private int|float|null $textStartedAt = null;

    private int $turn = 0;

    private bool $thinkingRecorded = false;

    /** @var array<string, list<array{at: int|float, call_id: string}>> */
    private array $toolStartedAt = [];

    private int $fallbackToolSequence = 0;

    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly AgentRun $run,
    ) {}

    public function text(string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->textStartedAt ??= hrtime(true);
        $this->text .= $delta;
        if (strlen($this->text) >= self::TEXT_FLUSH_BYTES
            || hrtime(true) - $this->textStartedAt >= self::TEXT_FLUSH_NANOSECONDS) {
            $this->flushText();
        }
    }

    public function thinking(string $delta): void
    {
        if ($delta === '' || $this->thinkingRecorded) {
            return;
        }

        $this->thinkingRecorded = true;
        $this->runs->event($this->run, 'thinking_started', ['turn' => $this->turn]);
    }

    public function turnStarted(int $turn): void
    {
        $this->flushText();
        $this->turn = $turn;
        $this->thinkingRecorded = false;
        $this->runs->event($this->run, 'turn_started', ['turn' => $turn]);
    }

    /** @param array<string, mixed> $input */
    public function toolStarted(string $name, array $input, ?string $callId = null): void
    {
        $this->flushText();
        $callId ??= $this->run->uuid.':event-tool:'.(++$this->fallbackToolSequence);
        $this->toolStartedAt[$name][] = ['at' => hrtime(true), 'call_id' => $callId];
        $this->runs->event($this->run, 'tool_started', [
            'call_id' => $callId,
            'name' => $name,
            'input' => $input,
        ]);
    }

    public function toolCompleted(
        string $name,
        ToolResult $result,
        string $outputPreview,
        ?string $callId = null,
    ): void {
        $this->flushText();
        $started = isset($this->toolStartedAt[$name])
            ? array_shift($this->toolStartedAt[$name])
            : null;
        if (($this->toolStartedAt[$name] ?? []) === []) {
            unset($this->toolStartedAt[$name]);
        }

        $this->runs->event($this->run, 'tool_completed', [
            'call_id' => $callId ?? $started['call_id'] ?? null,
            'name' => $name,
            'is_error' => $result->isError,
            'duration_ms' => $started === null
                ? null
                : max(0, (int) round((hrtime(true) - $started['at']) / 1_000_000)),
            'output_preview' => $outputPreview,
        ]);
    }

    public function flush(): void
    {
        $this->flushText();
    }

    private function flushText(): void
    {
        if ($this->text === '') {
            return;
        }

        $text = $this->text;
        $this->text = '';
        $this->textStartedAt = null;
        $this->runs->event($this->run, 'text_delta', ['text' => $text]);
    }
}
