<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\AffiliateService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    public function __construct(private AffiliateService $affiliates)
    {
    }

    public function create(Request $request)
    {
        /*
         * Accept a referral code from either the link (?ref=ABC12345) or a
         * field the visitor typed. Carried into the form as a hidden input so it
         * survives the round trip if validation fails and the form re-renders —
         * losing it there would silently cost the referrer their reward.
         *
         * An unknown code is not an error: the visitor still gets an account,
         * they just are not attributed to anyone. Rejecting the sign-up over a
         * mistyped code would be a hostile way to greet a new customer.
         */
        $referralCode = $request->query('ref', $request->input('ref'));

        return view('auth.register', [
            'referralCode' => $this->affiliates->userForCode($referralCode)?->referral_code,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // 8 is the platform floor; the reset flow enforces the same via
            // Rules\Password in NewPasswordController.
            'password' => ['required', 'confirmed', Rules\Password::min(8)],
            // The form has always rendered a consent checkbox; without a rule
            // here it carried no name and was never checked server-side.
            'terms' => ['accepted'],
            'ref' => ['nullable', 'string', 'max:16'],
        ], [
            'terms.accepted' => 'You must accept the terms of service and privacy policy to continue.',
        ]);

        // Resolve the referrer before creating the account, so a code that no
        // longer exists simply results in no attribution.
        $referrer = $this->affiliates->userForCode($validated['ref'] ?? null);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            // Set once, at sign-up, and only when it is not the user's own code.
            // The reward itself is paid much later, on qualifying funding; this
            // is just the attribution.
            'referred_by_user_id' => $referrer?->id,
        ]);

        // Everyone gets a code of their own so they can refer others.
        $this->affiliates->ensureCode($user);

        event(new Registered($user));

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // Never trust a user-supplied redirect target on registration.
        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
