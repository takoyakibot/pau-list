<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Services\YouTube\GoogleOAuth;
use App\Support\OwnerSession;
use App\Support\PendingLogin;
use App\Support\Pkce;
use App\Support\ProposalKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Google login for channel owners (spec 6.1-6.3, checklist 6.7).
 * The only input read at the start is `p`; the return target is always that proposal (6.7 C3).
 */
class OwnerAuthController extends Controller
{
    public const CODE_PATTERN = '/^[A-Za-z0-9\/._~+-]{1,512}$/D';

    public const ACCESS_DENIED_MESSAGE = 'ログインがキャンセルされました。作成するには、もう一度ログインして YouTube の管理を許可してください';

    public const ERROR_MESSAGE = 'ログインできませんでした。もう一度お試しください';

    public function __construct(
        private readonly OwnerSession $owners,
        private readonly GoogleOAuth $oauth,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $key = $request->query('p');
        if (! is_string($key) || ! ProposalKey::isWellFormed($key)) {
            abort(404);
        }
        Proposal::findLiveOrAbort($key);

        if (! $this->oauth->isConfigured()) {
            abort(503);
        }

        $pending = $this->owners->begin($key);

        // The default redirect body repeats the URL (with the state): send an empty body instead.
        return redirect()->away($this->oauth->authorizationUrl($pending->state, Pkce::challenge($pending->verifier)))
            ->setContent('');
    }

    public function callback(Request $request): Response|RedirectResponse
    {
        // One-time: the pending login is gone whatever happens below (6.7 A4, B3).
        $pending = $this->owners->pullPending();

        if (! $this->oauth->isConfigured()) {
            abort(503);
        }

        $params = [];
        foreach (['state', 'code', 'error', 'iss'] as $name) {
            $value = $request->query->all()[$name] ?? null;
            // Empty values arrive as null (ConvertEmptyStringsToNull) and count as absent.
            if ($value !== null && ! is_string($value)) {
                return $this->invalid($pending);
            }
            $params[$name] = $value;
        }
        ['state' => $state, 'code' => $code, 'error' => $error, 'iss' => $iss] = $params;

        // An authorization response carries either a code or an error, never both (6.7 D4).
        if ($error !== null && $code !== null) {
            return $this->invalid($pending);
        }
        // RFC 9207: when an issuer is given it must be Google's (6.7 D5).
        if ($iss !== null && $iss !== GoogleOAuth::ISSUER) {
            return $this->invalid($pending);
        }
        // 6.7 A3, A5.
        if ($pending === null || $pending->isExpired()
            || $state === null || $state === '' || ! hash_equals($pending->state, $state)) {
            return $this->invalid($pending);
        }

        $key = $pending->proposalKey;
        Proposal::findLiveOrAbort($key);

        if ($error !== null) {
            // error_description and the error code itself are never shown (6.7 F1).
            return redirect()->route('proposals.show', $key)->with(
                'owner_error',
                $error === 'access_denied' ? self::ACCESS_DENIED_MESSAGE : self::ERROR_MESSAGE,
            );
        }

        if ($code === null || preg_match(self::CODE_PATTERN, $code) !== 1) {
            return $this->invalid($pending);
        }

        // Token exchange and channel check: Task 5.
        abort(501);
    }

    /** 400 with a way back: the proposal of the pulled login when it is still live, else the top page. */
    private function invalid(?PendingLogin $pending): Response
    {
        $retryKey = $pending !== null && Proposal::findLiveOr503($pending->proposalKey) !== null
            ? $pending->proposalKey
            : null;

        return response()->view('owner.callback-invalid', ['retryKey' => $retryKey], 400);
    }
}
