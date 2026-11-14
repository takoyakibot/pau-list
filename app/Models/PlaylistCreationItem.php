<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaylistCreationItem extends Model
{
    public const PENDING = 'pending';

    public const IN_FLIGHT = 'in_flight';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_outside_channel' => 'boolean',
            'attempts' => 'integer',
            'leased_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PlaylistCreation, $this> */
    public function creation(): BelongsTo
    {
        return $this->belongsTo(PlaylistCreation::class, 'creation_id');
    }
}
