<?php

namespace App\Http\Controllers;

use App\Models\TermsPrivacy;

class TermsController extends Controller
{
    /**
     * Show Terms of Service page
     */
    public function showTerms()
    {
        $terms = TermsPrivacy::where('type', 'terms')
                           ->where('is_active', true)
                           ->first();
        
        return view('terms-of-service', compact('terms'));
    }
    
    /**
     * Show Privacy Policy page
     */
    public function showPrivacy()
    {
        $privacy = TermsPrivacy::where('type', 'privacy')
                              ->where('is_active', true)
                              ->first();
        
        return view('privacy-policy', compact('privacy'));
    }
}