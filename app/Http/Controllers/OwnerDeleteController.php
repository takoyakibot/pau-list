<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * User decision #12: the channel owner may delete a proposal addressed to their channel before it expires.
 * GET shows a confirmation page; only the POST from that page (CSRF-protected) deletes.
 * Access is checked by RequireOwnerSession (live proposal, logged-in owner, matching channel).
 */
class OwnerDeleteController extends Controller
{
    public function confirm(Request $request): Response
    {
        return response()->view('owner.delete-confirm', [
            'proposal' => $request->attributes->get('proposal'),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        /** @var Proposal $proposal */
        $proposal = $request->attributes->get('proposal');

        try {
            DB::transaction(function () use ($proposal) {
                ProposalItem::query()->where('proposal_id', $proposal->id)->delete();
                $proposal->delete();
            });
        } catch (QueryException $e) {
            // The exception message contains SQL bindings: log only the SQLSTATE.
            Log::error('Proposal delete failed (database)', ['sqlstate' => (string) $e->getCode()]);
            abort(503);
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
