<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireOwnerSession;
use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\QuotaReservation;
use App\Services\YouTube\QuotaLedger;
use App\Support\OwnerSession;
use App\Support\ProposalKey;
use App\Support\QuotaClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreatesProposals;
use Tests\Support\EnforcesCsrf;
use Tests\Support\FakesGoogle;
use Tests\Support\FakesYouTube;
use Tests\TestCase;

#[Group('mysql')]
class CreationStartTest extends TestCase
{
    use CreatesProposals, EnforcesCsrf, FakesGoogle, FakesYouTube, RefreshDatabase;

    private const OTHER = 'UCbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:00:00'));
    }

    /** @param array<string, mixed> $override */
    private function body(array $override = []): array
    {
        return array_merge([
            'title' => '私の再生リスト',
            'description' => '説明',
            'privacy' => 'private',
            'video_ids' => ['aaaaaaaaaaa', 'bbbbbbbbbbb'],
        ], $override);
    }

    private function start(string $key, array $body, array $headers = ['Sec-Fetch-Site' => 'same-origin']): TestResponse
    {
        return $this->postJson("/p/$key/creation", $body, $headers);
    }

    private function fakeBothPublic(): void
    {
        $this->fakeVideos([
            $this->videoResource('aaaaaaaaaaa', $this->channelId, ['title' => '最新 A']),
            $this->videoResource('bbbbbbbbbbb', self::OTHER, ['title' => '最新 B']),
        ]);
    }

    public function test_start_reserves_snapshots_and_redirects(): void
    {
        $key = $this->createProposal();
        $this->fakeBothPublic();
        Log::spy();

        $res = $this->actAsOwner($this->channelId)->start($key, $this->body())
            ->assertCreated()->assertExactJson(['redirect' => 'creation']);

        $c = PlaylistCreation::sole();
        $this->assertSame([PlaylistCreation::PENDING, 150, 'private'], [$c->status, $c->estimated_units, $c->privacy_status]);
        $this->assertSame([$this->channelId, '私の再生リスト', '説明'], [$c->owner_channel_id, $c->title, $c->description]);
        $this->assertSame([0, 1], $c->items()->pluck('position')->all());
        $this->assertSame(['最新 A', '最新 B'], $c->items()->pluck('title')->all());
        $this->assertSame([false, true], $c->items()->get()->map(fn ($i) => $i->is_outside_channel)->all());
        $this->assertSame(['pending', 'pending'], $c->items()->pluck('status')->all());
        $this->assertNotNull($c->started_at);
        $r = QuotaReservation::sole();
        $this->assertSame([150, 0, QuotaReservation::ACTIVE, QuotaClock::todayPt()], [$r->reserved_units, $r->consumed_units, $r->status, $r->day_pt]);
        $this->assertSame(1, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::READ));
        $this->assertSame(0, app(QuotaLedger::class)->used(QuotaLedger::WRITE));
        $this->assertSame(2, ProposalItem::count());
        $res->assertSessionHas('creation_autostart', true);
        Log::shouldHaveReceived('info')->with('Creation started', ['creation_id' => $c->id, 'items' => 2, 'reserved_units' => 150])->once();
    }

    public function test_edited_order_title_and_privacy_are_used(): void
    {
        $key = $this->createProposal();
        $this->fakeBothPublic();

        $this->actAsOwner($this->channelId)->start($key, $this->body([
            'title' => "  新しい名前\u{200B}  ",
            'description' => null,
            'privacy' => 'public',
            'video_ids' => ['bbbbbbbbbbb', 'aaaaaaaaaaa'],
        ]))->assertCreated();

        $c = PlaylistCreation::sole();
        $this->assertSame(['新しい名前', '', 'public', 150], [$c->title, $c->description, $c->privacy_status, $c->estimated_units]);
        $this->assertSame(['bbbbbbbbbbb', 'aaaaaaaaaaa'], $c->items()->pluck('video_id')->all());
        $this->assertSame([0, 1], $c->items()->pluck('position')->all());
        $this->assertSame('私の再生リスト', Proposal::sole()->title);
    }

    public function test_one_video_reserves_100(): void
    {
        $key = $this->createProposal();
        $this->fakeBothPublic();
        $this->actAsOwner($this->channelId)->start($key, $this->body(['video_ids' => ['aaaaaaaaaaa']]))->assertCreated();
        $this->assertSame(100, QuotaReservation::sole()->reserved_units);
    }

    public function test_insufficient_quota_creates_nothing_and_explains(): void
    {
        $this->makeCreation(3, reserve: 4880); // free 120: one video affordable
        $key = $this->createProposal();
        $this->fakeBothPublic();

        $res = $this->actAsOwner($this->channelId)->start($key, $this->body())->assertStatus(409);

        $this->assertSame(
            '本日の残り: 再生リスト 1 つ＋動画あと 1 本まで作成できます。あと 1 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 11月15日 17時 です）',
            $res->json('message'),
        );
        $this->assertSame(1, PlaylistCreation::count());
        $this->assertSame(3, PlaylistCreationItem::count());
        $this->assertSame(1, QuotaReservation::count());
        $res->assertSessionMissing('creation_autostart');
    }

    public function test_insufficient_quota_counts_other_creations_reservations(): void
    {
        config(['youtube.quota.write_limit' => 1000]);
        $other = $this->makeCreation(3, reserve: 920); // free 80: nothing affordable
        $key = $this->createProposal();
        $this->fakeBothPublic();

        $res = $this->actAsOwner($this->channelId)->start($key, $this->body())->assertStatus(409);
        $this->assertSame('本日はこれ以上作成できません。次にリセットされるのは 日本時間 11月15日 17時 です', $res->json('message'));
        $this->assertSame(1, PlaylistCreation::count());
        $this->assertSame($other->id, PlaylistCreation::sole()->id);
    }

    public function test_unavailable_videos_are_returned_not_dropped(): void
    {
        $key = $this->createProposal();
        $this->fakeOwnerVideos([]); // the owner cannot see it either
        $this->fakeVideos([
            $this->videoResource('aaaaaaaaaaa', $this->channelId),
            $this->videoResource('bbbbbbbbbbb', self::OTHER, ['privacy' => 'private']),
        ]);

        $this->actAsOwner($this->channelId)->start($key, $this->body())->assertStatus(422)->assertExactJson([
            'message' => 'この動画は削除または非公開になっています。外してから作成してください',
            'unavailable_ids' => ['bbbbbbbbbbb'],
        ]);
        $this->assertSame(0, PlaylistCreation::count());
        $this->assertSame(0, QuotaReservation::count());
        $this->assertSame(2, ProposalItem::count());
    }

    public function test_owner_unlisted_video_is_accepted_via_owner_token(): void
    {
        $key = $this->createProposal();
        $this->fakeOwnerVideos([$this->videoResource('unl00000001', $this->channelId, ['privacy' => 'unlisted', 'title' => '限定の動画'])]);
        $this->fakeVideos([
            $this->videoResource('aaaaaaaaaaa', $this->channelId),
            $this->videoResource('unl00000001', $this->channelId, ['privacy' => 'unlisted']),
        ]);

        $this->actAsOwner($this->channelId)->start($key, $this->body(['video_ids' => ['unl00000001', 'aaaaaaaaaaa']]))->assertCreated();

        $ownerCalls = Http::recorded(fn ($r) => $r->hasHeader('Authorization'))->map(fn ($p) => $p[0]);
        $this->assertCount(1, $ownerCalls);
        $this->assertSame('Bearer ya29.session-token', $ownerCalls->first()->header('Authorization')[0]);
        parse_str((string) parse_url($ownerCalls->first()->url(), PHP_URL_QUERY), $q);
        $this->assertSame('unl00000001', $q['id']);
        $this->assertSame(2, app(QuotaLedger::class)->used(QuotaLedger::OWNER_READ));

        $item = PlaylistCreationItem::where('video_id', 'unl00000001')->sole();
        $this->assertSame([0, '限定の動画', false], [$item->position, $item->title, $item->is_outside_channel]);
        $this->assertSame(0, ProposalItem::where('video_id', 'unl00000001')->count());
        $this->assertSame(2, ProposalItem::count());
    }

    public function test_unlisted_video_of_another_channel_is_rejected(): void
    {
        $key = $this->createProposal();
        $this->fakeOwnerVideos([
            $this->videoResource('unl00000002', self::OTHER, ['privacy' => 'unlisted']),
            $this->videoResource('prv00000001', $this->channelId, ['privacy' => 'private']),
        ]);
        $this->fakeVideos([$this->videoResource('aaaaaaaaaaa', $this->channelId)]);

        $this->actAsOwner($this->channelId)->start($key, $this->body(['video_ids' => ['aaaaaaaaaaa', 'unl00000002', 'prv00000001']]))
            ->assertStatus(422)->assertJsonPath('unavailable_ids', ['unl00000002', 'prv00000001']);
        $this->assertSame(0, PlaylistCreation::count());
    }

    public function test_owner_401_forgets_login_and_returns_401(): void
    {
        $key = $this->createProposal();
        Http::fake(fn ($r) => $r->hasHeader('Authorization') ? $this->googleError(401, 'authError') : null);
        $this->fakeVideos([$this->videoResource('aaaaaaaaaaa', $this->channelId)]);

        $this->actAsOwner($this->channelId)->start($key, $this->body(['video_ids' => ['aaaaaaaaaaa', 'unl00000001']]))
            ->assertStatus(401)->assertExactJson(['message' => RequireOwnerSession::NOTICE]);
        $this->assertFalse(session()->has(OwnerSession::OWNER_KEY));
        $this->assertSame(0, PlaylistCreation::count());
    }

    public function test_youtube_failure_is_503_json(): void
    {
        $key = $this->createProposal();
        $this->fakeYouTubeError('videos', 500, 'backendError');

        $this->actAsOwner($this->channelId)->start($key, $this->body())
            ->assertStatus(503)->assertExactJson(['message' => 'YouTube との通信に失敗しました。時間をおいて再度お試しください']);
        $this->assertTrue(session()->has(OwnerSession::OWNER_KEY));
        $this->assertSame(0, PlaylistCreation::count());
    }

    public function test_second_start_redirects_without_new_reservation(): void
    {
        $key = $this->createProposal();
        $this->fakeBothPublic();
        $this->actAsOwner($this->channelId)->start($key, $this->body())->assertCreated();
        $sent = count(Http::recorded());

        $this->actAsOwner($this->channelId)->start($key, $this->body(['title' => '別の名前']))
            ->assertOk()->assertExactJson(['redirect' => 'creation']);

        $this->assertCount($sent, Http::recorded());
        $this->assertSame(1, PlaylistCreation::count());
        $this->assertSame('私の再生リスト', PlaylistCreation::sole()->title);
        $this->assertSame(1, QuotaReservation::count());
        $this->assertSame(150, QuotaReservation::sole()->reserved_units);
    }

    public function test_unique_violation_is_treated_as_existing(): void
    {
        $key = $this->createProposal();
        $proposal = Proposal::sole();
        $this->fakeBothPublic();
        $injected = false;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, Connection $connection) use (&$injected, $proposal) {
            if ($injected || preg_match('/^insert into ["`]?playlist_creations["`]?/i', $sql) !== 1) {
                return;
            }
            $injected = true;
            // Another request committed its creation after our existence checks.
            $connection->table('playlist_creations')->insert([
                'proposal_id' => $proposal->id,
                'owner_channel_id' => $proposal->channel_id,
                'title' => '先に作られた',
                'description' => '',
                'privacy_status' => 'private',
                'status' => PlaylistCreation::PENDING,
                'estimated_units' => 150,
                'started_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actAsOwner($this->channelId)->start($key, $this->body())
            ->assertOk()->assertExactJson(['redirect' => 'creation'])->assertSessionMissing('creation_autostart');

        $this->assertTrue($injected);
        $this->assertSame('先に作られた', PlaylistCreation::sole()->title);
        $this->assertSame(0, PlaylistCreationItem::count());
        $this->assertSame(0, QuotaReservation::count());
    }

    public function test_validation_errors_are_422_json(): void
    {
        $key = $this->createProposal();
        $this->actAsOwner($this->channelId);
        $cases = [
            [['title' => " \u{3000}"], 'title', '再生リスト名を入力してください'],
            [['title' => str_repeat('あ', 151)], 'title', '再生リスト名 は 150 文字以内で入力してください'],
            [['description' => str_repeat('あ', 1001)], 'description', '再生リストの説明 は 1000 文字以内で入力してください'],
            [['privacy' => 'friends'], 'privacy', '公開範囲の値が正しくありません'],
            [['privacy' => null], 'privacy', '公開範囲の値が正しくありません'],
            [['video_ids' => []], 'video_ids', '動画を 1 本以上選んでください'],
            [['video_ids' => array_map(fn ($i) => sprintf('v%010d', $i), range(1, 100))], 'video_ids', '1 つの再生リストに入れられるのは最大 99 本です'],
            [['video_ids' => ['aaaaaaaaaaa', 'aaaaaaaaaaa']], 'video_ids.0', '同じ動画が重複しています'],
            [['video_ids' => ['bad']], 'video_ids.0', '動画 ID の形式が正しくありません'],
        ];
        foreach ($cases as [$override, $field, $message]) {
            RateLimiter::clear(md5('creation-start'.'creation-start:'.$this->channelId));
            $res = $this->start($key, $this->body($override))->assertStatus(422);
            $this->assertSame($message, $res->json('errors')[$field][0] ?? null, $field);
        }
        $this->assertCount(0, Http::recorded());
        $this->assertSame(0, PlaylistCreation::count());
    }

    public function test_title_of_150_characters_is_accepted(): void
    {
        $key = $this->createProposal();
        $this->fakeBothPublic();
        $this->actAsOwner($this->channelId)->start($key, $this->body(['title' => str_repeat('あ', 150), 'description' => str_repeat('い', 1000)]))
            ->assertCreated();
    }

    public function test_json_routes_get_401_or_403_json_from_middleware(): void
    {
        $key = $this->createProposal();
        $this->start($key, $this->body())->assertStatus(401)->assertExactJson(['message' => RequireOwnerSession::NOTICE]);

        $this->actAsOwner($this->channelId, now()->toImmutable()->subSecond())->start($key, $this->body())
            ->assertStatus(401)->assertExactJson(['message' => RequireOwnerSession::NOTICE]);

        $this->actAsOwner(self::OTHER)->start($key, $this->body())
            ->assertForbidden()->assertExactJson(['message' => RequireOwnerSession::MISMATCH]);
        $this->assertSame('ログイン中のチャンネルはこの提案の対象ではありません', RequireOwnerSession::MISMATCH);

        $this->actAsOwner($this->channelId)->start(ProposalKey::generate(), $this->body())->assertNotFound()->assertJsonStructure(['message']);
        $this->assertCount(0, Http::recorded());
    }

    public function test_cross_site_is_rejected(): void
    {
        $key = $this->createProposal();
        $this->fakeBothPublic();
        $this->actAsOwner($this->channelId)->start($key, $this->body(), ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden()
            ->assertExactJson(['message' => 'この操作はこの画面からのみ行えます']);
        $this->start($key, $this->body(), [])->assertForbidden(); // neither Sec-Fetch-Site nor X-Requested-With
        $this->assertSame(0, PlaylistCreation::count());
        $this->assertCount(0, Http::recorded());
    }

    public function test_missing_csrf_token_is_rejected(): void
    {
        $this->enforceCsrf();
        $key = $this->createProposal();
        $this->fakeBothPublic();
        // Without Fetch Metadata (older browsers) the request needs the X-CSRF-TOKEN header; a wrong one is 419.
        // (A Sec-Fetch-Site: same-origin request is accepted by the framework's origin check.)
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->actAsOwner($this->channelId)->start($key, $this->body(), $headers)->assertStatus(419);
        $this->actAsOwner($this->channelId)->start($key, $this->body(), $headers + ['X-CSRF-TOKEN' => 'wrong'])->assertStatus(419);
        $this->assertSame(0, PlaylistCreation::count());
        $this->assertCount(0, Http::recorded());
    }

    public function test_start_is_limited_to_5_per_hour_per_channel(): void
    {
        $this->fakeVideos([$this->videoResource('aaaaaaaaaaa', $this->channelId, ['privacy' => 'private'])]);
        $this->fakeOwnerVideos([]);
        $key = $this->createProposal();
        $body = $this->body(['video_ids' => ['aaaaaaaaaaa']]);
        for ($i = 0; $i < 5; $i++) {
            $this->actAsOwner($this->channelId)->start($key, $body)->assertStatus(422);
        }
        $this->actAsOwner($this->channelId)->start($key, $body)->assertStatus(429)->assertJsonStructure(['message']);

        // Another channel has its own limit.
        $other = $this->createProposal(['channel_id' => self::OTHER]);
        $this->actAsOwner(self::OTHER)->start($other, $body)->assertStatus(422);

        $this->travel(61)->minutes();
        $this->actAsOwner($this->channelId)->start($key, $body)->assertStatus(422);
    }
}
