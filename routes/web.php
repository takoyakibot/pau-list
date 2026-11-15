<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CreationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OwnerAuthController;
use App\Http\Controllers\OwnerDeleteController;
use App\Http\Controllers\OwnerEditController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\StaticPageController;
use App\Http\Middleware\RejectCrossSiteRequest;
use App\Http\Middleware\RequireDocumentNavigation;
use App\Http\Middleware\RequireOwnerSession;
use App\Http\Middleware\RequireSameOriginFetch;
use App\Support\ProposalKey;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/privacy', [StaticPageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [StaticPageController::class, 'terms'])->name('terms');
Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');
// S8: mailed only, never stored. Sends are limited in the controller (ContactLimits).
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store');

Route::get('/channels', [ChannelController::class, 'open'])
    ->middleware(RequireDocumentNavigation::class)
    ->name('channels.open');
Route::get('/channels/{channelId}/propose', [ProposalController::class, 'create'])
    ->where('channelId', 'UC[A-Za-z0-9_-]{22}')
    ->middleware(RequireDocumentNavigation::class)
    ->name('channels.propose');

Route::get('/channels/{channelId}/videos/lookup', [ChannelController::class, 'video'])
    ->where('channelId', 'UC[A-Za-z0-9_-]{22}')
    // The origin check runs before the limiter: rejected requests must not use up the per-IP limit.
    ->middleware([RequireSameOriginFetch::class, 'throttle:video-lookup'])
    ->name('channels.video');

Route::post('/channels/{channelId}/proposals', [ProposalController::class, 'store'])
    ->where('channelId', 'UC[A-Za-z0-9_-]{22}')
    // Every POST: 20 per 10 minutes per IP. Successful saves are limited in the controller (ProposalStoreLimits).
    ->middleware('throttle:proposal-post')
    ->name('proposals.store');

Route::get('/p/{key}/shared', [ProposalController::class, 'shared'])
    ->where('key', ProposalKey::ROUTE_PATTERN)
    ->name('proposals.shared');

Route::get('/p/{key}', [ProposalController::class, 'show'])
    ->where('key', ProposalKey::ROUTE_PATTERN)
    ->name('proposals.show');

Route::get('/p/{key}/edit', [OwnerEditController::class, 'show'])
    ->where('key', ProposalKey::ROUTE_PATTERN)
    ->middleware([RequireDocumentNavigation::class, RequireOwnerSession::class])
    ->name('proposals.edit');

// Owner-only deletion (user decision #12): a confirmation page, then a CSRF-protected POST.
Route::get('/p/{key}/delete', [OwnerDeleteController::class, 'confirm'])
    ->where('key', ProposalKey::ROUTE_PATTERN)
    ->middleware([RequireDocumentNavigation::class, RequireOwnerSession::class])
    ->name('proposals.delete');
Route::post('/p/{key}/deletion', [OwnerDeleteController::class, 'destroy'])
    ->where('key', ProposalKey::ROUTE_PATTERN)
    ->middleware(RequireOwnerSession::class)
    ->name('proposals.destroy');
// S6: the owner's progress page (spec 2.5, 7.4, 7.5).
Route::get('/p/{key}/creation', [CreationController::class, 'show'])
    ->where('key', ProposalKey::ROUTE_PATTERN)
    ->middleware([RequireDocumentNavigation::class, RequireOwnerSession::class])
    ->name('proposals.creation');
// Creation (spec 7): JSON from S5. The origin check runs before the limiter, the limiter before the owner check
// (the limiter key is the logged-in channel, shared with the retry of failed items).
Route::post('/p/{key}/creation', [CreationController::class, 'start'])
    ->where('key', ProposalKey::ROUTE_PATTERN)
    ->middleware([RequireSameOriginFetch::class, 'throttle:creation-start', RequireOwnerSession::class])
    ->name('proposals.creation.start');
// S6 operations (spec 7.3–7.5), all JSON POSTs: origin check, then the limiter (step: per session; retry shares
// the start limit; pause/resume/abort/recreate share a per-session limit), then the owner check.
foreach ([
    'step' => 'throttle:creation-step',
    'pause' => 'throttle:creation-operation',
    'resume' => 'throttle:creation-operation',
    'abort' => 'throttle:creation-operation',
    'retry' => 'throttle:creation-start',
    'recreate' => 'throttle:creation-operation',
] as $action => $throttle) {
    Route::post("/p/{key}/creation/$action", [CreationController::class, $action])
        ->where('key', ProposalKey::ROUTE_PATTERN)
        ->middleware([RequireSameOriginFetch::class, $throttle, RequireOwnerSession::class])
        ->name("proposals.creation.$action");
}

// S5 add-by-URL (owner only): public videos as for proposers, plus the owner's own unlisted videos (user
// decision #9). Origin check, then the limiter (per owner channel), then the owner check.
Route::get('/p/{key}/videos/lookup', [OwnerEditController::class, 'lookup'])
    ->where('key', ProposalKey::ROUTE_PATTERN)
    ->middleware([RequireSameOriginFetch::class, 'throttle:owner-video-lookup', RequireOwnerSession::class])
    ->name('proposals.video');

Route::get('/deleted', [OwnerDeleteController::class, 'deleted'])->name('proposals.deleted');

Route::get('/owner/videos/unlisted', [OwnerEditController::class, 'unlisted'])
    // The origin check runs before the limiter: rejected requests must not use up the owner's limit.
    ->middleware([RequireSameOriginFetch::class, 'throttle:owner-unlisted'])
    ->name('owner.unlisted');

Route::get('/auth/google', [OwnerAuthController::class, 'redirect'])
    ->middleware([RequireDocumentNavigation::class, RejectCrossSiteRequest::class, 'throttle:oauth'])
    ->name('owner.login');
Route::get('/auth/google/callback', [OwnerAuthController::class, 'callback'])
    ->middleware([RequireDocumentNavigation::class, 'throttle:oauth'])
    ->name('owner.callback');
Route::post('/logout', [OwnerAuthController::class, 'logout'])->name('owner.logout');
Route::get('/logged-out', [OwnerAuthController::class, 'loggedOut'])->name('owner.logged-out');
