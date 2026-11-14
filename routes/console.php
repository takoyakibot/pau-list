<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:prune-expired')
    ->dailyAt('04:00')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping();
