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
        /*
         * Normalise the phone number before validating it.
         *
         * A new required field will be pasted at least once as `+234 803 123
         * 4567` or `0803-123-4567`. Stripping non-digits first means the regex
         * judges the number rather than the punctuation someone copied.
         *
         * The `234` → `0` step matters more than it looks: stripping the
         * punctuation from `+234 803 123 4567` leaves `2348031234567`, which is
         * a perfectly good number that the Nigerian regex rejects for not
         * starting with `0`. Accepting the international form and storing it the
         * way every other part of the application expects is the difference
         * between "we support +234" and "we reject it with a confusing message".
         */
        $digits = preg_replace('/\D/', '', (string) $request->input('phone'));

        if (preg_match('/^234[7-9][0-9]{9}$/', $digits)) {
            $digits = '0' . substr($digits, 3);
        }

        $request->merge(['phone' => $digits]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            /*
             * Required, deliberately.
             *
             * Two things need it and neither works without it: Paystack will not
             * issue a dedicated virtual account to a customer record with no
             * phone number, so "fund by transfer" silently becomes unavailable;
             * and there is no way to reach an account that has only an email
             * address when something goes wrong.
             *
             * Collecting it here rather than at first use means every new account
             * has one from the moment it exists, which is what retires the "add
             * your phone number" prompt on the virtual-account page for new
             * customers. The prompt remains for accounts created before this.
             *
             * The format follows `ProfileController::update()` exactly — one
             * rule, two entry points — so a number cannot be valid here and
             * invalid there.
             */
            'phone' => [
                'required',
                'string',
                'regex:/^0[7-9][0-9]{9}$/',
                'unique:users,phone',
            ],
            // 8 is the platform floor; the reset flow enforces the same via
            // Rules\Password in NewPasswordController.
            'password' => ['required', 'confirmed', Rules\Password::min(8)],
            // The form has always rendered a consent checkbox; without a rule
            // here it carried no name and was never checked server-side.
            'terms' => ['accepted'],
            'ref' => ['nullable', 'string', 'max:16'],
        ], [
            'terms.accepted' => 'You must accept the terms of service and privacy policy to continue.',
            'phone.required' => 'We need your phone number to open your wallet.',
            'phone.regex' => 'Enter a valid Nigerian phone number, for example 08012345678.',
            'phone.unique' => 'That phone number is already registered to another account.',
        ]);

        // Resolve the referrer before creating the account, so a code that no
        // longer exists simply results in no attribution.
        $referrer = $this->affiliates->userForCode($validated['ref'] ?? null);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
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
