<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A throttled (429) or expired-token (419) proposal save would otherwise end on an error page and
 * lose everything typed. Send the proposer back to the form with the input kept instead.
 */
final class ProposalFormRedirect
{
    /** Returns null (default error page) for other routes, JSON clients and cross-site posts. */
    public static function for(Request $request, string $message): ?RedirectResponse
    {
        if (! $request->routeIs('proposals.store') || $request->expectsJson()) {
            return null;
        }
        // Only for our own form: a cross-site post must not be able to pre-fill a visitor's form.
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null && $site !== 'same-origin') {
            return null;
        }

        return redirect()->route('channels.propose', (string) $request->route('channelId'))
            ->withInput($request->except(['_token', 'website']))
            ->withErrors(['save' => $message]);
    }
}
