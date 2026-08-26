<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSurfaceSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_home_opens_the_consumer_chat_surface(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))->assertRedirect(route('chat'));

        $this->get(route('chat'))
            ->assertOk()
            ->assertSeeHtml('data-surface="consumer"')
            ->assertSee('管理后台')
            ->assertDontSeeHtml('data-surface="admin"')
            ->assertDontSee('本地来源');
    }

    public function test_admin_pages_use_the_admin_surface_and_canonical_prefix(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertSame('/admin', route('admin.dashboard', absolute: false));
        $this->assertSame('/admin/sources', route('admin.sources', absolute: false));

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeHtml('data-surface="admin"')
            ->assertSee('管理后台')
            ->assertSee('打开知识助手')
            ->assertDontSeeHtml('data-surface="consumer"');
    }

    public function test_legacy_admin_urls_redirect_without_losing_query_parameters(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertRedirect(route('admin.dashboard'));
        $this->get('/wiki?path=wiki%2Findex.md')
            ->assertRedirect(route('admin.wiki', ['path' => 'wiki/index.md']));
    }

    public function test_both_surfaces_require_authentication(): void
    {
        $this->get(route('chat'))->assertRedirect(route('login'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }
}
