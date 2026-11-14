<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelCache extends Model
{
    protected $table = 'channel_caches';

    protected $primaryKey = 'channel_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meta_fetched_at' => 'immutable_datetime',
            'cached_since' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'truncated' => 'boolean',
        ];
    }

    /** @return HasMany<ChannelCacheVideo, $this> */
    public function videos(): HasMany
    {
        return $this->hasMany(ChannelCacheVideo::class, 'channel_id', 'channel_id');
    }
}
