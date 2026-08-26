<?php

namespace Tests\Feature;

use App\Services\Agent\WikiAgentFactory;
use App\Services\Wiki\WikiWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class WikiAgentFactoryTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
        app(WikiWorkspace::class)->initialize();
    }

    protected function tearDown(): void
    {
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_every_construction_returns_an_immutable_fresh_tool_set(): void
    {
        config(['phpwiki.model.name' => 'deepseek-v4-flash-vision-exp']);
        $first = app(WikiAgentFactory::class)->queryAgent();
        config(['phpwiki.model.name' => 'future-model']);
        $second = app(WikiAgentFactory::class)->queryAgent();

        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->tools[0], $second->tools[0]);
        $this->assertSame('deepseek-v4-flash-vision-exp', $first->model);
        $this->assertSame('future-model', $second->model);
        $this->assertSame(['SearchWiki', 'ReadWikiPage', 'ReadSourceExcerpt'], $first->allowedTools);
        $this->assertSame('bypass_permissions', $first->permissionMode);
        $this->assertSame('generic', $first->contextPreset);

        foreach ($first->tools as $tool) {
            $this->assertTrue($tool->isReadOnly([]));
            $this->assertFalse($tool->isConcurrencySafe([]));
        }
    }
}
