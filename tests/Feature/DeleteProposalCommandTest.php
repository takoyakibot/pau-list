<?php

namespace Tests\Feature;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\QuotaReservation;
use App\Services\ProposalDeleter;
use App\Services\YouTube\QuotaLedger;
use App\Support\ProposalKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesProposals;
use Tests\TestCase;

/**
 * Spec 9.x / Phase 4 Task 3: the operator deletes a proposal on request (within 7 days) by its URL or key.
 * Unlike the owner's deletion (Y19), a running creation is deleted too (its reservation is released).
 */
class DeleteProposalCommandTest extends TestCase
{
    use CreatesProposals, RefreshDatabase;

    private const CONFIRM = 'この提案を削除しますか？（作成中の場合は作成も止めて削除します。YouTube の再生リストは残ります）';

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 03:00:00'));
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
    }

    public function test_deletes_by_key_with_force(): void
    {
        $key = $this->createProposal();

        $this->artisan('app:delete-proposal', ['target' => $key, '--force' => true])
            ->expectsOutput('deleted')
            ->doesntExpectOutputToContain($key)
            ->doesntExpectOutputToContain(ProposalKey::hash($key))
            ->assertSuccessful();

        $this->assertSame(0, Proposal::count());
        $this->assertSame(0, ProposalItem::count());
    }

    public function test_shows_summary_without_key(): void
    {
        $key = $this->createProposal();

        $this->artisan('app:delete-proposal', ['target' => $key])
            ->expectsOutputToContain('UCaaaaaa')
            ->expectsOutputToContain('私の再生リスト')
            ->expectsOutputToContain('2026-11-15')
            ->expectsOutputToContain('なし')
            ->doesntExpectOutputToContain($this->channelId)
            ->doesntExpectOutputToContain($key)
            ->doesntExpectOutputToContain(substr(ProposalKey::hash($key), 0, 8))
            ->expectsConfirmation(self::CONFIRM, 'no')
            ->expectsOutput('cancelled')
            ->assertFailed();
    }

    public function test_deletes_by_url_after_confirmation(): void
    {
        $key = $this->createProposal();
        $other = $this->createProposal();

        $this->artisan('app:delete-proposal', ['target' => url("/p/$key/edit")])
            ->expectsConfirmation(self::CONFIRM, 'yes')
            ->expectsOutput('deleted')
            ->assertSuccessful();

        $this->assertFalse(Proposal::where('key_hash', ProposalKey::hash($key))->exists());
        $this->assertTrue(Proposal::where('key_hash', ProposalKey::hash($other))->exists());
    }

    public function test_declining_or_non_interactive_deletes_nothing(): void
    {
        $key = $this->createProposal();

        $this->artisan('app:delete-proposal', ['target' => $key])
            ->expectsConfirmation(self::CONFIRM, 'no')
            ->expectsOutput('cancelled')
            ->assertFailed();
        $this->artisan('app:delete-proposal', ['target' => $key, '--no-interaction' => true])
            ->expectsOutput('cancelled')
            ->assertFailed();

        $this->assertSame(1, Proposal::count());
        $this->assertSame(2, ProposalItem::count());
    }

    public function test_expired_but_not_yet_pruned_proposal_is_deleted(): void
    {
        $key = $this->createProposal(['created_at' => now()->subDays(31), 'expires_at' => now()->subDay()]);

        $this->artisan('app:delete-proposal', ['target' => $key, '--force' => true])
            ->expectsOutput('deleted')
            ->assertSuccessful();

        $this->assertSame(0, Proposal::count());
    }

    public function test_unknown_or_malformed_target_is_not_found(): void
    {
        $this->createProposal();

        foreach ([ProposalKey::generate(), 'abc', 'https://example.com/p/abc', ''] as $target) {
            $this->artisan('app:delete-proposal', ['target' => $target, '--force' => true])
                ->expectsOutput('proposal not found')
                ->assertFailed();
        }

        $this->assertSame(1, Proposal::count());
        $this->assertSame(2, ProposalItem::count());
    }

    public function test_running_creation_is_deleted_and_next_step_is_404(): void
    {
        $ledger = app(QuotaLedger::class);
        $ledger->ensureWriteRow();
        $freeBefore = $ledger->writeFreeUnits();
        $key = $this->createProposal();
        $this->makeCreation(3, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], reserve: 200, key: $key);
        $other = $this->makeCreation(1, reserve: 100);
        $this->assertSame($freeBefore - 300, $ledger->writeFreeUnits());

        $this->artisan('app:delete-proposal', ['target' => $key, '--force' => true])
            ->expectsOutputToContain('adding')
            ->expectsOutput('deleted')
            ->assertSuccessful();

        $this->assertSame(0, QuotaReservation::where('creation_id', '!=', $other->id)->count());
        $this->assertSame(0, PlaylistCreationItem::where('creation_id', '!=', $other->id)->count());
        $this->assertSame([$other->id], PlaylistCreation::pluck('id')->all());
        $this->assertSame($freeBefore - 100, $ledger->writeFreeUnits());
        $this->assertFalse(Proposal::where('key_hash', ProposalKey::hash($key))->exists());

        $this->actAsOwner($this->channelId)->withCredentials()
            ->postJson("/p/$key/creation/step", [], ['Sec-Fetch-Site' => 'same-origin'])
            ->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_completed_creation_is_deleted_too(): void
    {
        $key = $this->createProposal();
        $this->makeCreation(2, ['status' => PlaylistCreation::COMPLETED, 'youtube_playlist_id' => 'PLx'], key: $key);

        $this->artisan('app:delete-proposal', ['target' => $key, '--force' => true])
            ->expectsOutputToContain('completed')
            ->expectsOutput('deleted')
            ->assertSuccessful();

        $this->assertSame(0, Proposal::count());
        $this->assertSame(0, PlaylistCreation::count());
        $this->assertSame(0, PlaylistCreationItem::count());
    }

    public function test_log_has_hash_prefix_only(): void
    {
        $key = $this->createProposal();
        $hash = ProposalKey::hash($key);

        $this->artisan('app:delete-proposal', ['target' => url("/p/$key"), '--force' => true])->assertSuccessful();

        $entry = collect($this->logs)->firstWhere('message', 'Proposal deleted by operator');
        $this->assertNotNull($entry);
        $this->assertSame('info', $entry['level']);
        $this->assertSame(['key_hash_prefix' => substr($hash, 0, 8), 'creation' => null], $entry['context']);
        $all = json_encode($this->logs, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($key, $all);
        $this->assertStringNotContainsString($hash, $all);
    }

    public function test_log_has_creation_status(): void
    {
        $key = $this->createProposal();
        $this->makeCreation(1, ['status' => PlaylistCreation::PAUSED, 'pause_reason' => PlaylistCreation::PAUSE_USER], reserve: 100, key: $key);

        $this->artisan('app:delete-proposal', ['target' => $key, '--force' => true])->assertSuccessful();

        $entry = collect($this->logs)->firstWhere('message', 'Proposal deleted by operator');
        $this->assertSame(PlaylistCreation::PAUSED, $entry['context']['creation']);
    }

    public function test_database_failure_logs_sqlstate_only(): void
    {
        $key = $this->createProposal();
        DB::listen(function ($q) {
            if (str_starts_with(strtolower($q->sql), 'delete from "proposals"')) {
                throw new QueryException('sqlite', $q->sql, $q->bindings, new \PDOException('boom'));
            }
        });

        $this->artisan('app:delete-proposal', ['target' => $key, '--force' => true])
            ->expectsOutput('failed')
            ->assertFailed();

        $entry = collect($this->logs)->firstWhere('message', 'Proposal delete failed (database)');
        $this->assertNotNull($entry);
        $this->assertSame(['sqlstate'], array_keys($entry['context']));
        $this->assertStringNotContainsString(ProposalKey::hash($key), json_encode($this->logs));
    }

    public function test_repeated_race_with_a_creation_start_fails_and_warns_without_key(): void
    {
        $key = $this->createProposal();
        $this->instance(ProposalDeleter::class, new class(app(QuotaLedger::class)) extends ProposalDeleter
        {
            public function delete(Proposal $proposal, bool $evenIfRunning = false): bool
            {
                return false;
            }
        });

        $this->artisan('app:delete-proposal', ['target' => $key, '--force' => true])
            ->expectsOutput('failed')
            ->assertFailed();

        $entry = collect($this->logs)->firstWhere('message', 'Proposal delete raced');
        $this->assertNotNull($entry);
        $this->assertSame('warning', $entry['level']);
        $this->assertSame([], $entry['context']);
        $this->assertNull(collect($this->logs)->firstWhere('message', 'Proposal deleted by operator'));
        $this->assertSame(1, Proposal::count());
    }
}
