<?php

use App\Livewire\AgentChatPage;
use App\Livewire\DashboardPage;
use App\Livewire\LintHealthPage;
use App\Livewire\ProposalsPage;
use App\Livewire\RunsPage;
use App\Livewire\SourcesPage;
use App\Livewire\SystemStatusPage;
use App\Livewire\WikiBrowserPage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('chat');
    }

    return User::query()->exists()
        ? redirect()->route('login')
        : redirect()->route('register');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::livewire('chat', AgentChatPage::class)->name('chat');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::livewire('/', DashboardPage::class)->name('dashboard');
        Route::livewire('sources', SourcesPage::class)->name('sources');
        Route::livewire('wiki', WikiBrowserPage::class)->name('wiki');
        Route::livewire('proposals', ProposalsPage::class)->name('proposals');
        Route::livewire('runs', RunsPage::class)->name('runs');
        Route::livewire('lint', LintHealthPage::class)->name('lint');
        Route::livewire('system', SystemStatusPage::class)->name('system');
    });

    foreach ([
        'dashboard' => 'admin.dashboard',
        'sources' => 'admin.sources',
        'wiki' => 'admin.wiki',
        'proposals' => 'admin.proposals',
        'runs' => 'admin.runs',
        'lint' => 'admin.lint',
        'system' => 'admin.system',
    ] as $legacyPath => $adminRoute) {
        Route::get($legacyPath, static fn (Request $request) => redirect()->route($adminRoute, $request->query()))
            ->name($legacyPath);
    }
});

require __DIR__.'/settings.php';
