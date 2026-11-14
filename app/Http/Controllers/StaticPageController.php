<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class StaticPageController extends Controller
{
    public function privacy(): View
    {
        return view('static.privacy');
    }

    public function terms(): View
    {
        return view('static.terms');
    }
}
