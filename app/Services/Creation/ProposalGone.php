<?php

namespace App\Services\Creation;

use RuntimeException;

/** The proposal was deleted while its creation was being started (spec 7.2, Y19): nothing was created. */
final class ProposalGone extends RuntimeException
{
    public const MESSAGE = 'この提案は削除されたため、作成を開始できませんでした';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
