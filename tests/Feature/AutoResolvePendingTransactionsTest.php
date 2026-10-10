<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Services\Providers\BillProvider;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviders;
use Tests\TestCase;

/**
 * The every-two-minute pending-transaction sweep.
 *
 * ## What is being tested, and why it is not "does the command run"
 *
 * An unattended command that moves money has three ways to be wrong, and all
 * three are silent:
 *
 *   * it acts on an answer it did not receive — writing a live payment off
 *     because a provider was briefly unreachable;
 *   * it acts twice — crediting a payment the webhook already credited;
 *   * it refunds on a non-answer — giving away an order that was in fact vended.
 *
 * So most of these tests assert what the command *refuses* to do. `--dry-run`
 * gets its own section because its whole value is the claim that it changes
 * nothing, and a claim like that is worth testing rather than documenting.
 */
class AutoResolvePendingTransactionsTest extends TestCase
{
    use FakesProviders;
    use RefreshDatabase;

    private const PAYSTACK_SECRET = 'sk_test_autoresolve';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.secret_key' => self::PAYSTACK_SECRET,
            'services.bachs.enabled' => false,
        ]);
    }

    private function customer(string $balance = '0.00'): User
    {
        $user = User::factory()->create();

        if ($balance !== '0.00') {
            app(WalletService::class)->credit(
                user: $user,
                amount: \App\Support\Money::fromNaira($balance),
                countsAsFunding: true,
                entryType: WalletLedger::ENTRY_CREDIT,
                description: 'Test fixture opening balance',
            );
        }

        return $user->fresh();
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }

    /**
     * A pending row, aged past the sweep's grace window.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function pending(User $user, array $attributes = []): Transactions
    {
        $transaction = Transactions::create(array_merge([
            'user_id' => $user->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding',
            'amount' => 5000,
            'service_fee' => 0,
            'total_amount' => 5000,
            'provider' => 'paystack',
            'payment_method' => 'paystack',
            'payment_status' => 'pending',
            'status' => 'pending',
        ], $attributes));

        $transaction->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

        return $transaction->fresh();
    }

    private function fakeVerify(string $reference, string $status, int $kobo = 500000): void
    {
        /*
         * Matched on `api.paystack.co/*` rather than the more specific
         * `…/transaction/verify/*`. `verify()` calls the path with no trailing
         * segment, so the narrower glob never matches and `Http::fake` falls
         * through to an empty 200 — which the service reads as "Paystack does not
         * recognise this reference". Every assertion then passes for the wrong
         * reason, because `unreachable` and `failed` look alike from the outside.
         */
        Http::fake([
            'api.paystack.co/*' => Http::response([
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
     | Card funding
     | =================================================================== */

    public function test_a_payment_the_gateway_confirms_is_credited(): void
    {
        $customer = $this->customer();
        $transaction = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_ok']]);

        $this->fakeVerify($transaction->gatewayReference(), 'success');

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $this->assertSame('5000.00', $this->balanceOf($customer));
        $this->assertSame('success', $transaction->fresh()->status);
    }

    public function test_running_the_sweep_twice_credits_once(): void
    {
        // The command runs every two minutes, and the webhook may settle a row
        // between two ticks. Settlement has to be idempotent or this is a money
        // printer.
        $customer = $this->customer();
        $transaction = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_twice']]);

        $this->fakeVerify($transaction->gatewayReference(), 'success');

        $this->artisan('payments:auto-resolve')->assertExitCode(0);
        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $this->assertSame('5000.00', $this->balanceOf($customer));
        $this->assertSame(1, Transactions::where('user_id', $customer->id)->where('status', 'success')->where('type', 'credit')->count());
    }

    public function test_a_payment_the_gateway_still_treats_as_live_is_left_alone(): void
    {
        $customer = $this->customer();
        $transaction = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_live']]);

        $this->fakeVerify($transaction->gatewayReference(), 'ongoing');

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $this->balanceOf($customer));
    }

    public function test_an_unreachable_gateway_changes_nothing(): void
    {
        /*
         * The most important refusal in the command. A provider that cannot be
         * reached has told us nothing, and writing a live payment off on that
         * basis is how a customer pays and receives nothing.
         */
        $customer = $this->customer();
        $transaction = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_down']]);

        Http::fake(['api.paystack.co/*' => Http::response(['message' => 'Service unavailable'], 503)]);

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $this->balanceOf($customer));
    }

    public function test_an_abandoned_checkout_is_failed(): void
    {
        $customer = $this->customer();
        $transaction = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_gone']]);

        $this->fakeVerify($transaction->gatewayReference(), 'abandoned');

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame('0.00', $this->balanceOf($customer));
    }

    public function test_an_attempt_that_never_reached_checkout_is_failed_without_a_network_call(): void
    {
        $customer = $this->customer();
        $transaction = $this->pending($customer);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $this->assertSame('failed', $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_bank_transfer_is_left_to_the_webhook(): void
    {
        // A dedicated virtual account has no per-transaction lookup, so the
        // inbound transfer is matched by the gateway's own notification.
        $customer = $this->customer();
        $transaction = $this->pending($customer, [
            'payment_method' => 'bank_transfer',
            'provider' => 'bank_transfer',
            'meta' => ['virtual_account' => ['account_number' => '0123456789']],
        ]);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_row_younger_than_the_grace_window_is_skipped(): void
    {
        // The webhook gets its chance first. Without this the sweep would race
        // the primary settlement path on every freshly started payment.
        $customer = $this->customer();

        $transaction = Transactions::create([
            'user_id' => $customer->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding',
            'amount' => 5000,
            'service_fee' => 0,
            'total_amount' => 5000,
            'provider' => 'paystack',
            'payment_method' => 'paystack',
            'payment_status' => 'pending',
            'status' => 'pending',
            'meta' => ['paystack_access_code' => 'acc_fresh'],
        ]);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_the_grace_window_can_be_removed(): void
    {
        $customer = $this->customer();

        $transaction = Transactions::create([
            'user_id' => $customer->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding',
            'amount' => 5000,
            'service_fee' => 0,
            'total_amount' => 5000,
            'provider' => 'paystack',
            'payment_method' => 'paystack',
            'payment_status' => 'pending',
            'status' => 'pending',
            'meta' => ['paystack_access_code' => 'acc_nograce'],
        ]);

        $this->fakeVerify($transaction->gatewayReference(), 'success');

        $this->artisan('payments:auto-resolve', ['--grace' => 0])->assertExitCode(0);

        $this->assertSame('5000.00', $this->balanceOf($customer));
    }

    public function test_a_service_type_filter_narrows_the_sweep(): void
    {
        $customer = $this->customer();

        $funding = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_f']]);
        $airtime = $this->pending($customer, [
            'type' => 'debit',
            'service_type' => 'airtime',
            'provider' => 'clubkonnect',
            'payment_method' => 'wallet',
            'meta' => [],
        ]);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->artisan('payments:auto-resolve', ['--type' => ['airtime']])->assertExitCode(0);

        // Only the airtime row was in scope, and it has no gateway to ask.
        $this->assertSame('pending', $funding->fresh()->status);
    }

    public function test_the_limit_bounds_one_run(): void
    {
        $customer = $this->customer();

        $first = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_1']]);
        $second = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_2']]);

        // Give the two rows a definite order. Only `created_at` distinguishes
        // them, orderings inside one second are not guaranteed to match
        // insertion order on every engine, and a test that depends on the
        // tie-break would be asserting the database rather than the command.
        $first->forceFill(['created_at' => now()->subMinutes(20)])->saveQuietly();
        $second->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

        $this->fakeVerify($first->gatewayReference(), 'success');

        $this->artisan('payments:auto-resolve', ['--limit' => 1, '--type' => ['funding']])->assertExitCode(0);

        // The oldest row is examined first and settled; the second is still
        // waiting for the next tick.
        $this->assertSame('success', $first->fresh()->status, 'The oldest open row must be the one examined first.');
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame('5000.00', $this->balanceOf($customer), 'Exactly one row may be settled in a run of one.');
    }

    /* =====================================================================
     | Bill purchases
     | =================================================================== */

    private function unknownPurchase(User $user, string $orderStatus): void
    {
        $this->withProviders($this->fakeProvider('fake', ['status' => 'TIMEOUT'], $orderStatus));

        Transactions::create([
            'user_id' => $user->id,
            'reference' => 'BILL-UNKNOWN-1',
            'type' => 'debit',
            'service_type' => 'airtime',
            'description' => 'Airtime',
            'amount' => 1000,
            'service_fee' => 0,
            'total_amount' => 1000,
            'provider' => 'fake',
            'payment_method' => 'wallet',
            'payment_status' => 'processing',
            'status' => 'unknown',
            'meta' => ['unconfirmed' => true, 'requested_provider' => 'fake'],
        ])->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();
    }

    public function test_an_unknown_purchase_the_provider_confirms_is_settled(): void
    {
        $customer = $this->customer('4000.00');
        $this->unknownPurchase($customer, 'success');

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $transaction = Transactions::where('reference', 'BILL-UNKNOWN-1')->firstOrFail();

        $this->assertSame('success', $transaction->status);
        // Already debited at purchase time and now confirmed vended, so the
        // balance does not move again.
        $this->assertSame('4000.00', $this->balanceOf($customer));
    }

    public function test_an_unknown_purchase_the_provider_reports_failed_is_refunded(): void
    {
        $customer = $this->customer('4000.00');
        $this->unknownPurchase($customer, 'failed');

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $transaction = Transactions::where('reference', 'BILL-UNKNOWN-1')->firstOrFail();

        $this->assertSame('failed', $transaction->status);
        $this->assertSame('refunded', $transaction->payment_status);
        $this->assertSame('5000.00', $this->balanceOf($customer), 'The held money must be returned.');
    }

    public function test_an_unknown_purchase_the_provider_cannot_confirm_is_left_held(): void
    {
        /*
         * The refusal that protects a vended order. "The provider says pending"
         * and "the provider cannot answer" are both non-answers, and refunding on
         * one gives away goods the customer received.
         */
        $customer = $this->customer('4000.00');
        $this->unknownPurchase($customer, 'unknown');

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $transaction = Transactions::where('reference', 'BILL-UNKNOWN-1')->firstOrFail();

        $this->assertSame('unknown', $transaction->status);
        $this->assertSame('4000.00', $this->balanceOf($customer));
        $this->assertSame(0, WalletLedger::where('entry_type', WalletLedger::ENTRY_REFUND)->count());
    }

    public function test_a_pending_bill_purchase_is_not_second_guessed(): void
    {
        /*
         * Widening the sweep to `pending`/`processing` purchases would have it
         * act on whichever answer it happened to catch while the provider was
         * still working. The scheduled sweep keeps the narrow default.
         */
        $customer = $this->customer('4000.00');

        $provider = $this->fakeProvider('fake', null, 'failed');
        $this->withProviders($provider);

        $transaction = Transactions::create([
            'user_id' => $customer->id,
            'reference' => 'BILL-PENDING-1',
            'type' => 'debit',
            'service_type' => 'airtime',
            'description' => 'Airtime',
            'amount' => 1000,
            'service_fee' => 0,
            'total_amount' => 1000,
            'provider' => 'fake',
            'payment_method' => 'wallet',
            'payment_status' => 'processing',
            'status' => 'pending',
            'meta' => ['requested_provider' => 'fake'],
        ]);

        $transaction->forceFill(['created_at' => now()->subMinutes(30)])->saveQuietly();

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('4000.00', $this->balanceOf($customer));
        $this->assertSame(0, WalletLedger::where('entry_type', WalletLedger::ENTRY_REFUND)->count());
    }

    /* =====================================================================
     | --dry-run
     | =================================================================== */

    public function test_a_dry_run_credits_nothing(): void
    {
        $customer = $this->customer();
        $transaction = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_dry']]);

        $this->fakeVerify($transaction->gatewayReference(), 'success');

        $this->artisan('payments:auto-resolve', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $this->balanceOf($customer));
    }

    public function test_a_dry_run_fails_nothing(): void
    {
        $customer = $this->customer();
        $transaction = $this->pending($customer);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->artisan('payments:auto-resolve', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->completed_at);
    }

    public function test_a_dry_run_refunds_nothing(): void
    {
        $customer = $this->customer('4000.00');
        $this->unknownPurchase($customer, 'failed');

        $this->artisan('payments:auto-resolve', ['--dry-run' => true])->assertExitCode(0);

        $transaction = Transactions::where('reference', 'BILL-UNKNOWN-1')->firstOrFail();

        $this->assertSame('unknown', $transaction->status);
        $this->assertSame('4000.00', $this->balanceOf($customer));
        $this->assertSame(0, WalletLedger::where('entry_type', WalletLedger::ENTRY_REFUND)->count());
    }

    public function test_a_dry_run_still_asks_the_same_questions(): void
    {
        // A dry run that skipped the provider call would report a different
        // answer from the real run, which is worse than no dry run at all.
        $customer = $this->customer();
        $transaction = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_asks']]);

        $this->fakeVerify($transaction->gatewayReference(), 'success');

        $this->artisan('payments:auto-resolve', ['--dry-run' => true])->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/transaction/verify/'));
    }

    public function test_a_dry_run_reports_success_that_it_would_apply(): void
    {
        /*
         * The whole value of a dry run is that an operator can read what it would
         * do before letting it act, so the report is part of the contract — not
         * just the absence of a write.
         *
         * The output is captured through `Artisan::output()` rather than
         * `expectsOutputToContain()`, which does not exist until Laravel 9 and
         * would report a false pass on this version (Laravel 8's
         * `expectsOutput()` compares to the *first line*).
         */
        $customer = $this->customer();
        $transaction = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_report']]);

        $this->fakeVerify($transaction->gatewayReference(), 'success');

        $exit = \Illuminate\Support\Facades\Artisan::call('payments:auto-resolve', ['--dry-run' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('would credit', $output);
        $this->assertStringContainsString('5,000.00', $output);

        // And it still wrote nothing.
        $this->assertSame('pending', $transaction->fresh()->status);
        $this->assertSame('0.00', $this->balanceOf($customer));
    }

    public function test_the_summary_says_whether_anything_would_change(): void
    {
        $customer = $this->customer();
        $transaction = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_summary']]);

        $this->fakeVerify($transaction->gatewayReference(), 'success');

        \Illuminate\Support\Facades\Artisan::call('payments:auto-resolve', ['--dry-run' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('Would change 1 of 1', $output);
    }

    /* =====================================================================
     | Idempotency and robustness
     | =================================================================== */

    public function test_a_settled_row_is_not_queried_again(): void
    {
        $customer = $this->customer();

        $transaction = $this->pending($customer, ['meta' => ['paystack_access_code' => 'acc_done']]);
        // `pending()` builds a pending row; this one is already final, which is
        // also what the candidate query excludes — so the assertion is that the
        // command does not touch it even when it is named directly.
        $transaction->forceFill(['status' => 'success', 'payment_status' => 'success'])->save();

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->artisan('payments:auto-resolve')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_nothing_pending_reports_cleanly(): void
    {
        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $exit = \Illuminate\Support\Facades\Artisan::call('payments:auto-resolve');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No pending transactions', $output);
        Http::assertNothingSent();
    }

    public function test_the_command_is_scheduled_every_two_minutes(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'payments:auto-resolve'));

        $this->assertCount(1, $events, 'payments:auto-resolve must be scheduled exactly once.');
        $this->assertSame('*/2 * * * *', $events->first()->expression);
    }
}
