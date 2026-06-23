<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PageController extends Controller
{
    public function upload(): View
    {
        return view('pages.upload');
    }

    public function settings(): View
    {
        return view('pages.settings');
    }
}
