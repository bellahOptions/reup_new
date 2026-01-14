<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CableTvController extends Controller
{
    public function index()
    {
        return view('cable-tv.index', [
            'user' => Auth::user(),
        ]);
    }
    
    public function subscribe(Request $request)
    {
        // Handle cable TV subscription logic
        return redirect()->route('cable-tv.index')
            ->with('success', 'Cable TV subscription successful!');
    }
    
    public function providers()
    {
        return response()->json([
            'dstv' => 'DSTV',
            'gotv' => 'GOTV',
            'startimes' => 'Startimes',
            'showmax' => 'Showmax',
        ]);
    }
}