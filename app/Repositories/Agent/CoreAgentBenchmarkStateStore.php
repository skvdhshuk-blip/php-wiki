<?php

namespace App\Repositories\Agent;

use Illuminate\Support\Facades\DB;

class CoreAgentBenchmarkStateStore
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function rollbackOnly(callable $callback): mixed
    {
        $initialTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();

        try {
            return $callback();
        } finally {
            while (DB::transactionLevel() > $initialTransactionLevel) {
                DB::rollBack();
            }
        }
    }
}
