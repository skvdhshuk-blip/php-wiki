<?php

namespace App\Services\Wiki;

use App\Entities\SourceCitation;

class SourceCitationCodec
{
    private const PATTERN = '/\[\[source:([^|\]]+)\|sha256:([a-f0-9]{64})\|([^\]]+)\]\]/i';

    public function parse(string $markdown): SourceCitation
    {
        $matches = $this->matches($markdown);
        if (count($matches) !== 1
            || $matches[0]['offset'] !== 0
            || $matches[0]['markdown'] !== $markdown) {
            throw new \InvalidArgumentException('source 引用格式无效。');
        }

        return $matches[0]['citation'];
    }

    /** @return list<SourceCitation> */
    public function all(string $content): array
    {
        return array_map(
            static fn (array $match): SourceCitation => $match['citation'],
            $this->matches($content),
        );
    }

    /**
     * @return list<array{citation: SourceCitation, markdown: string, offset: int}>
     */
    public function matches(string $content): array
    {
        preg_match_all(self::PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $match): array => [
            'citation' => new SourceCitation($match[1][0], strtolower($match[2][0]), $match[3][0]),
            'markdown' => $match[0][0],
            'offset' => $match[0][1],
        ], $matches);
    }

    public function countMarkers(string $content): int
    {
        return substr_count(strtolower($content), '[[source:');
    }

    public function format(SourceCitation $citation): string
    {
        if (str_contains($citation->path, '|') || str_contains($citation->path, ']')) {
            throw new \InvalidArgumentException('source 引用路径包含保留字符。');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $citation->sha256) !== 1) {
            throw new \InvalidArgumentException('source 引用 SHA-256 格式无效。');
        }
        if ($citation->locator === '' || str_contains($citation->locator, ']')) {
            throw new \InvalidArgumentException('source 引用 locator 格式无效。');
        }

        return "[[source:{$citation->path}|sha256:{$citation->sha256}|{$citation->locator}]]";
    }
}
