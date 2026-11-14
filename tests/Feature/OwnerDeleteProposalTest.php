<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Services\YouTube\YouTubeReader;
use App\Support\OwnerSession;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
