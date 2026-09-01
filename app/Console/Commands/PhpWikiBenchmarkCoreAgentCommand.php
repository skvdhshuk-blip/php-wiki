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
use App\Services\Agent\PromptRepository;
use App\Services\Agent\QueryWikiWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class PhpWikiBenchmarkCoreAgentCommand extends Command
{
    protected $signature = 'php-wiki:benchmark-core-agent
        {--live : Execute real model runs instead of only validating the corpus}
        {--limit=0 : Limit live runs; zero executes the complete 50-question corpus}
        {--ids= : Execute a comma-separated fixed subset of corpus IDs}
        {--workspace=fixture : fixture uses the isolated acceptance knowledge base; configured uses PHP_WIKI_ROOT}
        {--output= : JSON report path}
        {--report-only : Always exit successfully; the report still records whether the gates passed}';

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
        PromptRepository $prompts,
    ): int {
        $completeCorpus = $corpus->all();
        $entries = $completeCorpus;
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
        $idsOption = trim((string) $this->option('ids'));
        if ($limit > 0 && $idsOption !== '') {
            $this->components->error('--limit and --ids cannot be used together.');

            return self::INVALID;
        }
        if ($idsOption !== '') {
            $requestedIds = array_values(array_unique(array_filter(array_map(
                'trim',
                explode(',', $idsOption),
            ))));
            $entriesById = array_column($entries, null, 'id');
            $unknownIds = array_values(array_diff($requestedIds, array_keys($entriesById)));
            if ($unknownIds !== []) {
                $this->components->error('Unknown corpus IDs: '.implode(', ', $unknownIds));

                return self::INVALID;
            }
            $entries = array_map(static fn (string $id): array => $entriesById[$id], $requestedIds);
        } elseif ($limit > 0) {
            $entries = array_slice($entries, 0, $limit);
        }
        $workspaceMode = trim((string) $this->option('workspace'));
        if (! in_array($workspaceMode, ['fixture', 'configured'], true)) {
            $this->components->error('--workspace must be fixture or configured.');

            return self::INVALID;
        }
        // 充分性门槛针对完整验收集，与本次跑了哪个子集无关：
        // 「验收集被删小了」和「回答变差了」都必须让 CI 变红。
        $execute = function () use ($entries, $completeCorpus, $chats, $queryRuns, $workflow, $runs, $observer, $evaluator): array {
            $thread = $chats->createThread();
            $observations = [];
            foreach ($entries as $index => $entry) {
                $this->line(sprintf('[%d/%d] %s', $index + 1, count($entries), $entry['id']));
                $run = $queryRuns->create($thread, $entry['question']);
                (new QueryWikiJob($run->id))->handle($workflow, $runs);
                $observations[] = $observer->observe($entry, $runs->withDetails($run->id) ?? $run->fresh());
            }

            return $evaluator->evaluate($entries, $observations, $completeCorpus);
        };
        $report = $workspaceMode === 'fixture'
            ? $benchmarkWorkspace->within(static fn (): array => $execute())
            : $execute();
        $report['generated_at'] = now()->toIso8601String();
        $report['model'] = config('phpwiki.model.name');
        $report['source'] = $this->sourceRevision();
        $report['prompt_version'] = $prompts->version();
        $report['scope'] = $idsOption !== '' ? 'subset' : ($limit > 0 ? 'smoke' : 'full');
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

        // 退出码区分「跑不起来」和「跑完但没过」：INVALID 留给用法错误，
        // FAILURE 表示门槛未通过。--report-only 只出报告不影响构建状态。
        if ($this->option('report-only')) {
            return self::SUCCESS;
        }

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 报告必须能追溯到具体代码状态，否则无法比较两次运行。
     *
     * @return array{commit: string|null, dirty: bool|null}
     */
    private function sourceRevision(): array
    {
        $commit = trim((string) (getenv('GITHUB_SHA') ?: ''));
        $dirty = null;

        if ($commit === '') {
            $head = $this->git(['rev-parse', 'HEAD']);
            $commit = $head === null ? '' : $head;
            $status = $this->git(['status', '--porcelain']);
            $dirty = $status === null ? null : $status !== '';
        }

        return ['commit' => $commit === '' ? null : $commit, 'dirty' => $dirty];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): ?string
    {
        $process = new Process(array_merge(['git'], $arguments), base_path());
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
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
