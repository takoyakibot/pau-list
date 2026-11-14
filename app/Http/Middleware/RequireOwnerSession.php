<?php

namespace App\Http\Middleware;

use App\Models\Proposal;
use App\Support\OwnerSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner-only pages under /p/{key}: the proposal must be live (else the usual 404), the owner must be
 * logged in with an unexpired token (else back to the preview), and the logged-in channel must be the
 * proposal's channel (else the mismatch page; spec 2.4 — no way to continue).
 * On success the request attributes carry `proposal` and `owner`.
 */
class RequireOwnerSession
{
    public const NOTICE = 'ログインの有効期限が切れたか、ログインしていません。もう一度ログインしてください';

    public function __construct(private readonly OwnerSession $owners) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->route('key');
        $proposal = Proposal::findLiveOrAbort($key);

        $owner = $this->owners->current();
        if ($owner === null) {
            return redirect()->route('proposals.show', $key)->with('owner_notice', self::NOTICE);
        }

        if ($owner->channelId !== $proposal->channel_id) {
            return response()->view('owner.mismatch', [
                'key' => $key,
                'proposal' => $proposal,
                'ownerChannelTitle' => $owner->channelTitle,
            ], 403);
        }

        $request->attributes->set('proposal', $proposal);
        $request->attributes->set('owner', $owner);

        return $next($request);
    }
}
