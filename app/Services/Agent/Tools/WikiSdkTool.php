<?php

namespace App\Services\Agent\Tools;

use HaoCode\Sdk\SdkTool;

abstract class WikiSdkTool extends SdkTool
{
    /**
     * Laravel services may own SQLite, cURL, or container state that is not
     * safe to inherit after pcntl_fork(). Wiki tools always stay in-process.
     *
     * @param  array<string, mixed>  $input
     */
    final public function isConcurrencySafe(array $input): bool
    {
        return false;
    }
}
