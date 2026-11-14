<?php

namespace App\Services\YouTube;

use RuntimeException;

class YouTubeApiException extends RuntimeException
{
    public function __construct(public readonly YouTubeError $error)
    {
        parent::__construct(sprintf(
            'YouTube API error: kind=%s status=%s reason=%s',
            $error->kind->value,
            $error->status ?? 'none',
            $error->reason ?? 'none',
        ));
    }
}
