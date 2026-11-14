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

    public function playlistUrl(): ?string
    {
        return $this->youtube_playlist_id === null
            ? null
            : 'https://www.youtube.com/playlist?list='.$this->youtube_playlist_id;
    }
}
