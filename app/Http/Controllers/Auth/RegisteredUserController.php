<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreRegistrationRequest;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\AffiliateService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

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

    public function store(StoreRegistrationRequest $request)
    {
        /*
         * The name and phone rules — and the normalisation that runs before them —
         * live in the form request. This method is about *registering*: resolving
         * the referrer, creating the account, and signing the customer in.
         */
        $validated = $request->validated();

        // Resolve the referrer before creating the account, so a code that no
        // longer exists simply results in no attribution.
        $referrer = $this->affiliates->userForCode($validated['ref'] ?? null);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            // Set once, at sign-up, and only when it is not the user's own code.
            // The reward itself is paid much later, on qualifying funding; this
            // is just the attribution.
            'referred_by_user_id' => $referrer?->id,
        ]);

        /*
         * A name that looks like a placeholder — "asdf", "Test Test", "aaaa" — is
         * flagged for an administrator to glance at. It is **not** a rejection: the
         * heuristic is a guess, the customer is not accused of anything, and the
         * account is fully usable either way. Flagging is deliberately the last
         * thing here so the account exists first and the flag has something to
         * attach to.
         */
        $request->flagNameForReview($user);

        // Everyone gets a code of their own so they can refer others.
        $this->affiliates->ensureCode($user);

        event(new Registered($user));

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // Never trust a user-supplied redirect target on registration.
        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
