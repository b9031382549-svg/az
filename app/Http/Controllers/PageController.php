<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PageController extends Controller
{
    public function settings(): View
    {
        return view('pages.settings');
    }
}
