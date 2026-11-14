<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelCacheExpiration extends Model
{
    protected $table = 'channel_cache_expirations';

    protected $primaryKey = 'channel_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expired_at' => 'immutable_datetime',
        ];
    }
}
