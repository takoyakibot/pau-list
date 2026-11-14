<?php

namespace App\Http\Controllers;

use App\Models\PlaylistCreation;
use App\Models\Proposal;
use App\Services\ProposalDeleter;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * User decision #12: the channel owner may delete a proposal addressed to their channel before it expires.
 * GET shows a confirmation page; only the POST from that page (CSRF-protected) deletes.
 * Access is checked by RequireOwnerSession (live proposal, logged-in owner, matching channel).
 *
 * Y19 (U14): while its creation is not completed, a proposal cannot be deleted (the owner is sent to S6 to stop
 * the rest first). After completion the proposal and its creation records are deleted together; the playlist on
 * YouTube stays.
 */
class OwnerDeleteController extends Controller
{
    public const IN_PROGRESS = '作成中の提案は削除できません。作成状況の画面で「残りを中止」してから削除してください';

    public function confirm(Request $request): Response
    {
        /** @var Proposal $proposal */
        $proposal = $request->attributes->get('proposal');
        $creation = $proposal->creation;

        return response()->view('owner.delete-confirm', [
            'proposal' => $proposal,
            'hasCreation' => $creation !== null,
            'blocked' => $creation !== null && $creation->status !== PlaylistCreation::COMPLETED,
        ]);
    }

    public function destroy(Request $request, ProposalDeleter $deleter): RedirectResponse
    {
        /** @var Proposal $proposal */
        $proposal = $request->attributes->get('proposal');

        try {
            // Y19: a creation that is not completed blocks the owner's deletion (unlike the operator's command).
            $deleted = $deleter->delete($proposal);
        } catch (QueryException $e) {
            // The exception message contains SQL bindings: log only the SQLSTATE.
            Log::error('Proposal delete failed (database)', ['sqlstate' => (string) $e->getCode()]);
            abort(503);
        }

        if (! $deleted) {
            return redirect()->route('proposals.creation', (string) $request->route('key'))
                ->with('creation_notice', self::IN_PROGRESS);
        }

        // Never log the raw key or the full hash.
        Log::info('Proposal deleted by owner', ['key_hash_prefix' => substr($proposal->key_hash, 0, 8)]);

        // The deleted page clears this tab's edit draft (same id as OwnerEditController's draftId).
        return redirect()->route('proposals.deleted')
            ->with('deleted_draft_id', substr($proposal->key_hash, 0, 16));
    }

    public function deleted(Request $request): Response
    {
        $draftId = $request->session()->get('deleted_draft_id');

        return response()->view('owner.deleted', [
            'draftId' => is_string($draftId) && preg_match('/^[0-9a-f]{16}$/D', $draftId) === 1 ? $draftId : null,
        ]);
    }
}
