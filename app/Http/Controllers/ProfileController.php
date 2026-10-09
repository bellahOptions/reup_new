<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OtpLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    /**
     * Where an avatar lives: the *private* disk.
     *
     * A profile photo is personal data, so it is never written under
     * `public/` (and therefore never reachable at a guessable `/storage/...`
     * URL). It is read back only through self::avatar(), which asserts
     * ownership.
     */
    private const AVATAR_DISK = 'local';

    private const AVATAR_DIRECTORY = 'avatars';

    /**
     * The only extensions an avatar may be stored with, keyed by the MIME type
     * detected from the file's *content*.
     *
     * The stored name is generated here from this map — the client's own
     * filename and extension are never used, so `evil.php` cannot become
     * `profile-1-1699999999.php` on a web-served path.
     */
    private const AVATAR_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
    ];

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
            // Three-state appearance, resolved server-side so the radio group and
            // the navbar switch cannot disagree about what is selected: the
            // *chosen* mode (which may be "system") and whether a choice was
            // ever actually made are different facts, and the page states both.
            'themeChoice' => \App\Support\Theme::modeFor($user),
            'themeChoiceIsExplicit' => \App\Support\Theme::hasExplicitChoice($user),
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
            'profile_picture' => [
                'nullable',
                'file',
                'image',
                // `mimes` compares the extension *guessed from the content*,
                // `mimetypes` compares the detected MIME itself. Both are
                // listed deliberately: the pair rejects a PHP/HTML/SVG payload
                // renamed to .jpg, and the explicit allowlist keeps `image`'s
                // wider set (bmp/webp/svg) out.
                'mimes:jpeg,png,jpg,gif',
                'mimetypes:image/jpeg,image/png,image/gif',
                'max:2048',
            ],
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

        // Handle profile picture upload.
        //
        // Previously the file was written to the `public` disk under a name
        // built from `getClientOriginalExtension()`, so a GIF/PHP polyglot
        // named `x.php` passed `image` + `mimes:gif` on its content and was
        // then stored as `profiles/profile-<id>-<time>.php` — a web-reachable,
        // server-executable path. The name and extension are now derived from
        // the content MIME only, and the bytes go to the private disk.
        if ($request->hasFile('profile_picture')) {
            $image = $request->file('profile_picture');

            $extension = self::AVATAR_EXTENSIONS[$image->getMimeType()] ?? null;

            if ($extension === null) {
                throw ValidationException::withMessages([
                    'profile_picture' => 'Upload a JPEG, PNG or GIF image.',
                ]);
            }

            $filename = \Illuminate\Support\Str::random(40) . '.' . $extension;
            $storedPath = self::AVATAR_DIRECTORY . '/' . $filename;

            if (! $image->storeAs(self::AVATAR_DIRECTORY, $filename, self::AVATAR_DISK)) {
                throw ValidationException::withMessages([
                    'profile_picture' => 'We could not save that image. Please try again.',
                ]);
            }

            // Only remove the previous photo once the replacement is safely on
            // disk, so a failed write cannot leave the account with none.
            $this->forgetAvatar($user->profile_picture, $storedPath);

            $validated['profile_picture'] = route('profile.avatar', [
                'user' => $user->id,
                'file' => $filename,
            ]);
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

    /**
     * Serve an uploaded avatar.
     *
     * The file lives on the private disk, so this is the only way to read it.
     * Authorisation is asserted here rather than by URL secrecy: the owner, or
     * an administrator (the console lists avatars), and nothing else. Anything
     * else is a 403; a request that does not name the user's current file is a
     * 404 so the endpoint cannot be used to enumerate another account's photos.
     */
    public function avatar(Request $request, User $user, string $file): StreamedResponse
    {
        $viewer = $request->user();

        if (! $viewer || ((int) $viewer->id !== (int) $user->id && ! $viewer->isAdmin())) {
            abort(403, 'You may not view that image.');
        }

        // The requested name must be exactly the one stored for this user, so
        // `../` or a null byte in the segment can never select another file
        // (the route pattern already rejects both, this is the second lock).
        if (basename($file) !== $file || basename((string) $user->profile_picture) !== $file) {
            abort(404);
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = array_search($extension, self::AVATAR_EXTENSIONS, true);

        if ($mime === false) {
            abort(404);
        }

        $path = self::AVATAR_DIRECTORY . '/' . $file;

        if (! Storage::disk(self::AVATAR_DISK)->exists($path)) {
            abort(404);
        }

        return response()->stream(
            function () use ($path) {
                $stream = Storage::disk(self::AVATAR_DISK)->readStream($path);

                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                // Fixed type from the allowlist above, never a value read back
                // out of the upload, and never an HTML-capable type.
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="avatar.' . $extension . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }

    /**
     * Remove a user's previously stored avatar, whichever era it came from.
     *
     * Values written before the move to the private disk are public-disk paths
     * of the form `/storage/profiles/<name>`; new ones are route URLs. Both are
     * handled so an account does not accumulate orphaned photos.
     */
    private function forgetAvatar(?string $current, string $keep): void
    {
        if (! $current) {
            return;
        }

        $name = basename(parse_url($current, PHP_URL_PATH) ?: $current);

        if ($name === '' || $name === basename($keep)) {
            return;
        }

        if (str_contains($current, '/storage/profiles/')) {
            Storage::disk('public')->delete('profiles/' . $name);

            return;
        }

        Storage::disk(self::AVATAR_DISK)->delete(self::AVATAR_DIRECTORY . '/' . $name);
    }

    /**
     * Save the account's appearance preference.
     *
     * Called by the switch in the navbar (as JSON, so the page does not reload
     * under the user) and by the radio group on the profile page (as a normal
     * form post, so it works with JavaScript disabled).
     *
     * -----------------------------------------------------------------------
     * Why 'system' is stored as NULL
     * -----------------------------------------------------------------------
     * There are three states — Light, Dark and System — but only two *choices*.
     * System means "I have no opinion, ask my device", which is exactly what a
     * NULL `theme_preference` already means, and exactly what an account that has
     * never opened this setting has. Writing the literal string 'system' would
     * create a second representation of the same state, and then "has this
     * account chosen anything?" — which the profile page and the admin table both
     * ask — would have two answers to check instead of one.
     *
     * Selecting System therefore *clears* the column, which is what lets an
     * account fall back to the site-wide default an administrator sets, rather
     * than pinning it to whatever the site default happened to be that day.
     */
    public function updateTheme(Request $request)
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(array_keys(config('theme.modes', [])))],
        ]);

        $user = $request->user();
        $mode = $validated['theme'];

        $user->forceFill([
            'theme_preference' => $mode === 'system' ? null : $mode,
        ])->save();

        $resolved = \App\Support\Theme::modeFor($user->fresh());

        if (! $request->expectsJson()) {
            return back()->with('success', 'Appearance updated.');
        }

        return response()->json([
            'success' => true,
            'mode' => $mode,
            'resolved' => $resolved,
            'message' => 'Appearance set to ' . config('theme.modes.' . $mode . '.label', $mode) . '.',
        ]);
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
     * in the response (`debug_code`) so the flow is testable; in production
     * `debug_code` is always null and delivery must be added before this
     * endpoint is useful. The code itself is deliberately *not* written to the
     * log: a one-time code is a credential, and a log file outlives the
     * ten-minute window it is valid for.
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