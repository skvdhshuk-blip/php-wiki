<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWikiWorkspace;
use Tests\TestCase;

class PhpWikiDoctorCommandTest extends TestCase
{
    use RefreshDatabase, UsesWikiWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWikiWorkspace();
    }

    protected function tearDown(): void
    {
        $this->tearDownWikiWorkspace();
        parent::tearDown();
    }

    public function test_live_doctor_fails_closed_before_contacting_model_without_consent(): void
    {
        config([
            'phpwiki.allow_remote_model' => false,
            'phpwiki.model.api_key' => null,
        ]);

        $this->artisan('php-wiki:doctor --live')
            ->expectsOutputToContain('Live visual Agent contract failed: 远程模型访问未授权')
            ->assertExitCode(1);
    }
}
