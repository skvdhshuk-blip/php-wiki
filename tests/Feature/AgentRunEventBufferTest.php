<?php

namespace Tests\Feature;

use App\Repositories\Agent\AgentRunRepository;
use App\Services\Agent\AgentRunEventBuffer;
use HaoCode\Tools\ToolResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentRunEventBufferTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_coalesces_text_hides_thinking_content_and_records_tool_duration(): void
    {
        $runs = app(AgentRunRepository::class);
        $run = $runs->createQueued('query', 'question');
        $buffer = new AgentRunEventBuffer($runs, $run);

        $buffer->turnStarted(1);
        $buffer->thinking('private chain of thought');
        $buffer->thinking('more private chain of thought');
        foreach (str_split(str_repeat('x', 600), 10) as $delta) {
            $buffer->text($delta);
        }
        $buffer->toolStarted('ReadWikiPage', ['path' => 'wiki/index.md']);
        $buffer->toolCompleted('ReadWikiPage', ToolResult::success('# Index'), '# Index');
        $buffer->flush();

        $events = $run->events()->get();
        $this->assertCount(1, $events->where('type', 'thinking_started'));
        $this->assertCount(3, $events->where('type', 'text_delta'));
        $this->assertStringNotContainsString(
            'private chain of thought',
            json_encode($events->toArray(), JSON_THROW_ON_ERROR),
        );

        $completion = $events->firstWhere('type', 'tool_completed');
        $this->assertNotNull($completion);
        $this->assertFalse($completion->payloadData()['is_error']);
        $this->assertGreaterThanOrEqual(0, $completion->payloadData()['duration_ms']);
    }
}
