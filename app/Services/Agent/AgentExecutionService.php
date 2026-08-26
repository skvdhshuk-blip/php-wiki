<?php

namespace App\Services\Agent;

use App\Entities\AgentInvocationOutcome;
use App\Exceptions\AgentContractException;
use App\Models\AgentRun;
use App\Repositories\Agent\AgentRunRepository;
use App\Services\Wiki\WikiPathGuard;
use HaoCode\Contracts\RunTerminationReason;
use HaoCode\Sdk\AbortController;
use HaoCode\Sdk\Agent;
use HaoCode\Sdk\QueryResult;
use HaoCode\Sdk\RunOptions;
use HaoCode\Tools\ToolResult;

class AgentExecutionService
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly WikiPathGuard $paths,
        private readonly AgentRunner $runner,
    ) {}

    /**
     * @param  list<string>  $images
     * @param  array<string, mixed>|null  $responseSchema
     */
    public function invoke(
        AgentRun $run,
        Agent $agent,
        string $prompt,
        array $images = [],
        bool $allowTextFallback = false,
        bool $emitText = true,
        ?array $responseSchema = null,
    ): AgentInvocationOutcome {
        try {
            $attempt = $this->runOnce($run, $agent, $prompt, $images, $emitText, $responseSchema);
            $result = $attempt->result;
            $this->runs->recordInvocation($run, $result);
            if ($this->acceptable($result)) {
                return $attempt;
            }
            if ($result->terminationReason === RunTerminationReason::Cancelled) {
                return $attempt;
            }

            throw new AgentContractException(
                "Agent result rejected: termination={$result->terminationReason->value}, text_bytes=".strlen($result->text),
            );
        } catch (\Throwable $primaryError) {
            if (! $allowTextFallback || $images !== [] || $this->runs->cancellationRequested($run)) {
                throw $primaryError;
            }

            $fallback = trim((string) config('phpwiki.model.text_fallback'));
            if ($fallback === '' || $fallback === $agent->model) {
                throw $primaryError;
            }

            $this->runs->event($run, 'fallback_started', [
                'from' => $agent->model,
                'to' => $fallback,
                'reason' => mb_substr($primaryError->getMessage(), 0, 500),
            ]);
            $this->runs->markFallback($run, $fallback);
            $attempt = $this->runOnce(
                $run,
                $agent->withModel($fallback),
                $prompt,
                [],
                $emitText,
                $responseSchema,
            );
            $result = $attempt->result;
            $this->runs->recordInvocation($run, $result);
            if (! $this->acceptable($result)) {
                throw new AgentContractException(
                    "Fallback result rejected: termination={$result->terminationReason->value}, text_bytes=".strlen($result->text),
                );
            }

            return new AgentInvocationOutcome($result, true, $attempt->toolInvocations);
        }
    }

    /**
     * @param  list<string>  $images
     * @param  array<string, mixed>|null  $responseSchema
     */
    private function runOnce(
        AgentRun $run,
        Agent $agent,
        string $prompt,
        array $images,
        bool $emitText,
        ?array $responseSchema,
    ): AgentInvocationOutcome {
        $abort = new AbortController;
        $events = new AgentRunEventBuffer($this->runs, $run);
        $trace = new AgentToolTrace(
            $run->uuid,
            $this->runs->events($run, ['tool_started'])->count(),
        );
        $checkCancellation = function () use ($run, $abort): void {
            if ($this->runs->cancellationRequested($run) && ! $abort->isAborted()) {
                $abort->abort();
            }
        };
        $checkCancellation();

        try {
            $result = $this->runner->run($agent, $prompt, new RunOptions(
                onText: function (string $delta) use ($events, $checkCancellation, $emitText): void {
                    $checkCancellation();
                    if ($emitText) {
                        $events->text($delta);
                    }
                },
                onThinking: function (string $delta) use ($events, $checkCancellation): void {
                    $checkCancellation();
                    $events->thinking($delta);
                },
                onToolStart: function (string $name, array $input) use ($events, $trace, $checkCancellation): void {
                    $checkCancellation();
                    $callId = $trace->started($name, $input);
                    $events->toolStarted($name, $this->safeToolInput($input), $callId);
                },
                onToolComplete: function (string $name, ToolResult $result) use ($events, $trace, $checkCancellation): void {
                    $checkCancellation();
                    $callId = $trace->completed($name, $result);
                    $events->toolCompleted($name, $result, $this->safeOutputPreview($result->output), $callId);
                },
                onTurnStart: function (int $turn) use ($events, $checkCancellation): void {
                    $checkCancellation();
                    $events->turnStarted($turn);
                },
                images: $images,
                ephemeral: true,
                responseSchema: $responseSchema,
                abortController: $abort,
                cwd: $this->paths->root(),
                maxBudgetUsd: config('phpwiki.model.max_budget_usd'),
            ));

            return new AgentInvocationOutcome($result, toolInvocations: $trace->completedInvocations());
        } finally {
            $events->flush();
        }
    }

    private function acceptable(QueryResult $result): bool
    {
        return $result->terminationReason === RunTerminationReason::Normal
            && trim($result->text) !== '';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function safeToolInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (preg_match('/content|api[_-]?key|token|secret|password|authorization|cookie/i', (string) $key) === 1) {
                $input[$key] = '<redacted '.(is_string($value) ? strlen($value) : 'structured').' bytes>';
            } elseif (is_array($value)) {
                $input[$key] = $this->safeToolInput($value);
            } elseif (is_string($value) && strlen($value) > 500) {
                $input[$key] = mb_substr($value, 0, 500).'…';
            }
        }

        return $input;
    }

    private function safeOutputPreview(string $output): string
    {
        $preview = mb_substr($output, 0, 500);

        return preg_replace(
            '/(api[_-]?key|token|secret|password|authorization)\s*[:=]\s*[^\s,;]+/iu',
            '$1=<redacted>',
            $preview,
        ) ?? $preview;
    }
}
