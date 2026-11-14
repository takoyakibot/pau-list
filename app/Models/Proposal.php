<?php

namespace App\Models;

use App\Support\ProposalKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proposal extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'item_count' => 'integer',
        ];
    }

    /** @return HasMany<ProposalItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class)->orderBy('position');
    }

    public static function findLiveByKey(string $key): ?self
    {
        if (! ProposalKey::isWellFormed($key)) {
            return null;
        }

        return static::query()
            ->where('key_hash', ProposalKey::hash($key))
            ->where('expires_at', '>', now())
            ->first();
    }
}
