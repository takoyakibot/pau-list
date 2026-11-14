<?php

namespace Tests\Support;

use App\Models\PlaylistCreation;
use App\Models\Proposal;
use App\Models\QuotaReservation;
use App\Support\OwnerLogin;
use App\Support\OwnerSession;
use App\Support\ProposalKey;
use App\Support\QuotaClock;
use Carbon\CarbonImmutable;

trait CreatesProposals
{
    protected string $channelId = 'UCaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * Same defaults as PreviewScreenTest::makeProposal. Returns the raw key.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function createProposal(array $attrs = [], ?array $items = null): string
    {
        $key = ProposalKey::generate();
        $items ??= [
            ['video_id' => 'aaaaaaaaaaa', 'title' => '動画 A', 'duration_seconds' => 65, 'is_outside_channel' => false],
            ['video_id' => 'bbbbbbbbbbb', 'title' => '動画 B', 'duration_seconds' => 3658, 'is_outside_channel' => true],
        ];
        $proposal = Proposal::create(array_merge([
            'key_hash' => ProposalKey::hash($key),
            'channel_id' => $this->channelId,
            'channel_title' => 'チャンネル A',
            'channel_thumbnail_url' => null,
            'title' => '私の再生リスト',
            'description' => "1 行目\n2 行目 https://example.com/x",
            'comment' => null,
            'proposer_name' => null,
            'item_count' => count($items),
            'created_at' => now(),
            'expires_at' => now()->addDays(30),
        ], $attrs));
        foreach ($items as $i => $item) {
            $outside = $item['is_outside_channel'];
            $proposal->items()->create(array_merge([
                'position' => $i + 1,
                'video_channel_id' => $outside ? 'UC'.str_repeat('b', 22) : $this->channelId,
                'video_channel_title' => $outside ? '別チャンネル' : 'チャンネル A',
                'thumbnail_url' => 'https://i.ytimg.com/vi/'.$item['video_id'].'/mqdefault.jpg',
                'published_at' => now()->subDays(3),
            ], $item));
        }

        return $key;
    }

    protected function actAsOwner(
        string $channelId,
        ?CarbonImmutable $expiresAt = null,
        string $title = 'My Channel',
        string $token = 'ya29.session-token',
    ): static {
        return $this->withSession([OwnerSession::OWNER_KEY => OwnerSession::serialize(
            new OwnerLogin($token, $expiresAt ?? now()->toImmutable()->addMinutes(30), $channelId, $title),
        )]);
    }

    /**
     * A pending creation (with $items pending items, positions 0..n-1) for a new proposal. With $reserve,
     * today's active reservation of that many units is created; quota_usage is left untouched.
     * With $key, the creation belongs to that (already created) proposal.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function makeCreation(int $items = 3, array $attrs = [], ?int $reserve = null, ?string $key = null): PlaylistCreation
    {
        $proposal = Proposal::where('key_hash', ProposalKey::hash($key ?? $this->createProposal()))->sole();
        $creation = PlaylistCreation::create(array_merge([
            'proposal_id' => $proposal->id,
            'owner_channel_id' => $proposal->channel_id,
            'title' => $proposal->title,
            'description' => $proposal->description,
            'privacy_status' => 'private',
            'status' => PlaylistCreation::PENDING,
            'estimated_units' => 50 + 50 * $items,
            'started_at' => now(),
        ], $attrs));
        for ($i = 0; $i < $items; $i++) {
            $creation->items()->create([
                'position' => $i,
                'video_id' => sprintf('v%010d', $i),
                'title' => '動画 '.$i,
                'thumbnail_url' => null,
                'is_outside_channel' => false,
                'status' => 'pending',
            ]);
        }
        if ($reserve !== null) {
            QuotaReservation::create([
                'creation_id' => $creation->id,
                'day_pt' => QuotaClock::todayPt(),
                'reserved_units' => $reserve,
                'consumed_units' => 0,
                'status' => QuotaReservation::ACTIVE,
            ]);
        }

        return $creation;
    }
}
