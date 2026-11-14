<?php

namespace App\Services\Creation;

use App\Models\PlaylistCreation;
use App\Models\Proposal;
use App\Services\UnavailableVideos;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\VideoInfo;
use App\Services\YouTube\WriteLock;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeOwnerClient;
use App\Services\YouTube\YouTubeReader;
use App\Support\OwnerLogin;
use App\Support\QuotaClock;
use App\Support\QuotaPolicy;
use App\Support\RemainingToday;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Spec 7.2: start creating the owner's confirmed list. Re-checks every video (owner_read), reserves the write
 * quota (5.4) and snapshots the list into playlist_creations / playlist_creation_items, all or nothing.
 * The proposal itself is never changed: the owner's own unlisted videos (Y11) live only in the snapshot.
 * A proposal has at most one creation (UNIQUE proposal_id): a second start returns the existing one.
 */
class CreationStarter
{
    public function __construct(
        private readonly YouTubeReader $reader,
        private readonly YouTubeOwnerClient $owner,
        private readonly QuotaLedger $ledger,
    ) {}

    /**
     * @param  array{title: string, description?: ?string, privacy: string, video_ids: list<string>}  $validated
     *
     * @throws UnavailableVideos
     * @throws InsufficientWriteQuota
     * @throws ProposalGone
     * @throws YouTubeApiException
     */
    public function start(Proposal $proposal, OwnerLogin $owner, array $validated): StartedCreation
    {
        $existing = $proposal->creation()->first();
        if ($existing !== null) {
            return new StartedCreation($existing, false);
        }

        $ids = array_values($validated['video_ids']);
        $videos = $this->finalCheck($ids, $owner);
        $n = count($ids);
        $estimated = QuotaPolicy::PLAYLIST_UNITS + QuotaPolicy::ITEM_UNITS * $n;

        $this->ledger->ensureWriteRow();
        try {
            $started = $this->ledger->withWriteLock(function (WriteLock $lock) use ($proposal, $validated, $ids, $videos, $n, $estimated) {
                // The owner may have deleted the proposal (another tab) during the final check. The delete takes the
                // write lock too, so this locking read sees it; nothing is created for a deleted proposal.
                if (! Proposal::whereKey($proposal->id)->sharedLock()->exists()) {
                    throw new ProposalGone;
                }
                $existing = $proposal->creation()->first();
                if ($existing !== null) {
                    return new StartedCreation($existing, false);
                }
                if ($lock->free() < $estimated) {
                    throw $this->insufficient($lock, $n);
                }

                try {
                    $creation = PlaylistCreation::create([
                        'proposal_id' => $proposal->id,
                        'owner_channel_id' => $proposal->channel_id,
                        'title' => $validated['title'],
                        'description' => $validated['description'] ?? '',
                        'privacy_status' => $validated['privacy'],
                        'status' => PlaylistCreation::PENDING,
                        'estimated_units' => $estimated,
                        'started_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Last line of defense against a double start (spec 7.2): another request created it after our
                    // checks. Only this statement failed and nothing else was written: commit and read the other
                    // request's row after the transaction (a fresh read; no creation-row lock under the write lock).
                    return null;
                }

                foreach ($ids as $position => $id) {
                    $video = $videos[$id];
                    $creation->items()->create([
                        'position' => $position,
                        'video_id' => $video->id,
                        'title' => $video->title,
                        'thumbnail_url' => $video->thumbnailUrl,
                        'is_outside_channel' => $video->channelId !== $proposal->channel_id,
                        'status' => 'pending',
                    ]);
                }
                if (! $lock->reserve($creation->id, $estimated)) {
                    throw $this->insufficient($lock, $n); // rolls the creation back
                }

                return new StartedCreation($creation, true);
            });
        } catch (UniqueConstraintViolationException) {
            $started = null;
        } catch (QueryException $e) {
            // Defensive: a foreign-key failure because the proposal was deleted after all (rolled back).
            if (! Proposal::whereKey($proposal->id)->exists()) {
                throw new ProposalGone;
            }
            throw $e;
        }
        if ($started === null) {
            $other = $proposal->creation()->first();
            if ($other === null) {
                throw new \RuntimeException('Creation insert hit a unique violation but no creation exists.');
            }

            return new StartedCreation($other, false);
        }

        if ($started->created) {
            Log::info('Creation started', [
                'creation_id' => $started->creation->id,
                // Which channel holds the reservation (an operator may release it: app:release-creation-reservation).
                'channel' => substr($proposal->channel_id, 0, 8),
                'items' => $n,
                'reserved_units' => $estimated,
            ]);
        }

        return $started;
    }

    /**
     * Public videos are checked with the API key (owner_read); the rest with the owner's token, accepting only
     * the owner's own unlisted videos. Anything else is reported back, never dropped silently.
     *
     * @param  list<string>  $ids
     * @return array<string, VideoInfo>
     *
     * @throws UnavailableVideos
     * @throws YouTubeApiException
     */
    private function finalCheck(array $ids, OwnerLogin $owner): array
    {
        $accepted = [];
        foreach ($this->reader->videos($ids, QuotaLedger::OWNER_READ) as $id => $video) {
            if ($video->privacyStatus === 'public') {
                $accepted[$id] = $video;
            }
        }

        $rest = array_values(array_diff($ids, array_keys($accepted)));
        if ($rest !== []) {
            foreach ($this->owner->videos($owner->accessToken, $rest) as $id => $video) {
                if ($video->channelId === $owner->channelId && $video->privacyStatus === 'unlisted') {
                    $accepted[$id] = $video;
                }
            }
        }

        $unavailable = array_values(array_filter($ids, fn (string $id) => ! isset($accepted[$id])));
        if ($unavailable !== []) {
            throw new UnavailableVideos($unavailable);
        }

        return $accepted;
    }

    private function insufficient(WriteLock $lock, int $n): InsufficientWriteQuota
    {
        Log::info('Write quota insufficient', ['items' => $n, 'free_units' => $lock->free()]);

        return new InsufficientWriteQuota(
            new RemainingToday(QuotaPolicy::itemsAffordable($lock->free()), QuotaClock::nextResetJst()),
            $n,
        );
    }
}
