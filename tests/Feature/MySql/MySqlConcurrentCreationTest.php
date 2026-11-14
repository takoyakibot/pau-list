<?php

namespace Tests\Feature\MySql;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Models\Proposal;
use App\Models\QuotaReservation;
use App\Services\YouTube\QuotaLedger;
use App\Support\ProposalKey;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\CreatesProposals;
use Tests\Support\RunsMySqlWorkers;
use Tests\TestCase;

/**
 * MySQL only (final review, race tests): real concurrent processes on the creation paths (spec 7.2, 7.3, 7.4).
 * Each worker (tests/Support/mysql/creation-worker.php) fakes Google in its own process and starts at a shared
 * moment, so the operations overlap; the rows are committed (truncation instead of a transaction).
 */
#[Group('mysql')]
class MySqlConcurrentCreationTest extends TestCase
{
    use CreatesProposals;
    use DatabaseTruncation {
        truncateDatabaseTables as private truncateAllTables;
    }
    use RunsMySqlWorkers;

    private const WORKER = 'tests/Support/mysql/creation-worker.php';

    /** @var list<string> */
    protected $exceptTables = ['migrations'];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL only.');
        }
    }

    /** SQLite (in-memory) runs nothing here: the tests are skipped and must not migrate a throwaway database. */
    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->truncateAllTables();
        }
    }

    /** A moment shortly after every worker has booted. */
    private static function startAt(): string
    {
        return sprintf('%.3f', microtime(true) + 2.0);
    }

    /** @return array{result: string, playlist_posts: int, item_posts: int} */
    private static function decode(string $out): array
    {
        $json = json_decode($out, true);

        return is_array($json) ? $json : ['result' => $out, 'playlist_posts' => -1, 'item_posts' => -1];
    }

    private function proposalId(string $key): int
    {
        return Proposal::where('key_hash', ProposalKey::hash($key))->sole()->id;
    }

    /** (a) Two starts of the same proposal at once: exactly one creation and one reservation (spec 7.2). */
    public function test_two_concurrent_starts_create_one_creation_and_one_reservation(): void
    {
        $id = $this->proposalId($this->createProposal());
        $at = self::startAt();

        try {
            $outs = array_map(self::decode(...), $this->runWorkers(self::WORKER, [['start', (string) $id, $at], ['start', (string) $id, $at]]));

            $results = array_column($outs, 'result');
            sort($results);
            $this->assertSame(['created', 'existing'], $results, json_encode($outs));
            $creation = PlaylistCreation::sole();
            $this->assertSame(1, PlaylistCreationItem::where('creation_id', $creation->id)->count());
            $reservation = QuotaReservation::sole();
            $this->assertSame([$creation->id, 100, QuotaReservation::ACTIVE], [$reservation->creation_id, $reservation->reserved_units, $reservation->status]);
        } finally {
            $this->truncateAllTables();
        }
    }

    /**
     * (c) Two steps of the same creation at once while playlists.insert is slow (3 s): the second sees the first's
     * lease and only waits, so exactly one write is sent and counted (spec 7.3, D25).
     */
    public function test_two_concurrent_steps_send_and_count_exactly_one_write(): void
    {
        $creation = $this->makeCreation(1, reserve: 100);
        $at = self::startAt();

        try {
            $outs = array_map(self::decode(...), $this->runWorkers(self::WORKER, [['step', (string) $creation->id, $at], ['step', (string) $creation->id, $at]]));

            $this->assertSame(1, array_sum(array_column($outs, 'playlist_posts')), json_encode($outs));
            $this->assertSame(0, array_sum(array_column($outs, 'item_posts')), json_encode($outs));
            $creation->refresh();
            $this->assertSame([PlaylistCreation::ADDING, 'PLworker'], [$creation->status, $creation->youtube_playlist_id]);
            $this->assertSame(50, QuotaReservation::where('creation_id', $creation->id)->sole()->consumed_units);
            $this->assertSame(50, app(QuotaLedger::class)->used(QuotaLedger::WRITE));
        } finally {
            $this->truncateAllTables();
        }
    }

    /**
     * (e) 「残りを中止」 on one creation while another proposal's creation starts: both finish (no deadlock between
     * creation row → write row → items and the start's write row → inserts).
     */
    public function test_abort_and_another_start_at_once_both_succeed(): void
    {
        $running = $this->makeCreation(3, ['status' => PlaylistCreation::ADDING, 'youtube_playlist_id' => 'PLx'], reserve: 150);
        $other = $this->proposalId($this->createProposal());
        $at = self::startAt();

        try {
            $outs = array_map(self::decode(...), $this->runWorkers(self::WORKER, [['abort', (string) $running->id, $at], ['start', (string) $other, $at]]));

            $this->assertSame(['aborted', 'created'], array_column($outs, 'result'), json_encode($outs));
            $running->refresh();
            $this->assertSame(PlaylistCreation::COMPLETED, $running->status);
            $this->assertSame(3, PlaylistCreationItem::where('creation_id', $running->id)->where('status', PlaylistCreationItem::SKIPPED)->count());
            $this->assertSame(QuotaReservation::RELEASED, QuotaReservation::where('creation_id', $running->id)->sole()->status);
            $started = PlaylistCreation::where('proposal_id', $other)->sole();
            $this->assertSame(QuotaReservation::ACTIVE, QuotaReservation::where('creation_id', $started->id)->sole()->status);
        } finally {
            $this->truncateAllTables();
        }
    }
}
