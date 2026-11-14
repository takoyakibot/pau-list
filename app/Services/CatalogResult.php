<?php

namespace App\Services;

use App\Models\ChannelCache;

final readonly class CatalogResult
{
    /**
     * @param  bool  $hadExpired  An expiration record existed for the channel when the import started.
     */
    public function __construct(
        public CatalogStatus $status,
        public ?ChannelCache $channel,
        public bool $hadExpired = false,
    ) {}
}
