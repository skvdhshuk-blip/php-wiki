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
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return User::query()->exists()
        ? redirect()->route('login')
        : redirect()->route('register');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::livewire('dashboard', DashboardPage::class)->name('dashboard');
    Route::livewire('sources', SourcesPage::class)->name('sources');
    Route::livewire('wiki', WikiBrowserPage::class)->name('wiki');
    Route::livewire('chat', AgentChatPage::class)->name('chat');
    Route::livewire('proposals', ProposalsPage::class)->name('proposals');
    Route::livewire('runs', RunsPage::class)->name('runs');
    Route::livewire('lint', LintHealthPage::class)->name('lint');
    Route::livewire('system', SystemStatusPage::class)->name('system');
});

require __DIR__.'/settings.php';
