<?php

namespace App\Livewire;

use App\Repositories\Dashboard\DashboardReadRepository;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.admin')]
#[Title('总览')]
class DashboardPage extends Component
{
    public function render(): View
    {
        return view('livewire.dashboard-page', app(DashboardReadRepository::class)->summary());
    }
}
