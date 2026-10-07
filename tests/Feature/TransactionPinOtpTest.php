<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\CustomLoginOtp;
use App\Notifications\CustomPinOtp;
use App\Services\OtpLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The transaction PIN now requires an emailed one-time code.
 *
 * The PIN authorises every purchase, so it is the single most valuable thing an
 * attacker with a hijacked session could replace: set a PIN they know, then
 * spend the wallet. These tests assert that session possession alone is not
 * enough, and that the new step did not break the existing one.
 */
class TransactionPinOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** Capture the plaintext code from the notification the user received. */
    private function pinCodeFor(User $user): string
    {
        $code = null;

        Notification::assertSentTo($user, CustomPinOtp::class, function (CustomPinOtp $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    public function test_setting_a_pin_requires_an_emailed_code(): void
    {
        $user = User::factory()->create();

        // No code requested at all.
        $this->actingAs($user)
            ->put(route('profile.pin'), [
                'pin' => '5931',
                'pin_confirmation' => '5931',
            ])
            ->assertSessionHasErrors('pin_code');

        $this->assertNull($user->fresh()->transaction_pin, 'The PIN must not be set without a code.');
    }

    public function test_a_pin_can_be_set_with_a_valid_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.pin.code'))->assertRedirect();
        $code = $this->pinCodeFor($user);

        $this->actingAs($user)
            ->put(route('profile.pin'), [
                'pin' => '5931',
                'pin_confirmation' => '5931',
                'pin_code' => $code,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('5931', $user->fresh()->transaction_pin));
    }

    public function test_a_wrong_code_does_not_set_the_pin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.pin.code'));
        $this->pinCodeFor($user);

        $this->actingAs($user)
            ->put(route('profile.pin'), [
                'pin' => '5931',
                'pin_confirmation' => '5931',
                'pin_code' => '000000',
            ])
            ->assertSessionHasErrors('pin_code');

        $this->assertNull($user->fresh()->transaction_pin);
    }

    public function test_a_pin_code_is_single_use(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.pin.code'));
        $code = $this->pinCodeFor($user);

        $this->actingAs($user)->put(route('profile.pin'), [
            'pin' => '5931', 'pin_confirmation' => '5931', 'pin_code' => $code,
        ])->assertSessionHasNoErrors();

        // Replay the same code to change the PIN again without a fresh one.
        $this->actingAs($user)->put(route('profile.pin'), [
            'current_pin' => '5931',
            'pin' => '8274',
            'pin_confirmation' => '8274',
            'pin_code' => $code,
        ])->assertSessionHasErrors('pin_code');

        $this->assertTrue(Hash::check('5931', $user->fresh()->transaction_pin));
    }

    public function test_changing_a_pin_still_requires_the_current_pin(): void
    {
        // The pre-existing protection must survive: a valid email code plus a
        // hijacked session should still not be enough to swap the PIN silently.
        $user = User::factory()->create();
        app(\App\Services\SecurityService::class)->setPin($user, '5931');

        $this->actingAs($user)->post(route('profile.pin.code'));
        $code = $this->pinCodeFor($user->fresh());

        $this->actingAs($user)
            ->put(route('profile.pin'), [
                'current_pin' => '1111', // wrong
                'pin' => '8274',
                'pin_confirmation' => '8274',
                'pin_code' => $code,
            ])
            ->assertSessionHasErrors('pin');

        $this->assertTrue(Hash::check('5931', $user->fresh()->transaction_pin));
    }

    public function test_requesting_a_pin_code_does_not_issue_a_login_code(): void
    {
        /*
         * The purposes must stay separate. If the PIN request issued a 'login'
         * code, a user could authorise a PIN change with a sign-in code — and
         * worse, the sign-in flow could be satisfied by a PIN code.
         */
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.pin.code'));

        Notification::assertSentTo($user, CustomPinOtp::class);
        Notification::assertNotSentTo($user, CustomLoginOtp::class);
    }

    public function test_the_pin_code_email_says_what_it_is_for(): void
    {
        $user = User::factory()->create();

        $notification = new CustomPinOtp('123456', 10, '127.0.0.1');
        $html = (string) $notification->toMail($user)->render();

        // Distinguishable from a sign-in code at a glance — this is what stops
        // the message reading as phishing.
        $this->assertStringContainsString('PIN', $html);
        // The code is rendered with a space between each digit for readability,
        // so assert the spaced form rather than the bare digits.
        $this->assertStringContainsString('1 2 3 4 5 6', $html);
        $this->assertStringContainsString('PIN authorisation code', $html);
        // And it must not use the sign-in wording.
        $this->assertStringNotContainsString('sign in to your ReUp account', $html);
    }

    public function test_a_pin_code_cannot_be_used_for_sign_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.pin.code'));
        $code = $this->pinCodeFor($user);

        // Log out, then try the PIN code at the sign-in OTP endpoint.
        auth()->logout();

        $otp = app(OtpLoginService::class);
        $this->assertFalse(
            $otp->verify($user->fresh(), $code, OtpLoginService::PURPOSE),
            'A PIN-purpose code must not satisfy the sign-in purpose.'
        );
    }

    public function test_the_pin_code_endpoint_is_throttled_and_reports_back(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.pin.code'))->assertRedirect();
        $this->pinCodeFor($user);

        // Immediately requesting again lands inside the cooldown.
        $this->actingAs($user)
            ->post(route('profile.pin.code'))
            ->assertSessionHasErrors('pin_otp');
    }
}
