<?php

namespace App\Services;

use RuntimeException;

class UnavailableVideos extends RuntimeException
{
    /** @param list<string> $ids */
    public function __construct(public readonly array $ids)
    {
        parent::__construct('Some videos are deleted or not public: '.count($ids));
    }
}
