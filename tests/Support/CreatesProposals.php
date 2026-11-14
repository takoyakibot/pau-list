<?php

namespace Tests\Support;

use App\Models\Proposal;
use App\Support\OwnerLogin;
use App\Support\OwnerSession;
use App\Support\ProposalKey;
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
}
