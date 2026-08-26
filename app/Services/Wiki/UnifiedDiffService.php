<?php

namespace App\Services\Wiki;

class UnifiedDiffService
{
    public function make(string $before, string $after, string $path): string
    {
        if ($before === $after) {
            return "--- {$path}\n+++ {$path}\n(no changes)";
        }

        $old = preg_split('/\R/', $before) ?: [];
        $new = preg_split('/\R/', $after) ?: [];
        $prefix = 0;
        while (isset($old[$prefix], $new[$prefix]) && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }

        $oldSuffix = count($old) - 1;
        $newSuffix = count($new) - 1;
        while ($oldSuffix >= $prefix && $newSuffix >= $prefix && $old[$oldSuffix] === $new[$newSuffix]) {
            $oldSuffix--;
            $newSuffix--;
        }

        $contextStart = max(0, $prefix - 3);
        $contextEndOld = min(count($old) - 1, $oldSuffix + 3);
        $contextEndNew = min(count($new) - 1, $newSuffix + 3);
        $lines = ["--- a/{$path}", "+++ b/{$path}", '@@'];
        for ($i = $contextStart; $i < $prefix; $i++) {
            $lines[] = ' '.$old[$i];
        }
        for ($i = $prefix; $i <= $oldSuffix; $i++) {
            $lines[] = '-'.$old[$i];
        }
        for ($i = $prefix; $i <= $newSuffix; $i++) {
            $lines[] = '+'.$new[$i];
        }
        $suffixLength = min($contextEndOld - $oldSuffix, $contextEndNew - $newSuffix);
        for ($i = 1; $i <= $suffixLength; $i++) {
            $lines[] = ' '.$old[$oldSuffix + $i];
        }

        return implode("\n", $lines);
    }
}
