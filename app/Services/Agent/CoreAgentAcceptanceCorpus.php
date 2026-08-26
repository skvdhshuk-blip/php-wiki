<?php

namespace App\Services\Agent;

class CoreAgentAcceptanceCorpus
{
    /**
     * @return list<array{
     *   id: string,
     *   category: string,
     *   language: string,
     *   question: string,
     *   expected: array<string, mixed>
     * }>
     */
    public function all(): array
    {
        $path = resource_path('core-agent/acceptance-corpus.json');
        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Core Agent acceptance corpus is invalid JSON.', 0, $exception);
        }
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new \RuntimeException('Core Agent acceptance corpus must be a JSON list.');
        }

        $entries = [];
        $ids = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)
                || ! is_string($entry['id'] ?? null)
                || ! is_string($entry['category'] ?? null)
                || ! is_string($entry['language'] ?? null)
                || ! is_string($entry['question'] ?? null)
                || ! is_array($entry['expected'] ?? null)) {
                throw new \RuntimeException('Core Agent acceptance corpus contains an invalid entry.');
            }
            if (isset($ids[$entry['id']])) {
                throw new \RuntimeException("Duplicate acceptance question ID: {$entry['id']}");
            }
            $ids[$entry['id']] = true;
            $entries[] = [
                'id' => $entry['id'],
                'category' => $entry['category'],
                'language' => $entry['language'],
                'question' => $entry['question'],
                'expected' => $entry['expected'],
            ];
        }

        $this->assertShape($entries);

        return $entries;
    }

    /** @param list<array{id: string, category: string, language: string, question: string, expected: array<string, mixed>}> $entries */
    private function assertShape(array $entries): void
    {
        if (count($entries) !== 50) {
            throw new \RuntimeException('Core Agent acceptance corpus must contain exactly 50 questions.');
        }

        $counts = array_count_values(array_column($entries, 'category'));
        foreach (['lookup', 'research', 'conflict', 'unknown', 'ambiguous'] as $category) {
            if (($counts[$category] ?? 0) !== 10) {
                throw new \RuntimeException("Acceptance category {$category} must contain exactly 10 questions.");
            }
        }
        $languages = array_count_values(array_column($entries, 'language'));
        if (($languages['zh'] ?? 0) === 0 || ($languages['en'] ?? 0) === 0) {
            throw new \RuntimeException('Acceptance corpus must contain Chinese and English questions.');
        }
        $visual = count(array_filter(
            $entries,
            static fn (array $entry): bool => ($entry['expected']['visual'] ?? false) === true
                && ($entry['expected']['answer_type'] ?? null) === 'answer'
                && in_array($entry['expected']['evidence_kind'] ?? null, ['image_region', 'page'], true),
        ));
        if ($visual < 5) {
            throw new \RuntimeException('Acceptance corpus must contain at least five visual-evidence questions.');
        }
    }
}
