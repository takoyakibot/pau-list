<?php

use App\Http\Controllers\ChannelController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\StaticPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/privacy', [StaticPageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [StaticPageController::class, 'terms'])->name('terms');
Route::get('/contact', [ContactController::class, 'show'])->name('contact.show');

Route::get('/channels', [ChannelController::class, 'open'])->name('channels.open');
Route::get('/channels/{channelId}/propose', [ProposalController::class, 'create'])
    ->where('channelId', 'UC[A-Za-z0-9_-]{22}')
    ->name('channels.propose');
