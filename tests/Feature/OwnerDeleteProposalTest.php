<?php

namespace Tests\Feature;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\QuotaReservation;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\YouTubeReader;
use App\Support\OwnerSession;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\AssertsNoInlineCode;
use Tests\Support\CreatesProposals;
use Tests\Support\EnforcesCsrf;
use Tests\TestCase;

class OwnerDeleteProposalTest extends TestCase
{
    use AssertsNoInlineCode, CreatesProposals, EnforcesCsrf, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_starts_with($request->url(), YouTubeReader::BASE_URL)
            ? Http::response(['items' => []])
            : null);
    }

    public function test_edit_page_links_to_the_confirmation_page(): void
    {
        $key = $this->createProposal();
        $html = $this->actAsOwner($this->channelId)->get("/p/$key/edit")->assertOk()
            ->assertSee('この提案を削除')
            // relative to /p/{key}/edit: the raw key is not written into S5
            ->assertSee('href="delete"', false)->getContent();
        $this->assertStringNotContainsString($key, $html);
        $this->assertSame(route('proposals.delete', $key), Str::beforeLast(route('proposals.edit', $key), '/').'/delete');
    }

    public function test_confirmation_page_has_a_csrf_post_form_and_deletes_nothing(): void
    {
        $key = $this->createProposal();
        $res = $this->actAsOwner($this->channelId)->get(route('proposals.delete', $key))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('この提案を削除しますか？')
            ->assertSee('削除すると元に戻せません')
            ->assertSee('method="post"', false)
            ->assertSee('action="deletion"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('削除する')
            ->assertSee('href="edit"', false)
            ->assertDontSee('ya29.session-token');
        $this->assertNoInlineCode($res->getContent());
        $this->assertStringNotContainsString($key, $res->getContent());
        // the relative links resolve to the delete POST and the edit page
        $base = Str::beforeLast(route('proposals.delete', $key), '/');
        $this->assertSame(route('proposals.destroy', $key), $base.'/deletion');
        $this->assertSame(route('proposals.edit', $key), $base.'/edit');
        $this->assertSame(1, Proposal::count());
    }

    public function test_owner_can_delete_proposal_and_items(): void
    {
        $key = $this->createProposal();
        $other = $this->createProposal(['title' => '別の提案']);
        $hash = ProposalKey::hash($key);

        $this->actAsOwner($this->channelId)->post(route('proposals.destroy', $key))
            ->assertRedirect(route('proposals.deleted'))
            ->assertSessionHas('deleted_draft_id', substr($hash, 0, 16));

        $this->assertNull(Proposal::where('key_hash', $hash)->first());
        $this->assertSame(1, Proposal::count());
        $this->assertSame(2, ProposalItem::count());
        // the owner stays logged in
        $this->assertTrue(session()->has(OwnerSession::OWNER_KEY));

        $res = $this->get(route('proposals.deleted'))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('削除しました')
            ->assertSee('data-draft-id="'.substr($hash, 0, 16).'"', false)
            ->assertSee('href="'.route('home').'"', false);
        $this->assertNoInlineCode($res->getContent());
        // Vite is off in tests: check the view loads the script that clears the draft.
        $this->assertStringContainsString("@vite('resources/js/owner-deleted.js')", file_get_contents(resource_path('views/owner/deleted.blade.php')));

        // the deleted key is the same 404 as an unknown key
        $deleted = $this->get("/p/$key")->assertNotFound();
        $unknown = $this->get('/p/'.ProposalKey::generate())->assertNotFound();
        $this->assertSame($unknown->getContent(), $deleted->getContent());
        $this->actAsOwner($this->channelId)->get("/p/$key/edit")->assertNotFound();
        $this->actAsOwner($this->channelId)->post(route('proposals.destroy', $key))->assertNotFound();
        $this->get("/p/$other")->assertOk();
    }

    public function test_deleted_page_without_flash_has_no_draft_id(): void
    {
        $this->get(route('proposals.deleted'))->assertOk()->assertSee('削除しました')->assertDontSee('data-draft-id="', false);
    }

    public function test_other_channel_cannot_delete(): void
    {
        $key = $this->createProposal();
        $this->actAsOwner('UC'.str_repeat('z', 22))->post(route('proposals.destroy', $key))->assertForbidden();
        $this->actAsOwner('UC'.str_repeat('z', 22))->get(route('proposals.delete', $key))->assertForbidden();
        $this->assertSame(1, Proposal::count());
        $this->assertSame(2, ProposalItem::count());
    }

    public function test_logged_out_cannot_delete(): void
    {
        $key = $this->createProposal();
        $this->post(route('proposals.destroy', $key))->assertRedirect(route('proposals.show', $key))->assertSessionHas('owner_notice');
        $this->get(route('proposals.delete', $key))->assertRedirect(route('proposals.show', $key));
        $this->assertSame(1, Proposal::count());
    }

    public function test_expired_owner_cannot_delete(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $key = $this->createProposal();
        $this->actAsOwner($this->channelId, now()->toImmutable()->subSecond())
            ->post(route('proposals.destroy', $key))->assertRedirect(route('proposals.show', $key));
        $this->assertSame(1, Proposal::count());
    }

    public function test_expired_proposal_is_404(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14 03:00:00'));
        $key = $this->createProposal(['expires_at' => now()]);
        $this->actAsOwner($this->channelId)->post(route('proposals.destroy', $key))->assertNotFound();
    }

    public function test_get_does_not_delete(): void
    {
        $key = $this->createProposal();
        $this->actAsOwner($this->channelId)->get("/p/$key/deletion")->assertStatus(405);
        $this->assertSame(['POST'], Route::getRoutes()->getByName('proposals.destroy')->methods());
        $this->assertContains('web', Route::getRoutes()->getByName('proposals.destroy')->gatherMiddleware());
        $this->assertSame(1, Proposal::count());
    }

    public function test_csrf_token_is_required(): void
    {
        $this->enforceCsrf();
        $key = $this->createProposal();
        $this->actAsOwner($this->channelId)->post(route('proposals.destroy', $key))->assertStatus(419);
        $this->assertSame(1, Proposal::count());

        $this->actAsOwner($this->channelId)->get('/');
        $this->post(route('proposals.destroy', $key), ['_token' => session()->token()])->assertRedirect(route('proposals.deleted'));
        $this->assertSame(0, Proposal::count());
    }

    public function test_confirmation_page_rejects_non_navigation_fetch(): void
    {
        $key = $this->createProposal();
        $this->actAsOwner($this->channelId)->withHeaders(['Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'])
            ->get(route('proposals.delete', $key))->assertStatus(400);
    }

    private const IN_PROGRESS = '作成中の提案は削除できません。作成状況の画面で「残りを中止」してから削除してください';

    private const PLAYLIST_STAYS = '作成済みの再生リストは YouTube に残ります（この提案と作成の記録だけが削除されます）';

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (['quota_reservations', 'playlist_creation_items', 'playlist_creations', 'proposal_items', 'proposals'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function assertDeleteBlocked(string $key, string $case): void
    {
        $res = $this->actAsOwner($this->channelId)->get(route('proposals.delete', $key))->assertOk()
            ->assertSee(self::IN_PROGRESS)
            ->assertSee('<a class="font-semibold underline" href="creation">作成状況を見る</a>', false)
            ->assertDontSee('action="deletion"', false)
            ->assertDontSee('削除する</button>', false);
        $this->assertDoesNotMatchRegularExpression('/<form\b(?![^>]*owner\/logout)[^>]*>/i', $res->getContent(), $case);
        $this->assertNoInlineCode($res->getContent());
        $this->assertStringNotContainsString($key, $res->getContent());

        $before = $this->tableCounts();
        $this->actAsOwner($this->channelId)->post(route('proposals.destroy', $key))
            ->assertRedirect(route('proposals.creation', $key))
            ->assertSessionHas('creation_notice', self::IN_PROGRESS);
        $this->assertSame($before, $this->tableCounts(), $case);
    }

    public function test_in_progress_creation_blocks_delete_and_points_to_progress(): void
    {
        $key = $this->createProposal();
        $c = $this->makeCreation(3, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], 200, $key);
        $this->assertDeleteBlocked($key, 'adding');
        $this->assertSame(1, Proposal::count());
        $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::where('creation_id', $c->id)->sole()->status);

        // S6 shows the notice as an alert.
        $this->actAsOwner($this->channelId)->withSession(['creation_notice' => self::IN_PROGRESS])
            ->get(route('proposals.creation', $key))->assertOk()
            ->assertSee('<p class="notice-warn text-sm" role="alert">'.e(self::IN_PROGRESS).'</p>', false);
    }

    public function test_paused_or_needs_check_also_blocks_delete(): void
    {
        foreach ([
            'pending' => ['status' => PlaylistCreation::PENDING],
            'creating_playlist' => ['status' => PlaylistCreation::CREATING_PLAYLIST, 'playlist_attempted_at' => now()],
            'paused' => ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER],
            'needs_check' => ['status' => PlaylistCreation::NEEDS_CHECK],
        ] as $case => $attrs) {
            $key = $this->createProposal();
            $this->makeCreation(2, $attrs, 150, $key);
            $this->assertDeleteBlocked($key, $case);
        }
    }

    public function test_completed_creation_is_deleted_with_proposal(): void
    {
        $key = $this->createProposal();
        $c = $this->makeCreation(2, ['status' => PlaylistCreation::COMPLETED, 'youtube_playlist_id' => 'PLdone', 'completed_at' => now()], 150, $key);
        $c->items()->update(['status' => PlaylistCreationItem::DONE]);
        // A creation of another proposal is kept.
        $otherKey = $this->createProposal();
        $other = $this->makeCreation(1, ['status' => PlaylistCreation::COMPLETED, 'completed_at' => now()], 100, $otherKey);

        $res = $this->actAsOwner($this->channelId)->get(route('proposals.delete', $key))->assertOk()
            ->assertSee(self::PLAYLIST_STAYS)
            ->assertSee('action="deletion"', false)
            ->assertSee('href="creation"', false)
            ->assertDontSee('href="edit"', false)
            ->assertDontSee('PLdone');
        $this->assertNoInlineCode($res->getContent());

        $this->actAsOwner($this->channelId)->post(route('proposals.destroy', $key))->assertRedirect(route('proposals.deleted'));

        $this->assertSame([
            'quota_reservations' => 1, 'playlist_creation_items' => 1, 'playlist_creations' => 1, 'proposal_items' => 2, 'proposals' => 1,
        ], $this->tableCounts());
        $this->assertSame($other->id, PlaylistCreation::sole()->id);
        $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::sole()->status);
        $this->assertNull(PlaylistCreation::find($c->id));
        $this->get("/p/$key")->assertNotFound();
    }

    public function test_completed_delete_frees_unused_units_and_deletes_explicitly(): void
    {
        $key = $this->createProposal();
        // Completed while its reservation was still active (e.g. an old row): deleting it gives the units back.
        $c = $this->makeCreation(2, ['status' => PlaylistCreation::COMPLETED, 'completed_at' => now()], 150, $key);
        $ledger = app(QuotaLedger::class);
        $freeBefore = $ledger->remainingToday()->items;

        // Explicit deletes (not only by FK cascade): visible as one DELETE per table, in that order.
        $deletes = [];
        DB::listen(function ($q) use (&$deletes) {
            if (preg_match('/^delete from "?(\w+)"?/i', $q->sql, $m) === 1) {
                $deletes[] = $m[1];
            }
        });
        $this->actAsOwner($this->channelId)->post(route('proposals.destroy', $key))->assertRedirect(route('proposals.deleted'));

        $this->assertSame(['quota_reservations', 'playlist_creation_items', 'playlist_creations', 'proposal_items', 'proposals'], array_values(array_filter(
            $deletes, fn ($t) => in_array($t, ['quota_reservations', 'playlist_creation_items', 'playlist_creations', 'proposal_items', 'proposals'], true),
        )));
        $this->assertSame(0, QuotaReservation::count());
        $this->assertSame($freeBefore + 3, $ledger->remainingToday()->items);
        $this->assertNull(PlaylistCreation::find($c->id));
    }

    public function test_back_link_points_to_edit_without_creation(): void
    {
        $key = $this->createProposal();
        $this->actAsOwner($this->channelId)->get(route('proposals.delete', $key))->assertOk()
            ->assertSee('<a class="underline" href="edit">削除せずに編集画面へ戻る</a>', false)
            ->assertDontSee(self::PLAYLIST_STAYS)
            ->assertDontSee(self::IN_PROGRESS);
    }
}
