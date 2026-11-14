<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\StaticPageController;
use App\Support\ProposalKey;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/privacy', [StaticPageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [StaticPageController::class, 'terms'])->name('terms');
Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');

Route::get('/channels', [ChannelController::class, 'open'])->name('channels.open');
Route::get('/channels/{channelId}/propose', [ProposalController::class, 'create'])
    ->where('channelId', 'UC[A-Za-z0-9_-]{22}')
    ->name('channels.propose');

Route::get('/channels/{channelId}/videos/lookup', [ChannelController::class, 'video'])
    ->where('channelId', 'UC[A-Za-z0-9_-]{22}')
    ->middleware('throttle:video-lookup')
    ->name('channels.video');

Route::post('/channels/{channelId}/proposals', [ProposalController::class, 'store'])
    ->where('channelId', 'UC[A-Za-z0-9_-]{22}')
    ->middleware('throttle:proposal-store')
    ->name('proposals.store');

Route::get('/p/{key}/shared', [ProposalController::class, 'shared'])
    ->where('key', ProposalKey::ROUTE_PATTERN)
    ->name('proposals.shared');
