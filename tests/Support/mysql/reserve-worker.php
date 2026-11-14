<?php

/*
 * Child process for MySqlConcurrentReservationTest: reserves write quota for one creation through
 * QuotaLedger::withWriteLock() on its own connection and prints "ok" or "no".
 * Usage: php tests/Support/mysql/reserve-worker.php {creationId} {units}
 */

use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\WriteLock;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$creationId = (int) ($argv[1] ?? 0);
$units = (int) ($argv[2] ?? 0);

$ok = $app->make(QuotaLedger::class)->withWriteLock(fn (WriteLock $lock) => $lock->reserve($creationId, $units));

echo $ok ? 'ok' : 'no';
