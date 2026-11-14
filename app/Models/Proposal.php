<?php

namespace App\Models;

use App\Support\ProposalKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

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

    /** Unknown, malformed and expired keys are the same 404; a database failure is a 503. */
    public static function findLiveOrAbort(string $key): self
    {
        return static::findLiveOr503($key) ?? abort(404);
    }

    /** Like findLiveByKey, but a database failure aborts with 503 without reporting the key hash. */
    public static function findLiveOr503(string $key): ?self
    {
        try {
            return static::findLiveByKey($key);
        } catch (QueryException $e) {
            // The exception message contains SQL bindings (the full key hash): never report it as is.
            Log::error('Proposal lookup failed (database)', ['sqlstate' => (string) $e->getCode()]);
            abort(503);
        }
    }
}
