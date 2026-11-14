<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:prune-expired')
    ->dailyAt('04:00')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping();

// Stale session rows (owner tokens included) are gone within about an hour after they become unusable (spec 4.3).
Schedule::command('app:prune-sessions')
    ->hourly()
    ->withoutOverlapping();
