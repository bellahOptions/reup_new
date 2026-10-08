<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LoginThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Per-account credential throttling.
 *
 * The route's `throttle:10,1` keys on the client IP, which stops one host
 * hammering the form and nothing else: an attacker with a proxy pool gets ten
 * guesses per address per minute and the targeted account sees no limit. These
 * tests pin the part the IP cannot provide — a limit attached to the account —
 * and pin that it is a *rate limit*, never a permanent lockout, because a
 * permanent lockout on failed logins is itself a denial-of-service primitive.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear(LoginThrottle::ipKey(request()));
    }

    private function attempt(string $email, string $password = 'password')
    {
        return $this->post(route('login'), [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function test_correct_credentials_still_sign_in_under_the_limit(): void
    {
        $user = User::factory()->create();

        $this->attempt($user->email)
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_repeated_failures_for_one_account_are_refused(): void
    {
        $user = User::factory()->create();

        // Five wrong attempts exhaust the per-account allowance.
        for ($i = 0; $i < 5; $i++) {
            $this->attempt($user->email, 'wrong-password')
                ->assertSessionHasErrors('email');
        }

        $this->assertGuest();

        // The sixth is refused *before* the password is even considered, and the
        // message says so rather than repeating the generic credential error.
        $response = $this->attempt($user->email, 'wrong-password');

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Too many sign-in attempts',
            (string) session('errors')->first('email')
        );
    }

    public function test_the_right_password_is_also_refused_while_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($user->email, 'wrong-password');
        }

        // This is the point of throttling: an attacker who finally guesses
        // correctly still has to wait.
        $this->attempt($user->email, 'password');

        $this->assertGuest();
    }

    public function test_a_successful_sign_in_clears_the_account_counter(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->attempt($user->email, 'wrong-password');
        }

        // The real owner signs in successfully.
        $this->attempt($user->email, 'password')->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'));

        // Their allowance is whole again, so a subsequent mistake does not
        // immediately lock them out of their own account.
        $this->attempt($user->email, 'wrong-password')->assertSessionHasErrors('email');

        $this->assertStringNotContainsString(
            'Too many sign-in attempts',
            (string) session('errors')->first('email')
        );
    }

    public function test_one_attack_on_an_account_does_not_throttle_a_different_account(): void
    {
        $target = User::factory()->create();
        $bystander = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($target->email, 'wrong-password');
        }

        // The per-email key is scoped to the address under attack.
        $this->attempt($bystander->email)
            ->assertRedirect();

        $this->assertAuthenticatedAs($bystander);
    }

    public function test_the_account_limit_is_not_permanent(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($user->email, 'wrong-password');
        }

        $this->assertTrue(LoginThrottle::check(request(), $user->email)['blocked']);

        // Move past the one-minute window. `travel` rewinds the clock that the
        // rate limiter reads.
        $this->travel(2)->minutes();

        $this->assertFalse(
            LoginThrottle::check(request(), $user->email)['blocked'],
            'The per-account limit must expire; a permanent lockout is a denial of service.'
        );

        $this->attempt($user->email)->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_refusal_does_not_reveal_whether_the_account_exists(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($user->email, 'wrong-password');
        }

        $known = (string) session('errors')->first('email');

        RateLimiter::clear(LoginThrottle::emailKey('nobody-' . $user->id . '@example.test'));

        for ($i = 0; $i < 5; $i++) {
            $this->attempt('nobody-' . $user->id . '@example.test', 'wrong-password');
        }

        $unknown = (string) session('errors')->first('email');

        // Throttling is applied to an address whether or not it is registered,
        // so the throttle message cannot be used to enumerate accounts.
        $this->assertSame($known, $unknown);
    }

    public function test_the_email_is_not_stored_in_the_rate_limit_key(): void
    {
        $email = 'customer@example.test';

        // Cache/rate-limiter keys end up in logs and in `redis-cli` output, so
        // an email address must not appear in one.
        $this->assertStringNotContainsString($email, LoginThrottle::emailKey($email));
        $this->assertStringNotContainsString('customer', LoginThrottle::emailKey($email));
    }
}
