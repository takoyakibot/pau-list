<?php

namespace App\Services\Creation;

use App\Support\RemainingToday;
use RuntimeException;

/** Today's free write quota cannot cover the creation (spec 5.4 step 1): nothing was created or reserved. */
class InsufficientWriteQuota extends RuntimeException
{
    public function __construct(public readonly RemainingToday $remaining, public readonly int $items)
    {
        parent::__construct('Not enough write quota today for '.$items.' items.');
    }
}
