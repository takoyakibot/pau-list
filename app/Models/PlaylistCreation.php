<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The snapshot of what the channel owner confirmed, and the progress of creating it on YouTube (spec 4.2, 7). */
class PlaylistCreation extends Model
{
    public const CREATED_AT = null;

    public const PENDING = 'pending';

    public const CREATING_PLAYLIST = 'creating_playlist';

    public const ADDING = 'adding';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const NEEDS_CHECK = 'needs_check';

    public const PAUSE_AUTH = 'auth_expired';

    public const PAUSE_QUOTA_EXCEEDED = 'quota_exceeded';

    public const PAUSE_QUOTA_BUDGET = 'quota_budget';

    public const PAUSE_USER = 'user';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'playlist_attempted_at' => 'immutable_datetime',
            'playlist_leased_at' => 'immutable_datetime',
            'playlist_attempts' => 'integer',
            'reconcile_failures' => 'integer',
            'estimated_units' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'owner_last_active_at' => 'immutable_datetime',
            'authorized_data_pruned_at' => 'immutable_datetime',
            'pruned_had_playlist' => 'boolean',
            'done_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
            'total_count' => 'integer',
        ];
    }

    /** @return HasMany<PlaylistCreationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PlaylistCreationItem::class, 'creation_id')->orderBy('position');
    }

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /** @return HasMany<QuotaReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(QuotaReservation::class, 'creation_id');
    }

    /**
     * The logged-in owner used this creation now (start, step, operation, S6 view, logout): data obtained with the
     * owner's token is kept until 7 days after the last use (PruneExpiredData). Only this column is written.
     */
    public static function recordOwnerActivity(int $id): void
    {
        self::whereKey($id)->toBase()->update(['owner_last_active_at' => now()]);
    }

    /** The items were deleted 7 days after the owner's last use; only the counts remain (*_count). */
    public function isPruned(): bool
    {
        return $this->authorized_data_pruned_at !== null;
    }

    /** A playlist was created on YouTube (its id may have been dropped with the owner's data). */
    public function playlistCreated(): bool
    {
        return $this->youtube_playlist_id !== null || $this->pruned_had_playlist === true;
    }

    public function playlistUrl(): ?string
    {
        return $this->youtube_playlist_id === null
            ? null
            : 'https://www.youtube.com/playlist?list='.$this->youtube_playlist_id;
    }
}
