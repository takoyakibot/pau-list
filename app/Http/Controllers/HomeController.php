<?php

namespace App\Http\Controllers;

use App\Support\QuotaPolicy;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('home', [
            'maxItems' => QuotaPolicy::maxItemsPerPlaylist(),
            'retentionDays' => (int) config('listmaker.retention_days'),
        ]);
    }
}
