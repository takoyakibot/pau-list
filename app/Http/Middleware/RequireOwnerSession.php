<?php

namespace App\Http\Middleware;

use App\Models\Proposal;
use App\Services\YouTube\YouTubeApiException;
use App\Services\YouTube\YouTubeErrorKind;
use App\Support\OwnerSession;
use Closure;
use Illuminate\Http\JsonResponse;
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

    /** JSON endpoints: the owner is not (or no longer) logged in. The page script offers the re-login. */
    public static function loginRequiredJson(): JsonResponse
    {
        return response()->json(['message' => self::NOTICE], 401);
    }

    /**
     * Maps a failed YouTube call made with the owner's token to JSON. Google's 401 (kind Auth) means the
     * token is no longer accepted: the owner login is dropped and the same 401 as a missing login is
     * returned, so the page asks for a re-login. Anything else is the caller's fixed message.
     * Shared by every owner JSON endpoint (Phase 3 creation steps use it too).
     */
    public static function ownerApiFailureJson(YouTubeApiException $e, OwnerSession $owners, string $message, int $status = 503): JsonResponse
    {
        if ($e->error->kind === YouTubeErrorKind::Auth) {
            $owners->forget();

            return self::loginRequiredJson();
        }

        return response()->json(['message' => $message], $status);
    }

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
