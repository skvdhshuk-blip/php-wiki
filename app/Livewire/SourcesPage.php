<?php

namespace App\Livewire;

use App\Models\WikiSource;
use App\Repositories\Source\SourceRepository;
use App\Services\Application\AgentRunDispatchService;
use App\Services\Source\SourceScanner;
use App\Services\Wiki\WorkspaceInitializer;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('本地来源')]
class SourcesPage extends Component
{
    public string $filter = '';

    /** @return Collection<int, WikiSource> */
    #[Computed]
    public function sources(): Collection
    {
        return app(SourceRepository::class)->listed($this->filter);
    }

    public function scan(WorkspaceInitializer $initializer, SourceScanner $scanner): void
    {
        $initializer->initialize();
        $result = $scanner->scan();
        unset($this->sources);
        Flux::toast(variant: 'success', text: "扫描完成：{$result['discovered']} 个来源，{$result['changed']} 个变化");
    }

    public function ingest(int $sourceId, AgentRunDispatchService $dispatch, SourceRepository $sources): void
    {
        $source = $sources->find($sourceId);
        $run = $dispatch->ingest($source);
        Flux::toast(variant: 'success', text: "已入队：{$run->uuid}");
    }

    public function ingestAll(AgentRunDispatchService $dispatch, SourceRepository $sources): void
    {
        $count = 0;
        $sources->pending()->each(function (WikiSource $source) use ($dispatch, &$count): void {
            $dispatch->ingest($source);
            $count++;
        });
        Flux::toast(variant: 'success', text: "已顺序入队 {$count} 个来源");
    }

    public function render(): View
    {
        return view('livewire.sources-page');
    }
}
