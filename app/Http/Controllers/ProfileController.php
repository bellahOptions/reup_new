<?php

namespace App\Http\Controllers;

use App\Services\OtpLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        
        // Get Nigerian states
        $states = [
            'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 
            'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT', 'Gombe', 'Imo', 
            'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 
            'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara'
        ];
        
        return view('profile.index', [
            'user' => $user,
            'states' => $states,
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        
        // Clean phone numbers before validation
        $request->merge([
            'phone' => preg_replace('/\D/', '', $request->phone),
            'whatsapp' => preg_replace('/\D/', '', $request->whatsapp),
        ]);
        
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'regex:/^0[7-9][0-9]{9}$/', Rule::unique('users')->ignore($user->id)],
            'whatsapp' => ['nullable', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
            'birthday' => ['nullable', 'date', 'before:-18 years'],
            'gender' => ['nullable', 'in:male,female,other'],
            'address' => ['nullable', 'string', 'max:500'],
            'state' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'profile_picture' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            // Avatar choices are validated against the configured keys rather
            // than accepted as free text, so a hand-crafted POST cannot store a
            // colour or glyph the renderer does not know about.
            'avatar_color' => ['nullable', 'string', Rule::in(array_keys(config('avatars.colors', [])))],
            'avatar_icon' => ['nullable', 'string', Rule::in(array_keys(config('avatars.icons', [])))],
        ], [
            'phone.regex' => 'Please enter a valid Nigerian phone number (e.g., 08012345678)',
            'whatsapp.regex' => 'Please enter a valid Nigerian phone number for WhatsApp',
            'birthday.before' => 'You must be at least 18 years old',
        ]);

        // An empty string from the "Initials" radio means "no glyph", which is
        // null in the column rather than an empty string that would then fail
        // the `in:` rule on the way back out.
        $validated['avatar_icon'] = $validated['avatar_icon'] ?? null;

        // Handle profile picture upload (SIMPLIFIED - without Intervention Image)
        if ($request->hasFile('profile_picture')) {
            $image = $request->file('profile_picture');
            $filename = 'profile-' . $user->id . '-' . time() . '.' . $image->getClientOriginalExtension();
            
            // Store the image (no resizing)
            $path = $image->storeAs('profiles', $filename, 'public');
            
            // Delete old profile picture if exists
            if ($user->profile_picture) {
                Storage::disk('public')->delete('profiles/' . basename($user->profile_picture));
            }
            
            $validated['profile_picture'] = '/storage/profiles/' . $filename;
        }

        // Update user
        $user->update($validated);

        // Check if profile is now completed
        if (!$user->profile_completed && $user->profile_completion_percentage >= 80) {
            $user->profile_completed = true;
            $user->save();
        }

        return redirect()->route('profile.index')
            ->with('success', 'Profile updated successfully!')
            ->with('phone_alert', empty($user->phone));
    }

    public function updateNotifications(Request $request)
    {
        $validated = $request->validate([
            'notifications' => ['required', 'array'],
            'notifications.email' => ['nullable', 'array'],
            'notifications.sms' => ['nullable', 'array'],
            'notifications.push' => ['nullable', 'array'],
        ]);

        $user = auth()->user();
        
        // Merge with existing preferences
        $currentPreferences = $user->notification_preferences ?? [];
        $updatedPreferences = array_merge($currentPreferences, $validated['notifications']);
        
        $user->update(['notification_preferences' => $updatedPreferences]);

        return back()->with('success', 'Notification preferences updated!');
    }

    /**
     * Start phone verification by issuing a one-time code.
     *
     * The code lives in the cache with a short TTL and is bound to the user id,
     * so it cannot be replayed or brute-forced against another account.
     *
     * NOTE: no SMS gateway is wired up. Outside production the code is returned
     * in the response and written to the log so the flow is testable; in
     * production `debug_code` is always null and delivery must be added before
     * this endpoint is useful.
     */
    public function requestPhoneVerification(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^0[7-9][0-9]{9}$/'],
        ]);

        $user = auth()->user();

        // Someone else's verified number must not be claimable.
        $taken = \App\Models\User::where('phone', $validated['phone'])
            ->where('id', '!=', $user->id)
            ->exists();

        if ($taken) {
            return response()->json([
                'success' => false,
                'message' => 'That phone number is already registered to another account.',
            ], 422);
        }

        $code = (string) random_int(100000, 999999);

        Cache::put('phone.verify.' . $user->id, [
            'code' => Hash::make($code),
            'phone' => $validated['phone'],
            'attempts' => 0,
        ], now()->addMinutes(10));

        if (! app()->environment('production')) {
            Log::info('Phone verification code issued (non-production)', [
                'user_id' => $user->id,
                'code' => $code,
            ]);
        }

        // Store the number as unverified so the UI can show it as pending.
        $user->forceFill(['phone' => $validated['phone']])->save();

        return response()->json([
            'success' => true,
            'message' => 'We sent a 6-digit code to ' . $validated['phone'] . '.',
            'expires_in' => 600,
            'debug_code' => app()->environment('production') ? null : $code,
        ]);
    }

    /**
     * Complete phone verification with the issued code.
     */
    public function verifyPhone(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
        ]);

        $user = auth()->user();
        $cacheKey = 'phone.verify.' . $user->id;
        $pending = Cache::get($cacheKey);

        if (! $pending) {
            return response()->json([
                'success' => false,
                'message' => 'That code has expired. Request a new one.',
            ], 422);
        }

        if (($pending['attempts'] ?? 0) >= 5) {
            Cache::forget($cacheKey);

            return response()->json([
                'success' => false,
                'message' => 'Too many incorrect attempts. Request a new code.',
            ], 429);
        }

        if (! Hash::check($validated['code'], $pending['code'])) {
            $pending['attempts'] = ($pending['attempts'] ?? 0) + 1;
            Cache::put($cacheKey, $pending, now()->addMinutes(10));

            return response()->json([
                'success' => false,
                'message' => 'That code is not correct.',
                'attempts_remaining' => max(0, 5 - $pending['attempts']),
            ], 422);
        }

        Cache::forget($cacheKey);

        $user->forceFill([
            'phone' => $pending['phone'],
            'phone_verified_at' => now(),
            'requires_phone_update' => false,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Phone number verified.',
        ]);
    }

    /**
     * Email a one-time code to authorise a PIN set or change.
     *
     * The PIN authorises every purchase, so changing it is the highest-value
     * action an attacker with a hijacked session can take: set a PIN they know,
     * then drain the wallet. Requiring a code delivered to the account's email
     * means a stolen session alone is not enough.
     *
     * The code lives in the `pin` purpose, so it is independent of any sign-in
     * code in flight and cannot be swapped for one.
     */
    public function requestPinOtp(Request $request, OtpLoginService $otp)
    {
        $user = $request->user();

        $issued = $otp->issue($user, $request->ip(), $request->userAgent(), OtpLoginService::PURPOSE_PIN);

        if (! $issued) {
            return back()->withErrors([
                'pin_otp' => 'Please wait a moment before requesting another code.',
            ]);
        }

        return back()->with('success', 'We emailed you a code to authorise this change.');
    }

    /**
     * Set or change the transaction PIN.
     *
     * Requires:
     *   - the current PIN, when one exists, so a hijacked session cannot install
     *     a new PIN on its own; and
     *   - a one-time code emailed to the account owner, so possession of the
     *     session is not sufficient either.
     */
    public function updatePin(Request $request, OtpLoginService $otp)
    {
        $validated = $request->validate([
            'current_pin' => ['nullable', 'string', 'size:4'],
            'pin' => ['required', 'string', 'size:4', 'confirmed'],
            'pin_code' => ['required', 'string', 'digits:6'],
        ], [
            'pin_code.required' => 'Enter the 6-digit code we emailed you.',
            'pin_code.digits' => 'The authorisation code is 6 digits.',
        ]);

        $user = $request->user();
        $security = app(\App\Services\SecurityService::class);

        // Verify the emailed code first. It is single-use and attempt-capped, so
        // a wrong code cannot be brute forced by replaying this endpoint.
        if (! $otp->verify($user, $validated['pin_code'], OtpLoginService::PURPOSE_PIN)) {
            return back()->withErrors([
                'pin_code' => 'That authorisation code is incorrect or has expired. Request a new one.',
            ]);
        }

        try {
            if ($security->hasPin($user)) {
                $security->verifyPin($user, $validated['current_pin'] ?? null);
            }

            $security->setPin($user, $validated['pin']);
        } catch (\Throwable $e) {
            return back()->withErrors(['pin' => $e->getMessage()]);
        }

        return back()->with('success', 'Transaction PIN saved.');
    }
}