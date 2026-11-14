<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of a cached upload. Rows are written with the query builder by ChannelVideoCatalog.
 */
class ChannelCacheVideo extends Model
{
    protected $table = 'channel_cache_videos';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_at' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
