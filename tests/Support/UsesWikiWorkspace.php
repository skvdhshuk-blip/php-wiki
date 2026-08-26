<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait UsesWikiWorkspace
{
    protected string $wikiRoot;

    protected function setUpWikiWorkspace(): void
    {
        $this->wikiRoot = storage_path('framework/testing/php-wiki-'.Str::uuid());
        File::makeDirectory($this->wikiRoot, 0755, true);
        config([
            'phpwiki.root' => $this->wikiRoot,
            'phpwiki.allow_remote_model' => false,
        ]);
    }

    protected function tearDownWikiWorkspace(): void
    {
        $testingRoot = realpath(storage_path('framework/testing'));
        $root = realpath($this->wikiRoot);
        if ($testingRoot !== false && $root !== false && str_starts_with($root, $testingRoot.DIRECTORY_SEPARATOR.'php-wiki-')) {
            File::deleteDirectory($root);
        }
    }
}
