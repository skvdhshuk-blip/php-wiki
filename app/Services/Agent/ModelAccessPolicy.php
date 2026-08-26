<?php

namespace App\Services\Agent;

use RuntimeException;

class ModelAccessPolicy
{
    public function assertAllowed(): void
    {
        if (! config('phpwiki.allow_remote_model')) {
            throw new RuntimeException('远程模型访问未授权；请设置 PHP_WIKI_ALLOW_REMOTE_MODEL=true。');
        }
        if (trim((string) config('phpwiki.model.api_key')) === '') {
            throw new RuntimeException('PHP_WIKI_API_KEY 未配置。');
        }
    }
}
