<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TipsController extends Controller
{
    /**
     * Mark the first sign-in introduction as seen.
     *
     * Both "Got it" and the skip button post here. They are treated identically
     * on purpose: a user who dismisses an introduction has told us they do not
     * want to see it, and re-showing it because they left via the X instead of
     * the final step would be obnoxious.
     */
    public function dismiss(Request $request)
    {
        $user = $request->user();

        if ($user && $user->tips_seen_at === null) {
            $user->forceFill(['tips_seen_at' => now()])->save();
        }

        return redirect()->route('dashboard')
            ->with('success', 'You can revisit these tips any time from the help menu.');
    }

    /**
     * Show the introduction again.
     *
     * Clearing the timestamp rather than storing a second flag keeps a single
     * source of truth for "has this person been introduced".
     */
    public function replay(Request $request)
    {
        $user = $request->user();

        if ($user) {
            $user->forceFill(['tips_seen_at' => null])->saveQuietly();
        }

        return redirect()->route('dashboard');
    }
}
