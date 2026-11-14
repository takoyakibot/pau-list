<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneExpiredData extends Command
{
    protected $signature = 'app:prune-expired';

    protected $description = '保持期限切れのデータを削除する（4.3）';

    public function handle(): int
    {
        $this->info('削除対象の処理は未実装です（Phase 1 以降）');

        return self::SUCCESS;
    }
}
