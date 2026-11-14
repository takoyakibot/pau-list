<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireOwnerSession;
use App\Http\Requests\StartCreationRequest;
use App\Models\Proposal;
use App\Services\Creation\CreationStarter;
use App\Services\Creation\InsufficientWriteQuota;
use App\Services\UnavailableVideos;
use App\Services\YouTube\YouTubeApiException;
use App\Support\OwnerLogin;
use App\Support\OwnerSession;
use Illuminate\Http\JsonResponse;

/**
 * S6 and the creation JSON endpoints (spec 7). Every route runs behind RequireSameOriginFetch and
 * RequireOwnerSession, so `proposal` and `owner` are the logged-in owner's own proposal.
 * Redirects are relative (`creation`): the raw key is never written into a response.
 */
class CreationController extends Controller
{
    public const UNAVAILABLE = 'この動画は削除または非公開になっています。外してから作成してください';

    public const YOUTUBE_FAILED = 'YouTube との通信に失敗しました。時間をおいて再度お試しください';

    public function start(StartCreationRequest $request, CreationStarter $starter, OwnerSession $owners): JsonResponse
    {
        /** @var Proposal $proposal */
        $proposal = $request->attributes->get('proposal');
        /** @var OwnerLogin $owner */
        $owner = $request->attributes->get('owner');

        try {
            $started = $starter->start($proposal, $owner, $request->validated());
        } catch (UnavailableVideos $e) {
            return response()->json(['message' => self::UNAVAILABLE, 'unavailable_ids' => $e->ids], 422);
        } catch (InsufficientWriteQuota $e) {
            return response()->json(['message' => $e->remaining->shortageSentence($e->items)], 409);
        } catch (YouTubeApiException $e) {
            return RequireOwnerSession::ownerApiFailureJson($e, $owners, self::YOUTUBE_FAILED);
        }

        if (! $started->created) {
            return response()->json(['redirect' => 'creation']);
        }
        // S6 starts the steps by itself only right after this start (ruling: auto-start only after Create).
        session()->flash('creation_autostart', true);

        return response()->json(['redirect' => 'creation'], 201);
    }
}
