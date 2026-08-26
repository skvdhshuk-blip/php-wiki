<?php

namespace App\Console\Commands;

use App\Jobs\QueryWikiJob;
use App\Repositories\Agent\AgentRunRepository;
use App\Repositories\Agent\QueryRunStore;
use App\Repositories\Chat\ChatRepository;
use App\Services\Agent\CoreAgentAcceptanceCorpus;
use App\Services\Agent\CoreAgentBenchmarkEvaluator;
use App\Services\Agent\CoreAgentBenchmarkObserver;
use App\Services\Agent\CoreAgentBenchmarkWorkspace;
use App\Services\Agent\QueryWikiWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PhpWikiBenchmarkCoreAgentCommand extends Command
{
    protected $signature = 'php-wiki:benchmark-core-agent
        {--live : Execute real model runs instead of only validating the corpus}
        {--limit=0 : Limit live runs; zero executes the complete 50-question corpus}
        {--workspace=fixture : fixture uses the isolated acceptance knowledge base; configured uses PHP_WIKI_ROOT}
        {--output= : JSON report path}';

    protected $description = 'Validate or execute the evidence-first Core Agent acceptance corpus';

    public function handle(
        CoreAgentAcceptanceCorpus $corpus,
        CoreAgentBenchmarkEvaluator $evaluator,
        CoreAgentBenchmarkObserver $observer,
        CoreAgentBenchmarkWorkspace $benchmarkWorkspace,
        QueryRunStore $queryRuns,
        ChatRepository $chats,
        QueryWikiWorkflow $workflow,
        AgentRunRepository $runs,
    ): int {
        $entries = $corpus->all();
        if (! $this->option('live')) {
            $manifest = $benchmarkWorkspace->within(
                static fn (): array => $benchmarkWorkspace->manifest(),
            );
            $this->components->info(sprintf(
                'Core Agent benchmark valid: 50 questions, 5 balanced categories, bilingual and visual cases, %d raw and %d Wiki fixtures.',
                $manifest['raw_files'],
                $manifest['wiki_files'],
            ));

            return self::SUCCESS;
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $entries = array_slice($entries, 0, $limit);
        }
        $workspaceMode = trim((string) $this->option('workspace'));
        if (! in_array($workspaceMode, ['fixture', 'configured'], true)) {
            $this->components->error('--workspace must be fixture or configured.');

            return self::INVALID;
        }
        $execute = function () use ($entries, $chats, $queryRuns, $workflow, $runs, $observer, $evaluator): array {
            $thread = $chats->createThread();
            $observations = [];
            foreach ($entries as $index => $entry) {
                $this->line(sprintf('[%d/%d] %s', $index + 1, count($entries), $entry['id']));
                $run = $queryRuns->create($thread, $entry['question']);
                (new QueryWikiJob($run->id))->handle($workflow, $runs);
                $observations[] = $observer->observe($entry, $runs->withDetails($run->id) ?? $run->fresh());
            }

            return $evaluator->evaluate($entries, $observations);
        };
        $report = $workspaceMode === 'fixture'
            ? $benchmarkWorkspace->within(static fn (): array => $execute())
            : $execute();
        $report['generated_at'] = now()->toIso8601String();
        $report['model'] = config('phpwiki.model.name');
        $report['scope'] = $limit > 0 ? 'smoke' : 'full';
        $report['workspace'] = $workspaceMode;
        if ($workspaceMode === 'fixture') {
            $report['fixture'] = $benchmarkWorkspace->manifest();
        }
        $path = $this->reportPath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n");
        $this->line("Report: {$path}");
        $this->components->{$report['passed'] ? 'info' : 'error'}(
            $report['passed'] ? 'Core Agent acceptance passed.' : 'Core Agent acceptance failed closed.',
        );

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }

    private function reportPath(): string
    {
        $configured = trim((string) $this->option('output'));
        if ($configured !== '') {
            return str_starts_with($configured, DIRECTORY_SEPARATOR)
                ? $configured
                : base_path($configured);
        }

        return storage_path('app/core-agent-benchmarks/core-agent-'.now()->format('Ymd-His').'.json');
    }
}
