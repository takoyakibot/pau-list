<?php

namespace Tests\Feature;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Models\QuotaReservation;
use App\Services\Creation\CreationProgress;
use App\Services\YouTube\GoogleOAuth;
use App\Services\YouTube\QuotaLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreatesProposals;
use Tests\TestCase;

/**
 * Final review of Phase 4 (policy I1, Developer Policies III.D.2): data obtained with the owner's token (the
 * creation's items, a non-public playlist id) is deleted within 7 days after the owner's last use or logout.
 * The batch runs daily and deletes what reaches 7 days before its next run (now + 24h − 7 days).
 */
#[Group('mysql')]
class PruneAuthorizedDataTest extends TestCase
{
    use CreatesProposals, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:00:00'));
    }

    /** A completed creation of 3 items (2 done, 1 failed), last used by the owner at $lastActive. */
    private function completed(CarbonImmutable $lastActive, array $attrs = [], ?string $key = null): PlaylistCreation
    {
        $c = $this->makeCreation(3, array_merge([
            'status' => PlaylistCreation::COMPLETED,
            'youtube_playlist_id' => 'PLprivate123',
            'completed_at' => $lastActive,
            'owner_last_active_at' => $lastActive,
        ], $attrs), key: $key);
        $c->items()->where('position', '<', 2)->update(['status' => PlaylistCreationItem::DONE, 'youtube_item_id' => 'I']);
        $c->items()->where('position', 2)->update(['status' => PlaylistCreationItem::FAILED, 'last_error' => 'forbidden']);

        return $c;
    }

    public function test_kept_until_seven_days_before_the_next_run(): void
    {
        // Last used 6 days minus 1 minute ago: at the next run (in 24h) it is still under 7 days.
        $c = $this->completed(now()->toImmutable()->subDays(6)->addMinute());

        $this->artisan('app:prune-expired')->expectsOutput('authorized_data: 0')->assertSuccessful();

        $c->refresh();
        $this->assertNull($c->authorized_data_pruned_at);
        $this->assertSame('PLprivate123', $c->youtube_playlist_id);
        $this->assertSame(3, $c->items()->count());
    }

    public function test_deleted_when_seven_days_are_reached_before_the_next_run(): void
    {
        $c = $this->completed(now()->toImmutable()->subDays(6));

        $this->artisan('app:prune-expired')->expectsOutput('authorized_data: 1')->assertSuccessful();

        $c->refresh();
        $this->assertNotNull($c->authorized_data_pruned_at);
        $this->assertSame(0, PlaylistCreationItem::where('creation_id', $c->id)->count());
        $this->assertNull($c->youtube_playlist_id);
        $this->assertTrue((bool) $c->pruned_had_playlist);
        $this->assertSame([2, 1, 0, 3], [$c->done_count, $c->failed_count, $c->skipped_count, $c->total_count]);
        $this->assertSame(PlaylistCreation::COMPLETED, $c->status);
        // The proposal itself stays until its own 30 days.
        $this->assertNotNull($c->proposal()->first());
    }

    public function test_public_playlist_id_is_kept(): void
    {
        $c = $this->completed(now()->toImmutable()->subDays(10), ['privacy_status' => 'public', 'youtube_playlist_id' => 'PLpublic123']);

        $this->artisan('app:prune-expired')->expectsOutput('authorized_data: 1')->assertSuccessful();

        $c->refresh();
        $this->assertSame('PLpublic123', $c->youtube_playlist_id);
        $this->assertSame(0, PlaylistCreationItem::where('creation_id', $c->id)->count());
    }

    public function test_stale_paused_and_needs_check_creations_are_closed_and_their_reservation_released(): void
    {
        $old = now()->toImmutable()->subDays(8);
        $paused = $this->makeCreation(2, [
            'status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER,
            'youtube_playlist_id' => 'PLp', 'owner_last_active_at' => $old,
        ], reserve: 150);
        $check = $this->makeCreation(2, [
            'status' => PlaylistCreation::NEEDS_CHECK, 'playlist_attempted_at' => $old, 'owner_last_active_at' => $old,
        ]);

        $this->artisan('app:prune-expired')->expectsOutput('authorized_data: 2')->assertSuccessful();

        foreach ([$paused->fresh(), $check->fresh()] as $c) {
            $this->assertSame(PlaylistCreation::COMPLETED, $c->status);
            $this->assertNull($c->pause_reason);
            $this->assertNotNull($c->completed_at);
            $this->assertSame(0, PlaylistCreationItem::where('creation_id', $c->id)->count());
        }
        $this->assertSame(QuotaReservation::RELEASED, QuotaReservation::where('creation_id', $paused->id)->value('status'));
        $this->assertSame([0, 0, 0, 2], [$paused->fresh()->done_count, $paused->fresh()->failed_count, $paused->fresh()->skipped_count, $paused->fresh()->total_count]);
    }

    public function test_is_idempotent_and_does_not_touch_other_creations(): void
    {
        $this->completed(now()->toImmutable()->subDays(9));
        $recent = $this->completed(now()->toImmutable()->subDay());

        $this->artisan('app:prune-expired')->expectsOutput('authorized_data: 1')->assertSuccessful();
        $this->artisan('app:prune-expired')->expectsOutput('authorized_data: 0')->assertSuccessful();

        $this->assertSame(3, $recent->items()->count());
    }

    public function test_s6_after_pruning_shows_counts_without_items(): void
    {
        $key = $this->createProposal();
        $c = $this->completed(now()->toImmutable()->subDays(9), key: $key);
        $this->artisan('app:prune-expired')->assertSuccessful();

        $res = $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertOk()
            ->assertSee('作成が完了しました（追加 2 本・失敗 1 本・中止 0 本）')
            ->assertSee(CreationProgress::PRUNED)
            ->assertSee('3 / 3 本を処理しました', false)
            ->assertDontSee('動画 0');
        // A playlist was created: the remake hint stays hidden (its id was dropped, not "not created").
        $this->assertMatchesRegularExpression('/x-show="showRemakeHint"\s+x-cloak/', $res->getContent());

        $progress = CreationProgress::toArray($c->fresh(), app(QuotaLedger::class)->remainingToday());
        $this->assertSame([], $progress['items']);
        $this->assertTrue($progress['playlist_created']);
        $this->assertNull($progress['playlist_url']);
        $this->assertSame(3, $progress['total']);
        $res->assertSee('&quot;playlist_created&quot;:true', false);
    }

    public function test_s4_after_pruning_still_says_created(): void
    {
        $key = $this->createProposal();
        $this->completed(now()->toImmutable()->subDays(9), key: $key);
        $this->artisan('app:prune-expired')->assertSuccessful();

        $this->get("/p/$key")->assertOk()->assertSee('作成済み')->assertDontSee('PLprivate123');
    }

    public function test_s6_view_and_operations_record_the_owner_activity(): void
    {
        $key = $this->createProposal();
        $c = $this->completed(now()->toImmutable()->subDays(5), key: $key);
        $this->travel(1)->hours();

        $this->actAsOwner($this->channelId)->get("/p/$key/creation")->assertOk();
        $this->assertTrue($c->fresh()->owner_last_active_at->equalTo(now()));

        $this->travel(1)->hours();
        $this->actAsOwner($this->channelId)->withCredentials()->postJson("/p/$key/creation/pause", [], ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
        $this->assertTrue($c->fresh()->owner_last_active_at->equalTo(now()));
    }

    public function test_another_channel_does_not_record_activity(): void
    {
        $key = $this->createProposal();
        $before = now()->toImmutable()->subDays(5);
        $c = $this->completed($before, key: $key);

        $this->actAsOwner('UC'.str_repeat('z', 22))->get("/p/$key/creation")->assertStatus(403);

        $this->assertTrue($c->fresh()->owner_last_active_at->equalTo($before));
    }

    public function test_logout_counts_as_the_last_activity(): void
    {
        Http::fake([GoogleOAuth::REVOKE_URL => Http::response('', 200)]);
        $c = $this->completed(now()->toImmutable()->subDays(5));
        $other = $this->completed(now()->toImmutable()->subDays(5), ['owner_channel_id' => 'UC'.str_repeat('z', 22)]);
        $this->travel(1)->hours();

        $this->actAsOwner($this->channelId)->post('/logout')->assertRedirect();

        $this->assertTrue($c->fresh()->owner_last_active_at->equalTo(now()));
        $this->assertTrue($other->fresh()->owner_last_active_at->lessThan(now()->subDays(4)));
    }
}
