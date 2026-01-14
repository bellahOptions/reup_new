<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ElectricityController extends Controller
{
    public function index()
    {
        return view('electricity.index', [
            'user' => Auth::user(),
        ]);
    }
    
    public function pay(Request $request)
    {
        // Handle electricity bill payment logic
        return redirect()->route('electricity.index')
            ->with('success', 'Electricity bill payment successful!');
    }
    
    public function discos()
    {
        return response()->json([
            'ikedc' => 'IKEDC - Ikeja Electric',
            'ekedc' => 'EKEDC - Eko Electric',
            'phed' => 'PHED - Port Harcourt Electric',
            'aedc' => 'AEDC - Abuja Electric',
            'kedco' => 'KEDCO - Kano Electric',
            'ibedc' => 'IBEDC - Ibadan Electric',
            'eedc' => 'EEDC - Enugu Electric',
            'jed' => 'JED - Jos Electric',
        ]);
    }
}