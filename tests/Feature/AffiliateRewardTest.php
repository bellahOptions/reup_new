<?php

namespace Tests\Feature;

use App\Models\Referral;
use App\Models\Transactions;
use App\Models\User;
use App\Services\AffiliateService;
use App\Services\PaystackService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Referral rewards.
 *
 * These are the tests that matter most in this suite: they guard money. The
 * cases are chosen around the ways a referral programme actually loses money —
 * paying twice, paying for ineligible funding, and paying people for referring
 * themselves.
 */
class AffiliateRewardTest extends TestCase
{
    use RefreshDatabase;

    private function fund(User $user, float $amount, array $attributes = []): Transactions
    {
        $movement = app(WalletService::class)->credit($user, $amount);

        return Transactions::create(array_merge([
            'user_id' => $user->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding',
            'amount' => $amount,
            'service_fee' => 0,
            'total_amount' => $amount,
            'balance_before' => $movement['balance_before'],
            'balance_after' => $movement['balance_after'],
            'payment_method' => 'paystack',
            'payment_status' => 'success',
            'status' => 'success',
            'paid_at' => now(),
        ], $attributes));
    }

    private function service(): AffiliateService
    {
        return app(AffiliateService::class);
    }

    /* =====================================================================
     | Qualification
     |=================================================================== */

    public function test_the_referrer_is_paid_when_the_referred_user_funds_1000(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $transaction = $this->fund($referred, 1000);

        $reward = $this->service()->rewardIfEligible($referred->fresh(), $transaction);

        $this->assertNotNull($reward);
        $this->assertEquals(200.00, (float) $reward->reward_amount);
        $this->assertSame($referrer->id, $reward->referrer_id);

        // The money actually arrived.
        $this->assertEquals(200.00, (float) $referrer->fresh()->wallet->balance);
    }

    public function test_exactly_1000_qualifies(): void
    {
        // The boundary is inclusive; `>=` not `>`. Getting this wrong would
        // silently refuse the exact amount the marketing promises.
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $transaction = $this->fund($referred, 1000.00);

        $this->assertNotNull($this->service()->rewardIfEligible($referred->fresh(), $transaction));
    }

    public function test_funding_below_the_threshold_does_not_pay(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $transaction = $this->fund($referred, 999.99);

        $this->assertNull($this->service()->rewardIfEligible($referred->fresh(), $transaction));
        $this->assertEquals(0.0, (float) $referrer->fresh()->wallet->balance);
    }

    public function test_several_small_top_ups_accumulate_to_qualify(): void
    {
        // Lifetime funding, not a single transaction. A user who tops up
        // 600 + 600 has funded 1,200 and must qualify.
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $first = $this->fund($referred, 600);
        $this->assertNull($this->service()->rewardIfEligible($referred->fresh(), $first));

        $second = $this->fund($referred, 600);
        $reward = $this->service()->rewardIfEligible($referred->fresh(), $second);

        $this->assertNotNull($reward);
        $this->assertEquals(1200.00, (float) $reward->qualifying_amount);
    }

    public function test_a_user_with_no_referrer_earns_nobody_anything(): void
    {
        $user = User::factory()->create(['referred_by_user_id' => null]);
        $transaction = $this->fund($user, 5000);

        $this->assertNull($this->service()->rewardIfEligible($user->fresh(), $transaction));
    }

    /* =====================================================================
     | Abuse and double-payment guards
     |=================================================================== */

    public function test_a_referral_is_only_paid_once(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $first = $this->fund($referred, 1000);
        $this->assertNotNull($this->service()->rewardIfEligible($referred->fresh(), $first));

        // Fund again well past the threshold.
        $second = $this->fund($referred, 5000);
        $this->assertNull($this->service()->rewardIfEligible($referred->fresh(), $second));

        $this->assertEquals(200.00, (float) $referrer->fresh()->wallet->balance);
        $this->assertSame(1, Referral::count());
    }

    public function test_replaying_the_same_funding_does_not_pay_twice(): void
    {
        // The webhook-retry case: same transaction, evaluated again.
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $transaction = $this->fund($referred, 1000);

        $this->assertNotNull($this->service()->rewardIfEligible($referred->fresh(), $transaction));
        $this->assertNull($this->service()->rewardIfEligible($referred->fresh(), $transaction));

        $this->assertEquals(200.00, (float) $referrer->fresh()->wallet->balance);
        $this->assertSame(1, Referral::count());
    }

    public function test_self_referral_is_refused(): void
    {
        // The obvious abuse: refer yourself with a second account and mint a
        // 20% rebate on your own money.
        $user = User::factory()->create();
        $user->forceFill(['referred_by_user_id' => $user->id])->saveQuietly();

        $transaction = $this->fund($user->fresh(), 5000);

        $this->assertNull($this->service()->rewardIfEligible($user->fresh(), $transaction));
        $this->assertSame(0, Referral::count());
    }

    public function test_the_reward_is_not_itself_counted_as_funding(): void
    {
        /*
         * A reward must not move the referrer's own total_funded. If it did, a
         * referrer who was themselves referred could chain rewards off their
         * own payouts.
         */
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $before = (float) $referrer->fresh()->wallet->total_funded;

        $transaction = $this->fund($referred, 1000);
        $this->service()->rewardIfEligible($referred->fresh(), $transaction);

        $after = (float) $referrer->fresh()->wallet->total_funded;

        $this->assertSame($before, $after, 'The referral reward must not count as funding.');
        $this->assertEquals(200.00, (float) $referrer->fresh()->wallet->balance);
    }

    public function test_the_reward_appears_in_the_referrers_transaction_history(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        $transaction = $this->fund($referred, 1000);
        $this->service()->rewardIfEligible($referred->fresh(), $transaction);

        $rewardTxn = Transactions::where('user_id', $referrer->id)
            ->where('description', 'like', 'Referral reward%')
            ->first();

        $this->assertNotNull($rewardTxn, 'The credit must be visible in history.');
        $this->assertEquals(200.00, (float) $rewardTxn->amount);
        $this->assertSame('credit', $rewardTxn->type);
    }

    /* =====================================================================
     | Wiring through the funding paths
     |=================================================================== */

    public function test_settling_a_card_payment_pays_the_referrer(): void
    {
        // End-to-end through PaystackService::settle(), which is what the
        // webhook, the browser callback and the reconciler all call. This is the
        // assertion that the reward is wired into the real funding path and not
        // merely into the service in isolation.
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        // A pending funding row, exactly as initiate() would have left it.
        $transaction = Transactions::create([
            'user_id' => $referred->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding',
            'amount' => 1000,
            'service_fee' => 0,
            'total_amount' => 1000,
            'payment_method' => 'paystack',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        app(PaystackService::class)->settle($transaction, [
            'amount' => 100000,
            'status' => 'success',
            'channel' => 'card',
            'gateway_response' => 'Successful',
        ]);

        // The customer was credited...
        $this->assertEquals(1000.00, (float) $referred->fresh()->wallet->balance);
        // ...and the referrer was paid.
        $this->assertEquals(200.00, (float) $referrer->fresh()->wallet->balance);
        $this->assertSame(1, Referral::count());
    }

    /* =====================================================================
     | Codes
     |=================================================================== */

    public function test_every_user_gets_a_referral_code(): void
    {
        $user = User::factory()->create(['referral_code' => null]);

        $code = $this->service()->ensureCode($user);

        $this->assertNotEmpty($code);
        $this->assertSame($code, $user->fresh()->referral_code);
    }

    public function test_codes_resolve_case_insensitively_and_trimmed(): void
    {
        $user = User::factory()->create(['referral_code' => 'ABCD1234']);

        $this->assertSame($user->id, $this->service()->userForCode('abcd1234')?->id);
        $this->assertSame($user->id, $this->service()->userForCode('  ABCD1234  ')?->id);
        $this->assertNull($this->service()->userForCode('NOPE0000'));
        $this->assertNull($this->service()->userForCode(''));
        $this->assertNull($this->service()->userForCode(null));
    }

    /* =====================================================================
     | Registration attribution
     |=================================================================== */

    public function test_registration_records_the_referrer(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'FRIEND01']);

        $this->post('/register', [
            'name' => 'New Person',
            'email' => 'new@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => true,
            'ref' => 'FRIEND01',
        ])->assertRedirect();

        $new = User::where('email', 'new@example.test')->firstOrFail();

        $this->assertSame($referrer->id, $new->referred_by_user_id);
        // New users get a code of their own.
        $this->assertNotEmpty($new->referral_code);
    }

    public function test_an_unknown_referral_code_does_not_block_registration(): void
    {
        // A mistyped code must not cost us the sign-up.
        $this->post('/register', [
            'name' => 'New Person',
            'email' => 'new2@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => true,
            'ref' => 'DOESNOTEXIST',
        ])->assertRedirect();

        $new = User::where('email', 'new2@example.test')->firstOrFail();

        $this->assertNull($new->referred_by_user_id);
    }

    public function test_the_register_page_carries_the_referral_code_from_the_link(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'LINKCODE']);

        $this->get('/register?ref=LINKCODE')
            ->assertOk()
            ->assertSee('LINKCODE');
    }
}
