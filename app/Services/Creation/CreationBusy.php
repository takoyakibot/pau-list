<?php

namespace App\Services\Creation;

use RuntimeException;

/** A write of this creation is being sent right now (a lease younger than 60 seconds): the operation must wait. */
class CreationBusy extends RuntimeException
{
    public const MESSAGE = '処理中の動画があります。数秒待ってからもう一度押してください';

    public function __construct()
    {
        parent::__construct('A write of this creation is in progress.');
    }
}
