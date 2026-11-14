<?php

namespace App\Services;

use App\Models\PlaylistCreation;
use App\Models\PlaylistCreationItem;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\QuotaReservation;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\WriteLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a proposal with its creation records (shared by the owner's deletion and app:delete-proposal).
 *
 * Lock order of 5.4: the creation row first (by primary key), then today's write row (QuotaLedger::withWriteLock).
 * Unused reserved units go back (WriteLock::release) before every record is deleted explicitly, in the order
 * quota_reservations → playlist_creation_items → playlist_creations → proposal_items → proposals (not only by
 * FK cascade). The playlist on YouTube stays.
 *
 * A creation that is not completed blocks the deletion (false, nothing changed) unless $evenIfRunning: the
 * operator deletes on request even during a creation; the owner's next step then finds no proposal (404).
 */
class ProposalDeleter
{
    /** Retries when a creation was started between the creation-row read and the write lock (operator only). */
    private const MAX_RACE_RETRIES = 3;

    public function __construct(private readonly QuotaLedger $ledger) {}

    /**
     * @return bool true when deleted; false when a creation that is not completed blocked it
     *
     * @throws QueryException (the caller logs the SQLSTATE only)
     */
    public function delete(Proposal $proposal, bool $evenIfRunning = false): bool
    {
        $this->ledger->ensureWriteRow();

        for ($attempt = 1; ; $attempt++) {
            $result = DB::transaction(fn () => $this->attempt($proposal, $evenIfRunning), 3);
            if ($result !== null) {
                return $result;
            }
            if ($attempt >= self::MAX_RACE_RETRIES) {
                return false;
            }
        }
    }

    /** True deleted, false blocked, null raced with a creation start (retry: its row must be locked first). */
    private function attempt(Proposal $proposal, bool $evenIfRunning): ?bool
    {
        // The row is locked by its primary key only (no gap lock on proposal_id that a concurrent start's insert,
        // made under the write lock, would wait on while this waits for the write lock).
        $id = PlaylistCreation::where('proposal_id', $proposal->id)->value('id');
        $creation = $id === null ? null : PlaylistCreation::whereKey($id)->lockForUpdate()->first();
        if (! $evenIfRunning && $creation !== null && $creation->status !== PlaylistCreation::COMPLETED) {
            return false;
        }

        return $this->ledger->withWriteLock(function (WriteLock $lock) use ($proposal, $creation, $evenIfRunning) {
            // A creation started meanwhile (CreationStarter inserts it under this lock): the owner's deletion is
            // blocked; the operator's starts over so that the new creation row is locked before the write row.
            // A locking read: it sees rows committed after this transaction's first (snapshot) read.
            if ($creation === null && PlaylistCreation::where('proposal_id', $proposal->id)->sharedLock()->exists()) {
                return $evenIfRunning ? null : false;
            }
            if ($creation !== null) {
                // Unused units go back first, then every record of the creation (not only by FK cascade).
                $lock->release($creation->id);
                QuotaReservation::where('creation_id', $creation->id)->delete();
                PlaylistCreationItem::where('creation_id', $creation->id)->delete();
                $creation->delete();
            }
            ProposalItem::query()->where('proposal_id', $proposal->id)->delete();
            $proposal->delete();

            return true;
        });
    }
}
