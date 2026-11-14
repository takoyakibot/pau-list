<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Support\OwnerLogin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** S5: the owner's edit screen (display only in Phase 2; no writes). */
class OwnerEditController extends Controller
{
    public function show(Request $request, string $key): Response
    {
        /** @var Proposal $proposal */
        $proposal = $request->attributes->get('proposal');
        /** @var OwnerLogin $owner */
        $owner = $request->attributes->get('owner');

        return response()->view('owner.edit', [
            'key' => $key,
            'proposal' => $proposal,
            'ownerChannelTitle' => $owner->channelTitle,
        ]);
    }
}
