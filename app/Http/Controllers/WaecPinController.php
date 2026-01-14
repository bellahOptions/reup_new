<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WaecPinController extends Controller
{
    public function index()
    {
        return view('waec-pin.index', [
            'user' => Auth::user(),
        ]);
    }
    
    public function purchase(Request $request)
    {
        // Handle WAEC PIN purchase logic
        return redirect()->route('waec-pin.index')
            ->with('success', 'WAEC PIN purchased successfully!');
    }
}