<?php

namespace Tests\Unit;

use App\Entities\SourceCitation;
use App\Services\Wiki\SourceCitationCodec;
use PHPUnit\Framework\TestCase;

class SourceCitationCodecTest extends TestCase
{
    public function test_it_round_trips_the_canonical_source_citation(): void
    {
        $codec = new SourceCitationCodec;
        $hash = str_repeat('a', 64);
        $markdown = "[[source:raw/note.md|sha256:{$hash}|lines:2-4]]";

        $citation = $codec->parse($markdown);

        $this->assertSame('raw/note.md', $citation->path);
        $this->assertSame($hash, $citation->sha256);
        $this->assertSame('lines:2-4', $citation->locator);
        $this->assertSame($markdown, $codec->format($citation));
    }

    public function test_it_rejects_malformed_citations_and_counts_incomplete_markers(): void
    {
        $codec = new SourceCitationCodec;
        $content = 'bad [[source:raw/note.md|sha256:nope|lines:1-1]]';

        $this->assertSame(1, $codec->countMarkers($content));
        $this->assertSame([], $codec->all($content));

        $this->expectException(\InvalidArgumentException::class);
        $codec->parse($content);
    }

    public function test_formatter_rejects_reserved_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SourceCitationCodec)->format(new SourceCitation(
            'raw/a|b.md',
            str_repeat('a', 64),
            'lines:1-1',
        ));
    }
}
