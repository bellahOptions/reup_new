<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pending-payment reconciliation.
 *
 * The command is the safety net for a webhook that never arrived, so the cases
 * that matter are the ones where acting wrongly is worse than not acting:
 * crediting twice, marking a live payment failed, and touching bill purchases
 * that Paystack knows nothing about.
 */
class ReconcilePaystackPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => 'sk_test_reconcile',
        ]);
    }

    /**
     * A pending Paystack funding row, old enough to be polled.
     */
    private function pendingFunding(User $user, array $attributes = []): Transactions
    {
        $transaction = Transactions::create(array_merge([
            'user_id' => $user->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding',
            'amount' => 5000,
            'service_fee' => 0,
            'total_amount' => 5000,
            'payment_method' => 'paystack',
            'payment_status' => 'pending',
            'status' => 'pending',
        ], $attributes));

        // Age it past the poll threshold.
        $transaction->forceFill(['created_at' => now()->subMinutes(15)])->saveQuietly();

        return $transaction->fresh();
    }

    /** Fake a Paystack verify response for the given reference. */
    private function fakeVerify(string $reference, string $status, int $kobo = 500000): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => $reference,
                    'status' => $status,
                    'amount' => $kobo,
                    'currency' => 'NGN',
                    'channel' => 'card',
                    'gateway_response' => 'Successful',
                ],
            ], 200),
        ]);
    }

    /* =====================================================================
     | UUID wiring
     |=================================================================== */

    public function test_a_transaction_is_given_a_uuid_and_a_reference(): void
    {
        $user = User::factory()->create();

        $transaction = Transactions::create([
            'user_id' => $user->id,
            'type' => 'debit',
            'service_type' => 'airtime',
            'description' => 'Airtime',
            'amount' => 100,
            'service_fee' => 2,
            'total_amount' => 102,
        ]);

        $this->assertTrue(Str::isUuid($transaction->uuid), 'uuid should be a v4 UUID');
        $this->assertNotEmpty($transaction->reference);
        $this->assertNotSame($transaction->uuid, $transaction->reference);
    }

    public function test_the_gateway_reference_is_the_uuid(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        $this->assertSame($transaction->uuid, $transaction->gatewayReference());
    }

    public function test_gateway_reference_falls_back_for_legacy_rows(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        // Simulate a row created before the uuid column existed.
        $transaction->forceFill(['uuid' => null])->saveQuietly();

        $this->assertSame($transaction->reference, $transaction->fresh()->gatewayReference());
    }

    public function test_a_transaction_can_be_found_by_uuid_reference_or_api_reference(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user, ['api_reference' => 'PSK-ECHO-1']);

        $this->assertTrue(Transactions::whereReference($transaction->uuid)->exists());
        $this->assertTrue(Transactions::whereReference($transaction->reference)->exists());
        $this->assertTrue(Transactions::whereReference('PSK-ECHO-1')->exists());
        $this->assertFalse(Transactions::whereReference('nope')->exists());
    }

    /* =====================================================================
     | Reconciliation
     |=================================================================== */

    public function test_a_successful_payment_is_credited(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        $this->fakeVerify($transaction->uuid, 'success');

        $this->artisan('payments:reconcile')->assertSuccessful();

        $transaction->refresh();

        $this->assertSame('success', $transaction->status);
        $this->assertSame('5000.00', $transaction->total_amount);
        // The balance pair must be written, since the dashboard and receipt show it.
        $this->assertNotNull($transaction->balance_before);
        $this->assertNotNull($transaction->balance_after);
        $this->assertEquals(5000.0, (float) $transaction->balance_after - (float) $transaction->balance_before);
        $this->assertEquals(5000.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_reconciliation_does_not_credit_twice(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        $this->fakeVerify($transaction->uuid, 'success');

        $this->artisan('payments:reconcile')->assertSuccessful();
        // Second run: the row is no longer pending, so it is not even selected.
        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertEquals(5000.0, (float) $user->fresh()->wallet_balance);
        $this->assertSame(1, Transactions::where('user_id', $user->id)->where('status', 'success')->count());
    }

    public function test_a_failed_payment_is_marked_failed_and_not_credited(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        $this->fakeVerify($transaction->uuid, 'failed', 0);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $transaction->refresh();

        $this->assertSame('failed', $transaction->status);
        $this->assertEquals(0.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_a_payment_still_in_flight_is_left_pending(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        // Paystack reports "ongoing" while the customer is still on the page.
        $this->fakeVerify($transaction->uuid, 'ongoing', 0);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_an_amount_mismatch_does_not_credit(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        // Customer paid ₦100 against a ₦5,000 row.
        $this->fakeVerify($transaction->uuid, 'success', 10000);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $transaction->refresh();

        $this->assertSame('failed', $transaction->status);
        $this->assertStringContainsString('Amount mismatch', (string) $transaction->status_message);
        $this->assertEquals(0.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_bill_purchases_are_never_polled(): void
    {
        // Paystack has never heard of a ClubKonnect airtime order. Polling one
        // would get "not found" and, if that were treated as failure, would mark
        // a delivered purchase as failed.
        $user = User::factory()->create();

        $airtime = $this->pendingFunding($user, [
            'service_type' => 'airtime',
            'payment_method' => 'wallet',
            'description' => 'Airtime — MTN',
        ]);

        Http::fake(['api.paystack.co/*' => Http::response(['status' => false], 404)]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('pending', $airtime->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_recent_payment_is_left_alone_for_the_webhook(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        // Fresh: only seconds old, so the webhook still has its chance.
        $transaction->forceFill(['created_at' => now()])->saveQuietly();

        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['status' => 'success']], 200)]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('pending', $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_gateway_error_leaves_the_payment_pending(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        // A 500 from Paystack is not a verdict on the payment.
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false], 500)]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        $this->fakeVerify($transaction->uuid, 'success');

        $this->artisan('payments:reconcile', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertEquals(0.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_an_abandoned_payment_expires_past_the_lookback(): void
    {
        $user = User::factory()->create();
        $transaction = $this->pendingFunding($user);

        // 30 hours old against a 24h default lookback: past the window.
        // NOTE the poll window is `created_at BETWEEN now-lookback AND now-minutes`,
        // so an out-of-window row must also fall *outside* that range.
        $transaction->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();

        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['status' => 'success']], 200)]);

        // lookback must be >= the age for the poll window, but the expiry sweep
        // uses `created_at < now - lookback`, so pass a lookback below the age.
        $this->artisan('payments:reconcile', ['--lookback' => 60])->assertSuccessful();

        $transaction->refresh();

        $this->assertSame('failed', $transaction->status);
        $this->assertStringContainsString('never completed', (string) $transaction->status_message);
        // Nothing was polled: it is past the window.
        Http::assertNothingSent();
    }

    public function test_it_reports_cleanly_when_paystack_is_not_configured(): void
    {
        config(['services.paystack.secret_key' => null]);

        $this->artisan('payments:reconcile')
            ->expectsOutput('Paystack is not configured (PAYSTACK_SECRET_KEY is empty) — nothing to reconcile.')
            ->assertSuccessful();
    }

    /* =====================================================================
     | Receipt email
     |=================================================================== */

    public function test_the_receipt_shows_both_balances(): void
    {
        $user = User::factory()->create();
        app(WalletService::class)->credit($user, 10000);

        $transaction = $this->pendingFunding($user, [
            'balance_before' => 10000,
            'balance_after' => 15000,
            'status' => 'success',
            'payment_status' => 'success',
            'meta' => [
                'request_ip' => '102.89.34.7',
                'request_user_agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120 Safari/537.36',
            ],
        ]);

        $html = (new \App\Mail\TransactionReceiptMail($transaction))->render();

        $this->assertStringContainsString('₦10,000.00', $html, 'balance before');
        $this->assertStringContainsString('₦15,000.00', $html, 'balance after');
        $this->assertStringContainsString('102.89.34.7', $html, 'request IP');
        $this->assertStringContainsString('Chrome on Windows', $html, 'parsed device');
        $this->assertStringContainsString('Security details', $html);
        $this->assertStringContainsString("Wasn't you?", $html);
        $this->assertStringContainsString($transaction->uuid, $html, 'gateway reference');
        $this->assertStringContainsString($transaction->reference, $html, 'display reference');
    }

    public function test_the_receipt_does_not_leak_the_raw_user_agent(): void
    {
        $user = User::factory()->create();

        $transaction = $this->pendingFunding($user, [
            'balance_before' => 0,
            'balance_after' => 5000,
            'status' => 'success',
            'meta' => [
                'request_user_agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120 Safari/537.36',
            ],
        ]);

        $html = (new \App\Mail\TransactionReceiptMail($transaction))->render();

        $this->assertStringNotContainsString('Mozilla/5.0', $html);
    }
}
