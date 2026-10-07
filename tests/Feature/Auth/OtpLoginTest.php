<?php

namespace Tests\Feature\Auth;

use App\Models\LoginOtp;
use App\Models\User;
use App\Notifications\CustomLoginOtp;
use App\Providers\RouteServiceProvider;
use App\Services\OtpLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * One-time sign-in code flow.
 *
 * These are HTTP-level tests on purpose. The flow's correctness lives in the
 * interaction between session state, the notification and the attempt counters,
 * and none of that is observable by unit-testing OtpLoginService in isolation.
 *
 * The code is never read back from the database — only its bcrypt hash is
 * stored, which is itself asserted below. Instead Notification::fake() captures
 * the notification object, and the plaintext code is taken from it, which is
 * exactly what a real user would see in their inbox.
 */
class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Counters live in the cache, which is not reset between tests by
        // RefreshDatabase.
        RateLimiter::clear('otp.cooldown.1');
    }

    /** Propose a code for the given address and return the plaintext code. */
    private function requestCode(User $user, array $headers = []): string
    {
        $this->post(route('login.code.send'), ['email' => $user->email], $headers)
            ->assertRedirect(route('login.code.verify'));

        $code = null;

        Notification::assertSentTo($user, CustomLoginOtp::class, function (CustomLoginOtp $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    /* =====================================================================
     | Happy path
     |=================================================================== */

    public function test_the_request_form_renders(): void
    {
        $this->get(route('login.code'))->assertOk()->assertSee('Sign in with a code');
    }

    public function test_a_verified_user_can_sign_in_with_a_code(): void
    {
        $user = User::factory()->create();

        $code = $this->requestCode($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->post(route('login.code.verify.post'), ['code' => $code])
            ->assertRedirect(RouteServiceProvider::HOME);

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_verify_screen_masks_the_email_address(): void
    {
        $user = User::factory()->create(['email' => 'ada.lovelace@example.test']);

        $this->requestCode($user);

        $this->get(route('login.code.verify'))
            ->assertOk()
            ->assertSee('a•••••••••••@example.test', false)
            // The full local part must not appear anywhere on the page.
            ->assertDontSee('ada.lovelace@example.test');
    }

    /* =====================================================================
     | Code handling
     |=================================================================== */

    public function test_only_a_hash_of_the_code_is_stored(): void
    {
        $user = User::factory()->create();

        $code = $this->requestCode($user);

        $otp = LoginOtp::where('user_id', $user->id)->firstOrFail();

        $this->assertNotSame($code, $otp->code_hash, 'The plaintext code must never be persisted.');
        $this->assertTrue(Hash::check($code, $otp->code_hash));
    }

    public function test_a_code_is_single_use(): void
    {
        $user = User::factory()->create();

        $code = $this->requestCode($user);

        $this->post(route('login.code.verify.post'), ['code' => $code]);
        $this->assertAuthenticatedAs($user);

        // Sign out, then replay the same code.
        $this->post('/logout');
        $this->assertGuest();

        $this->post(route('login.code.verify.post'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_an_incorrect_code_does_not_authenticate(): void
    {
        $user = User::factory()->create();

        $this->requestCode($user);

        $this->post(route('login.code.verify.post'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = User::factory()->create();

        $code = $this->requestCode($user);

        // Reach past the TTL without waiting.
        LoginOtp::where('user_id', $user->id)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->post(route('login.code.verify.post'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_code_dies_after_the_attempt_cap(): void
    {
        $user = User::factory()->create();

        $code = $this->requestCode($user);

        // Burn every allowed attempt with a wrong code.
        for ($i = 0; $i < OtpLoginService::MAX_ATTEMPTS; $i++) {
            $this->post(route('login.code.verify.post'), ['code' => '111111'])
                ->assertSessionHasErrors('code');
        }

        $this->assertGuest();

        // The correct code must no longer work: the cap invalidates it outright.
        $this->post(route('login.code.verify.post'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_requesting_a_new_code_invalidates_the_previous_one(): void
    {
        $user = User::factory()->create();

        $first = $this->requestCode($user);

        // Clear the resend cooldown so a second request is permitted.
        RateLimiter::clear('otp.cooldown.' . $user->id);

        $this->post(route('login.code.send'), ['email' => $user->email]);
        $second = null;
        Notification::assertSentTo($user, CustomLoginOtp::class, function (CustomLoginOtp $n) use (&$second) {
            $second = $n->code;

            return true;
        });

        // The first code is retired as soon as a new one is issued.
        $this->post(route('login.code.verify.post'), ['code' => $first])
            ->assertSessionHasErrors('code');

        $this->assertGuest();

        $this->post(route('login.code.verify.post'), ['code' => $second]);
        $this->assertAuthenticatedAs($user);
    }

    /* =====================================================================
     | Account enumeration
     |=================================================================== */

    public function test_an_unknown_address_gets_the_same_response_and_no_email(): void
    {
        $response = $this->post(route('login.code.send'), ['email' => 'nobody@example.test']);

        // Identical redirect target to the known-address case.
        $response->assertRedirect(route('login.code.verify'));

        Notification::assertNothingSent();
        $this->assertDatabaseCount('auth_one_time_codes', 0);
    }

    public function test_an_unknown_address_reaches_the_verify_screen(): void
    {
        $this->post(route('login.code.send'), ['email' => 'nobody@example.test']);

        // The screen renders rather than redirecting to the request form, which
        // would itself reveal that the address was not found.
        $this->get(route('login.code.verify'))->assertOk();
    }

    public function test_every_code_can_be_attempted_against_every_address(): void
    {
        // Two accounts: a code issued for one must never sign in the other.
        $alice = User::factory()->create(['email' => 'alice@example.test']);
        $bob = User::factory()->create(['email' => 'bob@example.test']);

        $bobCode = $this->requestCode($bob);

        // Switch the pending identity to Alice, then submit Bob's code.
        $this->post(route('login.code.send'), ['email' => $alice->email]);

        $this->post(route('login.code.verify.post'), ['code' => $bobCode])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    /* =====================================================================
     | Account state
     |=================================================================== */

    public function test_a_blocked_account_cannot_obtain_a_code(): void
    {
        $user = User::factory()->blocked()->create();

        $this->post(route('login.code.send'), ['email' => $user->email])
            ->assertRedirect(route('login.code.verify'));

        Notification::assertNothingSent();
        $this->assertDatabaseCount('auth_one_time_codes', 0);
    }

    public function test_a_suspended_account_cannot_obtain_a_code(): void
    {
        $user = User::factory()->status('suspended')->create();

        $this->post(route('login.code.send'), ['email' => $user->email]);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('auth_one_time_codes', 0);
    }

    public function test_an_account_blocked_after_issue_cannot_redeem_its_code(): void
    {
        $user = User::factory()->create();

        $code = $this->requestCode($user);

        // Suspended between issue and redemption.
        $user->forceFill(['is_blocked' => true])->save();

        $this->post(route('login.code.verify.post'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    /* =====================================================================
     | Rate limiting
     | =================================================================== */

    public function test_the_resend_cooldown_suppresses_a_second_code(): void
    {
        $user = User::factory()->create();

        $this->requestCode($user);

        // Immediately request again, inside the cooldown.
        $this->post(route('login.code.send'), ['email' => $user->email]);

        // Exactly one code was issued, so exactly one notification was sent.
        Notification::assertSentToTimes($user, CustomLoginOtp::class, 1);
        $this->assertSame(1, LoginOtp::where('user_id', $user->id)->count());
    }

    public function test_the_verify_screen_reports_the_remaining_cooldown(): void
    {
        $user = User::factory()->create();

        $this->requestCode($user);

        $this->get(route('login.code.verify'))
            ->assertOk()
            ->assertSee('Resend in');
    }

    /* =====================================================================
     | Session handling
     |=================================================================== */

    public function test_the_verify_screen_redirects_without_a_pending_request(): void
    {
        // Deep link with no code ever requested.
        $this->get(route('login.code.verify'))->assertRedirect(route('login.code'));
    }

    public function test_verifying_without_a_pending_request_is_refused(): void
    {
        $this->post(route('login.code.verify.post'), ['code' => '123456'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_verifying_without_a_pending_request_does_not_leak_account_existence(): void
    {
        // A session that never requested a code must fail the same way whether
        // or not the submitted code would have matched a real account. There is
        // no pending identity, so no account is consulted at all — the response
        // is identical by construction, and this pins that down.
        $user = User::factory()->create();

        $otp = LoginOtp::create([
            'user_id' => $user->id,
            'code_hash' => Hash::make('123456'),
            'purpose' => OtpLoginService::PURPOSE,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);

        // A valid, live code for a real user — but no pending request in session.
        $this->post(route('login.code.verify.post'), ['code' => '123456'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();

        // The code is untouched: nothing was even looked up.
        $this->assertNull($otp->fresh()->consumed_at);
    }

    public function test_the_pending_identity_is_cleared_after_a_successful_sign_in(): void
    {
        $user = User::factory()->create();

        $code = $this->requestCode($user);

        $this->post(route('login.code.verify.post'), ['code' => $code]);

        $this->assertNull(session('otp.pending_user_id'));
        $this->assertNull(session('otp.pending_email'));
    }

    /* =====================================================================
     | Input handling
     |=================================================================== */

    public function test_the_email_is_matched_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'mixed@example.test']);

        $this->post(route('login.code.send'), ['email' => 'MIXED@EXAMPLE.TEST']);

        Notification::assertSentTo($user, CustomLoginOtp::class);
    }

    public function test_a_non_numeric_code_is_rejected_before_verification(): void
    {
        $user = User::factory()->create();

        $this->requestCode($user);

        $this->post(route('login.code.verify.post'), ['code' => 'abcdef'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_password_login_still_works(): void
    {
        // The OTP path must not have disturbed the existing one.
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(RouteServiceProvider::HOME);

        $this->assertAuthenticatedAs($user);
    }
}
