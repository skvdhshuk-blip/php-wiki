<?php

namespace App\Livewire;

use App\Services\Wiki\GitWorkspaceService;
use App\Services\Wiki\WikiPathGuard;
use Composer\InstalledVersions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('系统状态')]
class SystemStatusPage extends Component
{
    public function render(WikiPathGuard $paths, GitWorkspaceService $git): View
    {
        return view('livewire.system-status-page', [
            'status' => [
                'Laravel' => app()->version(),
                'PHP' => PHP_VERSION,
                'Livewire' => InstalledVersions::getPrettyVersion('livewire/livewire'),
                'Octane' => InstalledVersions::getPrettyVersion('laravel/octane'),
                'Hao Code' => InstalledVersions::getPrettyVersion('sk-wang/hao-code'),
                '模型' => config('phpwiki.model.name'),
                '文本回退' => config('phpwiki.model.text_fallback'),
                'Provider' => config('phpwiki.model.provider'),
                'Base URL' => config('phpwiki.model.base_url'),
                '远程模型授权' => config('phpwiki.allow_remote_model') ? '已授权' : '未授权',
                'API Key' => config('phpwiki.model.api_key') ? '已配置（已隐藏）' : '未配置',
                'Wiki Root' => $paths->root(),
                'Git HEAD' => $git->head() ?? '未初始化',
            ],
        ]);
    }
}
