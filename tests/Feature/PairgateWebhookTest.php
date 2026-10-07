<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Pairgate webhook is where a vended token/PIN arrives and where an order
 * Pairgate accepted and later failed is reported — the two things the purchase
 * call cannot tell us.
 *
 * So the cases that matter are the ones where acting wrongly costs money:
 * refunding twice, refunding the wrong row, and letting an unsigned request do
 * either. Every test posts a *raw* body with a real HMAC over it, because that
 * is exactly what the controller verifies.
 */
class PairgateWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_pairgate';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pairgate.api_key' => 'pg_test_key',
            'services.pairgate.webhook_secret' => self::SECRET,
        ]);
    }

    /**
     * Deliver a signed webhook exactly as Pairgate documents it:
     * X-Pairgate-Signature = hex HMAC-SHA256 of "<timestamp>.<raw body>".
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $options  secret | timestamp | unsigned
     */
    private function deliver(array $payload, array $options = [])
    {
        $timestamp = (int) ($options['timestamp'] ?? time());
        $body = json_encode($payload);

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if (! ($options['unsigned'] ?? false)) {
            $secret = (string) ($options['secret'] ?? self::SECRET);

            $headers['HTTP_X_PAIRGATE_TIMESTAMP'] = (string) $timestamp;
            $headers['HTTP_X_PAIRGATE_SIGNATURE'] = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }

        return $this->call('POST', '/pairgate/webhook', [], [], [], $headers, $body);
    }

    /** A vended purchase, recorded the way the pipeline records one. */
    private function vended(User $user, array $attributes = []): Transactions
    {
        return Transactions::create(array_merge([
            'user_id' => $user->id,
            'type' => 'debit',
            'service_type' => 'electricity',
            'description' => 'Electricity — IKEDC (prepaid)',
            'amount' => 5000,
            'service_fee' => 0,
            'total_amount' => 5000,
            'recipient' => '1234567890',
            'provider' => 'Pairgate',
            'payment_method' => 'wallet',
            'payment_status' => 'success',
            'status' => 'success',
            'api_reference' => 'TRXEE20260615104818CQ3',
            'meta' => ['provider' => 'pairgate'],
        ], $attributes));
    }

    private function fundedUser(float $balance): User
    {
        $user = User::factory()->create();

        $wallet = app(WalletService::class)->forUser($user);
        $wallet->forceFill(['balance' => $balance])->save();

        return $user;
    }

    private function failurePayload(array $overrides = []): array
    {
        return array_merge([
            'event' => 'electricity.purchase',
            'reference' => null,
            'reference_code' => 'TRXEE20260615104818CQ3',
            'status' => 'failed',
            'message' => 'Transaction failed. Your wallet has been refunded.',
            'amount' => 5000,
        ], $overrides);
    }

    /* =====================================================================
     | Authentication
     |=================================================================== */

    public function test_it_refuses_to_run_without_a_configured_secret(): void
    {
        config(['services.pairgate.webhook_secret' => null]);

        // An endpoint that can refund money must never be open because nobody
        // finished the configuration.
        $this->deliver($this->failurePayload())->assertStatus(503);
    }

    public function test_it_rejects_a_delivery_with_no_signature(): void
    {
        $this->deliver($this->failurePayload(), ['unsigned' => true])->assertStatus(400);
    }

    public function test_it_rejects_a_signature_made_with_the_wrong_secret(): void
    {
        $this->deliver($this->failurePayload(), ['secret' => 'not-the-secret'])->assertStatus(401);
    }

    public function test_it_rejects_a_replayed_delivery_outside_the_window(): void
    {
        $this->deliver($this->failurePayload(), ['timestamp' => time() - 3600])->assertStatus(401);
    }

    public function test_a_valid_signature_is_required_before_anything_is_read(): void
    {
        $user = $this->fundedUser(1000);
        $transaction = $this->vended($user);

        $this->deliver($this->failurePayload(), ['secret' => 'not-the-secret']);

        // No refund, no status change: the payload is never trusted.
        $this->assertSame('success', $transaction->fresh()->payment_status);
        $this->assertSame(1000.0, (float) $user->wallet()->first()->balance);
    }

    /* =====================================================================
     | Success: the token arrives here and nowhere else
     |=================================================================== */

    public function test_a_successful_delivery_stores_the_electricity_token(): void
    {
        $user = $this->fundedUser(0);
        $transaction = $this->vended($user);

        $response = $this->deliver([
            'event' => 'electricity.purchase',
            'reference' => null,
            'reference_code' => 'TRXEE20260615104818CQ3',
            'status' => 'successful',
            'message' => 'Electricity purchase completed.',
            'amount' => 5000,
            'pin' => '1234-5678-9012-3456-7890',
            'completed_at' => now()->toIso8601String(),
        ]);

        $response->assertOk()->assertJson(['status' => 'settled']);

        $fresh = $transaction->fresh();

        $this->assertSame('success', $fresh->status);
        $this->assertSame('1234-5678-9012-3456-7890', $fresh->meta['token']);
    }

    public function test_exam_pin_reference_codes_are_all_kept(): void
    {
        $user = $this->fundedUser(0);
        $transaction = $this->vended($user, [
            'service_type' => 'exam',
            'api_reference' => 'TRXEXAM20260615104818CQ1',
            'meta' => ['provider' => 'pairgate'],
        ]);

        foreach (['TRXEXAM20260615104818CQ1', 'TRXEXAM20260615104818CQ2'] as $index => $referenceCode) {
            $this->deliver([
                'event' => 'waec.purchase',
                'reference' => $transaction->reference,
                'reference_code' => $referenceCode,
                'status' => 'successful',
                'amount' => 3500,
                'pin' => 'WAEC-PIN-' . $index,
            ], ['timestamp' => time() + $index])->assertOk();
        }

        $this->assertSame(
            ['TRXEXAM20260615104818CQ1', 'TRXEXAM20260615104818CQ2'],
            $transaction->fresh()->meta['provider_references']
        );
    }

    public function test_an_unknown_reference_is_acknowledged_not_retried(): void
    {
        $this->deliver($this->failurePayload(['reference_code' => 'TRX-NOT-OURS']))
            ->assertOk()
            ->assertJson(['status' => 'unknown_reference']);
    }

    /* =====================================================================
     | Failure: refund exactly once
     |=================================================================== */

    public function test_a_failed_delivery_refunds_the_customer(): void
    {
        $user = $this->fundedUser(1000);
        $transaction = $this->vended($user);

        $this->deliver($this->failurePayload())
            ->assertOk()
            ->assertJson(['status' => 'refunded']);

        $fresh = $transaction->fresh();

        $this->assertSame('failed', $fresh->status);
        $this->assertSame('refunded', $fresh->payment_status);
        $this->assertSame(6000.0, (float) $user->wallet()->first()->balance);
        $this->assertSame(1, Transactions::where('service_type', 'refund')->count());
    }

    public function test_a_redelivered_failure_does_not_refund_twice(): void
    {
        $user = $this->fundedUser(1000);
        $transaction = $this->vended($user);

        $this->deliver($this->failurePayload())->assertOk();

        // Pairgate retries anything it is not sure it delivered.
        $this->deliver($this->failurePayload(), ['timestamp' => time() + 1])
            ->assertOk()
            ->assertJson(['status' => 'already_refunded']);

        $this->assertSame(6000.0, (float) $user->wallet()->first()->balance);
        $this->assertSame(1, Transactions::where('service_type', 'refund')->count());
        $this->assertSame('refunded', $transaction->fresh()->payment_status);
    }

    public function test_a_failure_for_a_different_amount_is_not_refunded_automatically(): void
    {
        $user = $this->fundedUser(1000);
        $transaction = $this->vended($user);

        $this->deliver($this->failurePayload(['amount' => 4000]))
            ->assertOk()
            ->assertJson(['status' => 'amount_mismatch']);

        $this->assertSame('success', $transaction->fresh()->payment_status);
        $this->assertSame(1000.0, (float) $user->wallet()->first()->balance);
    }

    public function test_success_after_a_refund_is_flagged_instead_of_reinstated(): void
    {
        $user = $this->fundedUser(1000);
        $transaction = $this->vended($user);

        $this->deliver($this->failurePayload())->assertOk();

        $this->deliver([
            'event' => 'electricity.purchase',
            'reference_code' => 'TRXEE20260615104818CQ3',
            'status' => 'successful',
            'amount' => 5000,
            'pin' => '1234-5678-9012-3456-7890',
        ], ['timestamp' => time() + 1])
            ->assertOk()
            ->assertJson(['status' => 'refunded_needs_review']);

        $fresh = $transaction->fresh();

        // Still refunded, still failed: a human has to decide which delivery
        // was wrong, and the wallet must not be credited twice.
        $this->assertSame('refunded', $fresh->payment_status);
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(6000.0, (float) $user->wallet()->first()->balance);
    }

    public function test_a_delivery_matching_on_our_own_reference_is_honoured(): void
    {
        $user = $this->fundedUser(1000);
        $transaction = $this->vended($user);

        // No reference_code at all — only the client reference we sent.
        $this->deliver($this->failurePayload([
            'reference_code' => null,
            'reference' => $transaction->reference,
        ]))->assertOk()->assertJson(['status' => 'refunded']);

        $this->assertSame('refunded', $transaction->fresh()->payment_status);
    }
}
