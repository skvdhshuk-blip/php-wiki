<?php

namespace App\Services\Wiki;

use Illuminate\Support\Str;

class WikiMarkdownRenderer
{
    public function render(string $markdown): string
    {
        $markdown = preg_replace_callback(
            '/\[\[page:([^\]]+)\]\]/',
            static fn (array $match): string => '['.$match[1].']('.route('admin.wiki', ['path' => $match[1]], false).')',
            $markdown,
        ) ?? $markdown;
        $markdown = preg_replace_callback(
            '/\[\[source:([^\]]+)\]\]/',
            static fn (array $match): string => '`source:'.$match[1].'`',
            $markdown,
        ) ?? $markdown;

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
