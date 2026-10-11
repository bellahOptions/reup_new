<?php

namespace Tests\Feature;

use App\Console\Commands\SendFundingReceipts;
use App\Models\Transactions;
use App\Models\User;
use App\Notifications\FundingReceiptNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Funding receipts.
 *
 * The request was for a job that "checks for successful funding and sends
 * notification to user with receipts". Four claims matter, and they fail
 * independently:
 *
 *   1. a settled funding row produces exactly one receipt, on both channels;
 *   2. it is never sent twice — the row carries the claim;
 *   3. a row that is *not* settled produces nothing, however many times the sweep
 *      runs. This one is the money-losing bug: a receipt for ₦5,000 that was
 *      never actually credited is a customer asking support where their money is,
 *      and the evidence they hold says it arrived;
 *   4. a customer with a stale or corrected phone number still gets the receipt,
 *      because it is an email and nothing about it depends on a gateway.
 */
class FundingReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function funding(User $user, array $attributes = []): Transactions
    {
        /*
         * `created_at` is not mass-assignable — Eloquent guards it — so it is
         * pulled out here and written after the insert. The sweep's whole
         * selection is by age (`--days`, `--grace`), so a test that could not date
         * a row could only ever test the easy case.
         *
         * The default is five minutes old: past the one-minute grace period, so
         * the ordinary case in these tests is a row the sweep will act on.
         */
        $createdAt = $attributes['created_at'] ?? now()->subMinutes(5);
        unset($attributes['created_at']);

        $amount = $attributes['total_amount'] ?? '5000.00';

        $transaction = Transactions::create(array_merge([
            'user_id' => $user->id,
            'reference' => 'FND-' . strtoupper(bin2hex(random_bytes(4))),
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding — Card',
            'amount' => $amount,
            'service_fee' => '0.00',
            'total_amount' => $amount,
            'balance_before' => '1000.00',
            'balance_after' => number_format((float) $amount + 1000, 2, '.', ''),
            'provider' => 'paystack',
            'payment_method' => 'paystack',
            'payment_status' => 'success',
            'status' => 'success',
            'api_reference' => 'PSK-' . strtoupper(bin2hex(random_bytes(4))),
            'payment_reference' => 'PSK-' . strtoupper(bin2hex(random_bytes(4))),
            'paid_at' => now(),
            'completed_at' => now(),
            'meta' => ['channel' => 'card', 'gateway' => 'paystack'],
        ], $attributes));

        if ($createdAt !== null) {
            $transaction->forceFill(['created_at' => $createdAt])->save();
            $transaction->refresh();
        }

        return $transaction;
    }
    /* =====================================================================
     | The sweep
     =================================================================== */

    public function test_a_settled_funding_is_receipted_once(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $transaction = $this->funding($user);

        $this->artisan('payments:send-funding-receipts')->assertExitCode(0);

        Notification::assertSentTo(
            $user,
            FundingReceiptNotification::class,
            fn ($notification) => $notification->transaction->getKey() === $transaction->getKey()
        );

        $this->assertNotNull(
            ($transaction->fresh()->meta ?? [])[SendFundingReceipts::META_KEY] ?? null,
            'The row must carry the claim, or every later run sends the receipt again.'
        );
    }

    public function test_a_second_run_sends_nothing(): void
    {
        /*
         * The sweep runs every five minutes forever, over the same rows. Without
         * the stamp, a customer who funded once would get a receipt every five
         * minutes until they funded again — which is how a useful feature becomes
         * a reason to mark the sender as spam.
         */
        Notification::fake();

        $user = User::factory()->create();
        $this->funding($user);

        $this->artisan('payments:send-funding-receipts')->assertExitCode(0);
        $this->artisan('payments:send-funding-receipts')->assertExitCode(0);
        $this->artisan('payments:send-funding-receipts')->assertExitCode(0);

        Notification::assertSentToTimes($user, FundingReceiptNotification::class, 1);
    }

    public function test_an_unsettled_funding_is_never_receipted(): void
    {
        /*
         * The bug this exists to prevent. A pending row may never be credited —
         * the card may be declined — and a receipt is a document the customer
         * keeps and argues with. Sending one for money that never arrived is worse
         * than sending nothing, because it makes our own record the thing they
         * have to disprove.
         */
        Notification::fake();

        $user = User::factory()->create();

        $this->funding($user, ['status' => 'pending', 'payment_status' => 'pending']);
        $this->funding($user, ['status' => 'failed', 'payment_status' => 'failed']);
        $this->funding($user, ['status' => 'processing', 'payment_status' => 'processing']);

        $this->artisan('payments:send-funding-receipts')->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertSame(0, $this->receiptedCount());
    }

    public function test_a_debit_is_never_receipted_as_funding(): void
    {
        // `service_type = funding` with `type = debit` is a reversal. The sweep
        // only looks at credits, so a reversal cannot be announced as money the
        // customer received.
        Notification::fake();

        $user = User::factory()->create();

        $this->funding($user, ['type' => 'debit', 'description' => 'Funding reversal']);

        $this->artisan('payments:send-funding-receipts')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_funding_younger_than_the_grace_period_is_left_for_the_next_run(): void
    {
        /*
         * `--grace` exists so the receipt cannot overtake the settlement it
         * describes. A row written seconds ago may still be mid-settlement — the
         * webhook has committed the credit but the funding-fee bookkeeping runs
         * after it — and a receipt built from a half-written row would show a
         * balance the wallet does not have.
         */
        Notification::fake();

        $user = User::factory()->create();
        $transaction = $this->funding($user, ['created_at' => now()]);

        $this->artisan('payments:send-funding-receipts')->assertExitCode(0);

        Notification::assertNothingSent();

        // And it is not lost: a minute later it is picked up.
        $transaction->forceFill(['created_at' => now()->subMinutes(2)])->save();

        $this->artisan('payments:send-funding-receipts')->assertExitCode(0);

        Notification::assertSentToTimes($user, FundingReceiptNotification::class, 1);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $transaction = $this->funding($user);

        $this->artisan('payments:send-funding-receipts', ['--dry-run' => true])->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertArrayNotHasKey(
            SendFundingReceipts::META_KEY,
            $transaction->fresh()->meta ?? [],
            'A dry run must not claim a row, or the real run would skip it.'
        );
    }

    public function test_a_failed_send_is_retried_rather_than_claimed(): void
    {
        /*
         * The claim is written *after* the notification is dispatched, and that
         * ordering is the whole reason a mail outage is survivable: the row stays
         * unclaimed, the next run tries again, and the customer eventually gets
         * their receipt. The reverse — claim first, send second — would spend the
         * customer's one chance on a mail server that was down.
         *
         * The failure is injected at the notification channel, which is where a
         * real SMTP outage surfaces, rather than at the command: a mock of the
         * command's own collaborator would prove only that the mock was called.
         */
        $user = User::factory()->create();
        $transaction = $this->funding($user);

        $this->partialMock(\Illuminate\Notifications\ChannelManager::class, function ($mock) {
            $mock->shouldReceive('send')->andThrow(new \RuntimeException('mail server is down'));
        });

        $this->artisan('payments:send-funding-receipts')->assertExitCode(1);

        $this->assertArrayNotHasKey(
            SendFundingReceipts::META_KEY,
            $transaction->fresh()->meta ?? [],
            'A failed send must leave the row unclaimed so the next run retries it.'
        );
    }

    public function test_the_look_back_window_bounds_what_is_examined(): void
    {
        /*
         * This command is deployed onto a database that already holds settled
         * funding rows which were never receipted, because the feature did not
         * exist. Without a window, the first run would post a receipt for every
         * piece of funding in the history — a customer who funded in March getting
         * one in October reads as fraud or as a system that has lost track of
         * time.
         */
        Notification::fake();

        $user = User::factory()->create();

        $old = $this->funding($user, ['created_at' => now()->subDays(40)]);
        $recent = $this->funding($user, ['created_at' => now()->subDays(2)]);

        $this->artisan('payments:send-funding-receipts')->assertExitCode(0);

        Notification::assertSentToTimes($user, FundingReceiptNotification::class, 1);

        $this->assertArrayNotHasKey(SendFundingReceipts::META_KEY, $old->fresh()->meta ?? []);
        $this->assertArrayHasKey(SendFundingReceipts::META_KEY, $recent->fresh()->meta ?? []);
    }

    public function test_the_existing_meta_is_merged_and_not_replaced(): void
    {
        /*
         * `meta` carries the funding-fee audit trail, the gateway and the channel,
         * and `payments:charge-funding-fees` selects rows on exactly those keys. A
         * stamp written as a whole new array would delete them, and the fee sweep
         * would stop finding fees it needs to collect — a silent, financial loss
         * caused by adding a receipt.
         */
        Notification::fake();

        $user = User::factory()->create();

        $transaction = $this->funding($user, [
            'meta' => [
                'channel' => 'dedicated_nuban',
                'gateway' => 'paystack',
                'funding_fee' => '10.00',
                'funding_fee_status' => 'charged',
                'sender_bank' => 'GTBank',
            ],
        ]);

        $this->artisan('payments:send-funding-receipts')->assertExitCode(0);

        $meta = $transaction->fresh()->meta;

        $this->assertSame('charged', $meta['funding_fee_status'] ?? null);
        $this->assertSame('10.00', $meta['funding_fee'] ?? null);
        $this->assertSame('GTBank', $meta['sender_bank'] ?? null);
        $this->assertArrayHasKey(SendFundingReceipts::META_KEY, $meta);
    }

    /* =====================================================================
     | The notification itself
     =================================================================== */

    public function test_the_receipt_is_delivered_on_the_database_channel_first(): void
    {
        /*
         * Order matters, and this asserts the order rather than the membership.
         * Channels are processed in sequence and a throwing channel stops the
         * ones after it, so `['mail', 'database']` would mean a mail outage also
         * loses the record — the answer to "was this customer told?" disappearing
         * exactly when the question is being asked.
         */
        $user = User::factory()->create();
        $transaction = $this->funding($user);

        $channels = (new FundingReceiptNotification($transaction))->via($user);

        $this->assertSame('database', $channels[0]);
        $this->assertContains('mail', $channels);
    }

    public function test_the_database_record_points_at_the_transaction_and_carries_the_amount(): void
    {
        $user = User::factory()->create();
        $transaction = $this->funding($user, ['total_amount' => '2500.00']);

        $payload = (new FundingReceiptNotification($transaction))->toArray($user);

        $this->assertSame('funding_receipt', $payload['type']);
        $this->assertSame($transaction->getKey(), $payload['transaction_id']);
        $this->assertSame($transaction->reference, $payload['reference']);
        $this->assertSame('2500.00', $payload['amount']);
        $this->assertStringContainsString('2,500.00', $payload['message']);
    }

    public function test_the_notification_is_recorded_in_the_notifications_table(): void
    {
        /*
         * The end-to-end proof that the `database` channel works: the table this
         * writes to did not exist until this change, and `User::markTermsNotificationSent()`
         * had been calling `notifications()->create()` against it and throwing.
         *
         * The mail is faked rather than sent — the assertion is about the record,
         * and a test that needed an SMTP server would be a test nobody could run.
         */
        \Illuminate\Support\Facades\Mail::fake();

        $user = User::factory()->create();
        $transaction = $this->funding($user);

        $user->notify(new FundingReceiptNotification($transaction));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'notifiable_type' => User::class,
            'type' => FundingReceiptNotification::class,
        ]);

        $stored = $user->fresh()->notifications()->first();

        $this->assertNotNull($stored);
        $this->assertSame($transaction->reference, $stored->data['reference'] ?? null);
        $this->assertNull($stored->read_at);
    }

    public function test_the_receipt_email_renders_the_figures_from_the_row(): void
    {
        /*
         * The receipt is rendered rather than asserted by eye, because the numbers
         * in it are the reason it exists. `balance_before` and `balance_after` are
         * written by WalletService under a row lock at the instant the money moved,
         * so the email is a record rather than a reconstruction — and a template
         * that reformatted them would be the one place they could disagree with the
         * ledger.
         */
        $user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Obi']);

        $transaction = $this->funding($user, [
            'total_amount' => '1500.50',
            'amount' => '1500.50',
            'balance_before' => '100.00',
            'balance_after' => '1600.50',
            // The shape the webhook writes for a transfer into a dedicated
            // account, so the test exercises the transfer branch of the template
            // rather than the card one.
            'recipient' => '9930000737',
            'meta' => [
                'channel' => 'dedicated_nuban',
                'gateway' => 'paystack',
                'sender_bank' => 'GTBank',
                'sender_name' => 'ADA OBI',
                'narration' => 'Wallet top up',
            ],
        ]);

        $html = (string) $this->view('emails.funding-receipt', [
            'user' => $user,
            'transaction' => $transaction,
        ]);

        $this->assertStringContainsString('Your wallet has been funded', $html);
        $this->assertStringContainsString('₦1,500.50', $html);
        $this->assertStringContainsString('₦100.00', $html);
        $this->assertStringContainsString('₦1,600.50', $html);
        $this->assertStringContainsString($transaction->reference, $html);

        // The transfer details a customer needs when their bank asks: who sent
        // it, their note, and which account it landed in.
        $this->assertStringContainsString('Bank transfer from GTBank', $html);
        $this->assertStringContainsString('Sent by', $html);
        $this->assertStringContainsString('ADA OBI', $html);
        $this->assertStringContainsString('Wallet top up', $html);
        $this->assertStringContainsString('Paid into', $html);
        $this->assertStringContainsString('9930000737', $html);

        // And the one thing a *funding* receipt must not say: a card payment is
        // authorised with the PIN, money arriving in the wallet is not.
        $this->assertStringNotContainsString("Wasn't you?", $html);
    }

    public function test_the_email_subject_carries_the_amount(): void
    {
        // A receipt whose subject does not say how much is a receipt the customer
        // has to open to find out whether it is worth opening.
        $user = User::factory()->create();
        $transaction = $this->funding($user, ['total_amount' => '7700.00']);

        $mail = (new FundingReceiptNotification($transaction))->toMail($user);

        $this->assertStringContainsString('7,700.00', $mail->subject);
        $this->assertSame('emails.funding-receipt', $mail->view);
    }

    public function test_the_sweep_is_scheduled(): void
    {
        /*
         * A command nobody runs is not a feature. The schedule is asserted rather
         * than assumed because the whole point of a cron job is that it is
         * invisible when it works and invisible when it stops — see
         * `schedule:health --check`.
         */
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $entry = $events->first(fn ($event) => str_contains((string) $event->command, 'payments:send-funding-receipts'));

        $this->assertNotNull($entry, 'payments:send-funding-receipts is not scheduled.');
        $this->assertSame('*/5 * * * *', $entry->expression);
    }

    private function receiptedCount(): int
    {
        return Transactions::whereNotNull('meta->' . SendFundingReceipts::META_KEY)->count();
    }
}
