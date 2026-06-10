<?php

namespace App\Controllers;

class Pages extends BaseController
{
    public function about(): string
    {
        return view('pages/about');
    }
}
