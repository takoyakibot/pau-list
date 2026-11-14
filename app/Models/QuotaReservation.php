<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** Write quota reserved for one creation on one PT day (spec 5.4). */
class QuotaReservation extends Model
{
    public const ACTIVE = 'active';

    public const RELEASED = 'released';

    public const EXPIRED = 'expired';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reserved_units' => 'integer',
            'consumed_units' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Σ(reserved − consumed) of the active reservations of one PT day ('Y-m-d'): the part of 5.4's free formula. */
    public static function unusedActiveOn(string $dayPt): int
    {
        return (int) static::where('day_pt', $dayPt)
            ->where('status', self::ACTIVE)
            ->sum(DB::raw('reserved_units - consumed_units'));
    }

    /** @return BelongsTo<PlaylistCreation, $this> */
    public function creation(): BelongsTo
    {
        return $this->belongsTo(PlaylistCreation::class, 'creation_id');
    }
}
