<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Services\PaystackService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Every *other* way money can reach a wallet must charge the fee too.
 *
 * The funding form is only one of four doors. A customer who transfers to their
 * dedicated virtual account, or whose bank transfer is approved by hand, has
 * funded just as much as one who used a card — and if the fee were only charged
 * on the form path, those routes would silently be the free ones.
 *
 * A second `PaystackService` path deserves its own file rather than an extra case
 * in `FundingPromiseTest`, because these are *different settlement methods*
 * reaching the same wallet. Testing only the one the form uses is how a route
 * gets forgotten.
 */
class FundingFeeCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_coverage';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => self::SECRET,
            'wallet.funding_fee' => ['percentage' => 1.0, 'additional' => 0, 'cap' => null],
        ]);
    }

    private function customer(): User
    {
        return User::factory()->create();
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    private function feeRows(User $user): int
    {
        return Transactions::where('user_id', $user->id)->where('service_type', 'fee')->count();
    }

    /**
     * The number the inbound-transfer payload will name.
     *
     * Set with `forceFill`, not passed to `factory()->create()`: the `dva_*`
     * columns are deliberately not mass-assignable — an account number is not
     * something a request should be able to set — so the factory would silently
     * drop it and the transfer would arrive for an account nobody owns. The first
     * version of this test did exactly that and failed with `unknown_account`,
     * which was the test being wrong rather than the application.
     */
    private function withDedicatedAccount(User $user, string $number = '9876543210'): User
    {
        $user->forceFill(['dva_account_number' => $number, 'dva_bank_name' => 'Wema Bank'])->save();

        return $user->fresh();
    }

    /* =====================================================================
     | Virtual-account transfer
     | =================================================================== */

    public function test_a_virtual_account_transfer_credits_gross_and_charges_the_fee(): void
    {
        /*
         * This settles in `creditDedicatedAccountTransfer`, not in
         * `creditFunding` — a dedicated account has no per-transaction lookup, so
         * it has its own path. That is exactly why it is easy to miss.
         */
        $user = $this->withDedicatedAccount($this->customer());

        $result = app(PaystackService::class)->creditDedicatedAccountTransfer([
            'reference' => 'DVA-TRANSFER-1',
            'amount' => 100000,                  // kobo
            'currency' => 'NGN',
            'authorization' => [
                'channel' => 'dedicated_nuban',
                'receiver_bank_account_number' => '9876543210',
                'sender_bank' => 'GTBank',
            ],
        ]);

        $this->assertSame('credited', $result['status']);

        // ₦1,000 in, ₦10 fee, ₦990 left.
        $this->assertSame('990.00', $this->balanceOf($user), 'A bank transfer must be charged the fee too.');
        $this->assertSame(1, $this->feeRows($user));

        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('1000.00', (string) $wallet->total_funded);

        $verification = app(WalletService::class)->verify($wallet->fresh());
        $this->assertTrue($verification['consistent']);
    }

    public function test_a_replayed_virtual_account_transfer_charges_the_fee_once(): void
    {
        // The gateway retries this webhook until it gets a 2xx.
        $user = $this->withDedicatedAccount($this->customer());

        $payload = [
            'reference' => 'DVA-TRANSFER-2',
            'amount' => 100000,
            'currency' => 'NGN',
            'authorization' => [
                'channel' => 'dedicated_nuban',
                'receiver_bank_account_number' => '9876543210',
            ],
        ];

        app(PaystackService::class)->creditDedicatedAccountTransfer($payload);
        app(PaystackService::class)->creditDedicatedAccountTransfer($payload);
        app(PaystackService::class)->creditDedicatedAccountTransfer($payload);

        $this->assertSame('990.00', $this->balanceOf($user));
        $this->assertSame(1, $this->feeRows($user));
    }

    /* =====================================================================
     | Manual bank-transfer approval
     | =================================================================== */

    public function test_an_administrator_approving_a_transfer_charges_the_fee(): void
    {
        /*
         * Approval is the fallback when a DVA is unavailable: the customer
         * transfers to the shared account and an administrator confirms it. It is
         * still a funding, so it is still charged — otherwise it would be the one
         * route that avoids the fee, and the only one a customer could opt into.
         */
        $admin = User::factory()->superAdmin()->create();
        $user = $this->customer();

        $transfer = Transactions::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding — Bank transfer',
            'amount' => '1000.00',
            'service_fee' => '0.00',
            'total_amount' => '1000.00',
            'recipient' => $user->email,
            'provider' => 'bank_transfer',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'verifying',
            'status' => 'verifying',
            'meta' => ['service_fee' => '10.00'],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.bank-transfers.approve', $transfer), ['remarks' => 'Confirmed'])
            ->assertRedirect();

        $this->assertSame('990.00', $this->balanceOf($user), 'A manually approved transfer must be charged too.');
        $this->assertSame(1, $this->feeRows($user));
        $this->assertSame('charged', $transfer->fresh()->meta['funding_fee_status']);
    }

    /* =====================================================================
     | Card fallback (Bachs) — same crediting routine, so same charge
     | =================================================================== */

    public function test_the_card_fallback_settles_through_the_same_fee_path(): void
    {
        /*
         * `BachsService` hands its validated result to
         * `PaystackService::creditVerifiedFunding()`, which is `creditFunding` —
         * the same method the webhook uses. So the fee is collected on the
         * fallback rail for the same structural reason it is on the primary one.
         *
         * Asserted rather than assumed: a second rail with its own copy of the
         * crediting sequence is precisely how the two drift.
         */
        $user = $this->customer();

        /*
         * `payment_method` is an ENUM (`wallet`, `paystack`, `bank_transfer`,
         * `card`, `ussd`) and `bachs` is not in it — a fallback row records the
         * *method* as `paystack`, which is the card rail the customer chose, and
         * identifies the actual gateway through `meta.gateway`. That is also what
         * `PaymentStatusResolver::gateway()` reads, so it is the field that
         * decides which gateway is asked about the row.
         */
        $funding = Transactions::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding — Card',
            'amount' => '1000.00',
            'service_fee' => '0.00',
            'total_amount' => '1000.00',
            'recipient' => $user->email,
            'provider' => 'bachs',
            'payment_method' => 'paystack',
            'payment_status' => 'pending',
            'status' => 'pending',
            'meta' => ['gateway' => 'bachs', 'service_fee' => '10.00'],
        ]);

        app(PaystackService::class)->creditVerifiedFunding($funding, [
            'channel' => 'card',
            'reference' => 'bachs-ref-1',
            'currency' => 'NGN',
            'gateway_response' => 'succeeded',
            'gateway' => 'bachs',
            'raw' => [],
        ]);

        $this->assertSame('990.00', $this->balanceOf($user), 'The fallback rail must charge the fee as well.');
        $this->assertSame(1, $this->feeRows($user));
    }

    /* =====================================================================
     | The fee is ours, not the gateway's
     | =================================================================== */

    public function test_gateway_charges_are_not_passed_to_the_customer(): void
    {
        /*
         * Requirement 8, asserted rather than described. Paystack's own fee for
         * taking the payment is a cost of doing business; nothing in the funding
         * path derives a customer charge from it.
         *
         * The mechanism: no code reads a gateway fee, and the only customer charge
         * is `config('wallet.funding_fee')`. This pins that by charging with the
         * funding fee at zero and a gateway payload that carries a sizeable
         * `fees` figure — if anything ever started passing gateway charges on,
         * this is where it would show.
         */
        Config::set('wallet.funding_fee', ['percentage' => 0, 'additional' => 0, 'cap' => null]);

        $user = $this->withDedicatedAccount($this->customer());

        $result = app(PaystackService::class)->creditDedicatedAccountTransfer([
            'reference' => 'DVA-FEES-1',
            'amount' => 100000,
            'currency' => 'NGN',
            // Paystack reports its own charge here.
            'fees' => 1500,
            'authorization' => [
                'channel' => 'dedicated_nuban',
                'receiver_bank_account_number' => '9876543210',
            ],
        ]);

        $this->assertSame('credited', $result['status']);
        $this->assertSame(
            '1000.00',
            $this->balanceOf($user),
            'A gateway fee in the payload must not become a customer charge.'
        );
        $this->assertSame(0, $this->feeRows($user), 'No customer fee is configured, so none may be taken.');
    }
}
