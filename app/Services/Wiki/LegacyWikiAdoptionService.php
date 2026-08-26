<?php

namespace App\Services\Wiki;

use App\Models\WikiProposal;
use App\Repositories\Wiki\ProposalRepository;

class LegacyWikiAdoptionService
{
    public function __construct(
        private readonly WikiWorkspace $workspace,
        private readonly WikiPathGuard $paths,
        private readonly ProposalRepository $proposals,
        private readonly ChangeSetValidator $validator,
    ) {}

    public function propose(): ?WikiProposal
    {
        $files = $this->workspace->markdownFiles();
        $numbered = $this->numberedPages($files);
        $changes = [];
        foreach ($files as $path) {
            $content = $this->workspace->read($path);
            $adopted = $this->repairShortLinks($content, $numbered);
            $adopted = $this->ensureSchema($path, $adopted);
            if ($adopted !== $content) {
                $changes[$path] = $adopted;
            }
        }
        if ($this->workspace->exists('AGENTS.md')) {
            $content = $this->workspace->read('AGENTS.md');
            $adopted = $this->ensureSchema('AGENTS.md', $content);
            if (! str_contains($adopted, $this->workspace->schemaContractSection())) {
                $adopted = preg_replace(
                    '/^updated:\s*.*$/m',
                    'updated: '.now()->toDateString(),
                    $adopted,
                    1,
                ) ?? $adopted;
                $adopted = rtrim($adopted)."\n\n".$this->workspace->schemaContractSection()."\n";
            }
            if ($adopted !== $content) {
                $changes['AGENTS.md'] = $adopted;
            }
        }

        if ($changes === []) {
            return null;
        }

        $proposal = $this->proposals->createDraft(null, '接管既有 Obsidian Wiki Schema 与短链接');
        foreach ($changes as $path => $content) {
            $this->proposals->putPage(
                $proposal,
                $path,
                $content,
                $this->workspace->sha256($path),
                '将既有页面迁入统一 Wiki Schema；不修改任何 Source Catalog 文件。',
            );
        }
        $proposal = $this->proposals->reloadWithChanges($proposal);
        $this->proposals->setValidation($proposal, $this->validator->validate($proposal));

        return $this->proposals->reloadWithChanges($proposal);
    }

    /**
     * @param  list<string>  $files
     * @return array<string, string>
     */
    private function numberedPages(array $files): array
    {
        $numbered = [];
        foreach ($files as $path) {
            if (preg_match('#^wiki/(\d{2})-[^/]+\.md$#u', $path, $match) !== 1) {
                continue;
            }
            $key = 'page-'.$match[1];
            $numbered[$key] = isset($numbered[$key]) ? '' : preg_replace('/\.md$/', '', $path);
        }

        return array_filter($numbered);
    }

    /** @param array<string, string> $numbered */
    private function repairShortLinks(string $content, array $numbered): string
    {
        return preg_replace_callback(
            '/\[\[wiki\/(\d{2})((?:#[^\]|]+)?(?:\|[^\]]+)?)\]\]/u',
            static function (array $match) use ($numbered): string {
                $target = $numbered['page-'.$match[1]] ?? null;

                return is_string($target) ? '[['.$target.$match[2].']]' : $match[0];
            },
            $content,
        ) ?? $content;
    }

    private function ensureSchema(string $path, string $content): string
    {
        if (! preg_match('/\A---\R(.*?)\R---\R/s', $content, $match)) {
            return "---\ntype: ".$this->typeFor($path)."\nstatus: active\nupdated: ".$this->updatedDate($path)."\n---\n\n".$content;
        }

        $frontmatter = $match[1];
        $additions = [];
        if (preg_match('/^type:/m', $frontmatter) !== 1) {
            $additions[] = 'type: '.$this->typeFor($path);
        }
        if (preg_match('/^status:/m', $frontmatter) !== 1) {
            $additions[] = 'status: active';
        }
        if (preg_match('/^updated:/m', $frontmatter) !== 1) {
            $additions[] = 'updated: '.$this->updatedDate($path);
        }
        if ($additions === []) {
            return $content;
        }

        $replacement = "---\n".rtrim($frontmatter)."\n".implode("\n", $additions)."\n---\n";

        return $replacement.substr($content, strlen($match[0]));
    }

    private function typeFor(string $path): string
    {
        return match ($path) {
            'AGENTS.md' => 'schema/llm-wiki',
            'wiki/index.md' => 'wiki/index',
            'wiki/log.md' => 'wiki/log',
            default => 'wiki/topic',
        };
    }

    private function updatedDate(string $path): string
    {
        $mtime = filemtime($this->paths->absolute($path));

        return date('Y-m-d', $mtime === false ? time() : $mtime);
    }
}
