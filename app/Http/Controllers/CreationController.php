<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireOwnerSession;
use App\Http\Requests\StartCreationRequest;
use App\Models\PlaylistCreation;
use App\Models\Proposal;
use App\Services\Creation\CreationBusy;
use App\Services\Creation\CreationLifecycle;
use App\Services\Creation\CreationProgress;
use App\Services\Creation\CreationRunner;
use App\Services\Creation\CreationStarter;
use App\Services\Creation\GoogleQuotaNotReset;
use App\Services\Creation\InsufficientWriteQuota;
use App\Services\Creation\ProposalGone;
use App\Services\UnavailableVideos;
use App\Services\YouTube\QuotaLedger;
use App\Services\YouTube\YouTubeApiException;
use App\Support\OwnerLogin;
use App\Support\OwnerSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * S6 and the creation JSON endpoints (spec 7). Every route runs behind RequireSameOriginFetch and
 * RequireOwnerSession, so `proposal` and `owner` are the logged-in owner's own proposal.
 * Redirects are relative (`creation`): the raw key is never written into a response.
 */
class CreationController extends Controller
{
    public const UNAVAILABLE = 'この動画は削除または非公開になっています。外してから作成してください';

    public const YOUTUBE_FAILED = 'YouTube との通信に失敗しました。時間をおいて再度お試しください';

    public const NOT_STARTED = '作成は始まっていません。編集画面から作成してください';

    /**
     * S6. Only the logged-in owner of the creation's channel sees it (it lists the owner's unlisted video titles).
     * The page script runs the steps; the server renders the current state so the page reads without JS.
     */
    public function show(Request $request, QuotaLedger $ledger): Response|RedirectResponse
    {
        /** @var Proposal $proposal */
        $proposal = $request->attributes->get('proposal');
        /** @var OwnerLogin $owner */
        $owner = $request->attributes->get('owner');
        $key = (string) $request->route('key');

        $creation = $proposal->creation;
        if ($creation === null) {
            return redirect()->route('proposals.edit', $key);
        }
        if ($creation->owner_channel_id !== $owner->channelId) {
            return response()->view('owner.mismatch', [
                'key' => $key,
                'proposal' => $proposal,
                'ownerChannelTitle' => $owner->channelTitle,
            ], 403);
        }

        $progress = CreationProgress::toArray($creation, $ledger->remainingToday(), ownerLoggedIn: true);
        $titles = $creation->items()->pluck('title', 'position');

        return response()->view('owner.creation', [
            'proposal' => $proposal,
            'creation' => $creation,
            'ownerChannelTitle' => $owner->channelTitle,
            'progress' => $progress,
            'titles' => $titles,
            'runner' => [
                'progress' => $progress,
                // Steps start by themselves only right after 「作成」 (flashed by start()), not on a reload.
                'autostart' => session('creation_autostart') === true,
                // Relative to /p/{key}/creation: the raw key is never written into the page.
                'urls' => [
                    'step' => 'creation/step',
                    'pause' => 'creation/pause',
                    'resume' => 'creation/resume',
                    'abort' => 'creation/abort',
                    'retry' => 'creation/retry',
                    'recreate' => 'creation/recreate',
                ],
                // titles[position]: the progress JSON carries no titles (it is sent every second).
                'titles' => array_values($titles->all()),
            ],
        ]);
    }

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
        } catch (ProposalGone) {
            return response()->json(['message' => ProposalGone::MESSAGE], 404);
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

    /**
     * One step (spec 7.3): at most one YouTube write. Google's 401 logs the owner out (spec 7.2) and answers
     * like a missing login; a playlist name/description refused by YouTube deleted the creation (back to S5).
     */
    public function step(Request $request, CreationRunner $runner, QuotaLedger $ledger, OwnerSession $owners): JsonResponse
    {
        $creation = $this->creationOf($request);
        if ($creation instanceof JsonResponse) {
            return $creation;
        }
        /** @var OwnerLogin $owner */
        $owner = $request->attributes->get('owner');

        $result = $runner->step($creation, $owner);
        if ($result->authExpired) {
            $owners->forget();

            return RequireOwnerSession::loginRequiredJson();
        }
        $fresh = $creation->fresh();
        if ($result->redirect !== null || $fresh === null) {
            return response()->json(['redirect' => $result->redirect ?? 'edit', 'message' => $result->message]);
        }

        $progress = CreationProgress::toArray($fresh, $ledger->remainingToday(), ownerLoggedIn: true);

        return response()->json(array_merge($progress, [
            'next_delay_ms' => $result->nextDelayMs,
            'message' => $result->message ?? $progress['message'],
        ]));
    }

    public function pause(Request $request, CreationLifecycle $lifecycle, QuotaLedger $ledger): JsonResponse
    {
        return $this->operate($request, $ledger, fn (PlaylistCreation $c) => $lifecycle->pause($c));
    }

    public function resume(Request $request, CreationLifecycle $lifecycle, QuotaLedger $ledger): JsonResponse
    {
        return $this->operate($request, $ledger, fn (PlaylistCreation $c) => $lifecycle->resume($c));
    }

    public function abort(Request $request, CreationLifecycle $lifecycle, QuotaLedger $ledger): JsonResponse
    {
        return $this->operate($request, $ledger, fn (PlaylistCreation $c) => $lifecycle->abort($c));
    }

    public function retry(Request $request, CreationLifecycle $lifecycle, QuotaLedger $ledger): JsonResponse
    {
        return $this->operate($request, $ledger, fn (PlaylistCreation $c) => $lifecycle->retryFailed($c));
    }

    public function recreate(Request $request, CreationLifecycle $lifecycle, QuotaLedger $ledger): JsonResponse
    {
        return $this->operate($request, $ledger, fn (PlaylistCreation $c) => $lifecycle->recreate($c));
    }

    /** @param callable(PlaylistCreation): void $operation */
    private function operate(Request $request, QuotaLedger $ledger, callable $operation): JsonResponse
    {
        $creation = $this->creationOf($request);
        if ($creation instanceof JsonResponse) {
            return $creation;
        }

        try {
            $operation($creation);
        } catch (InsufficientWriteQuota $e) {
            return response()->json(['message' => $e->remaining->shortageSentence($e->items)], 409);
        } catch (GoogleQuotaNotReset $e) {
            return response()->json(['message' => $e->sentence()], 409);
        } catch (CreationBusy) {
            return response()->json(['message' => CreationBusy::MESSAGE], 409);
        }

        $fresh = $creation->fresh();
        if ($fresh === null) {
            return response()->json(['message' => self::NOT_STARTED], 404);
        }

        return response()->json(CreationProgress::toArray($fresh, $ledger->remainingToday(), ownerLoggedIn: true));
    }

    /**
     * The proposal's creation, or the JSON error: 404 without one, 403 when the creation belongs to another
     * channel than the logged-in owner (spec 6.4: checked again here, not only the proposal's channel).
     */
    private function creationOf(Request $request): PlaylistCreation|JsonResponse
    {
        /** @var Proposal $proposal */
        $proposal = $request->attributes->get('proposal');
        /** @var OwnerLogin $owner */
        $owner = $request->attributes->get('owner');

        $creation = $proposal->creation;
        if ($creation === null) {
            return response()->json(['message' => self::NOT_STARTED], 404);
        }
        if ($creation->owner_channel_id !== $owner->channelId) {
            return response()->json(['message' => RequireOwnerSession::MISMATCH], 403);
        }

        return $creation;
    }
}
