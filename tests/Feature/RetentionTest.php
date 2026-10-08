<?php

namespace Tests\Feature;

use App\Models\BillReminder;
use App\Models\ProductAlert;
use App\Models\SavedBill;
use App\Models\ServiceCategory;
use App\Models\ServiceOrder;
use App\Models\ServiceProduct;
use App\Models\Transactions;
use App\Models\User;
use App\Models\WalletLedger;
use App\Retention\RetentionService;
use App\Services\WalletService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saved bills, Pay Again and bill reminders.
 *
 * The three properties that carry risk:
 *
 *   * a saved bill belongs to exactly one customer, and every access is scoped to
 *     them — a sequential id must not be enough to read or change it;
 *   * Pay Again creates a **new** order at the **current** price, never a replay
 *     of the old one;
 *   * a reminder **never** debits a wallet.
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private RetentionService $retention;

    protected function setUp(): void
    {
        parent::setUp();

        $this->retention = app(RetentionService::class);
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function savedBill(User $user, array $attributes = []): SavedBill
    {
        return $this->retention->save($user, array_merge([
            'product_key' => 'electricity',
            'label' => 'Home meter',
            'identifier' => '45012345678',
        ], $attributes));
    }

    /**
     * A published product, so Pay Again has something to re-resolve.
     *
     * `payAgainInputs()` deliberately re-reads the product and refuses when it is
     * no longer sellable, so a fixture without one would test the refusal rather
     * than the happy path.
     */
    private function product(string $productKey = 'airtime'): ServiceProduct
    {
        $category = ServiceCategory::firstOrCreate(
            ['slug' => 'pay-bills'],
            ['group' => ServiceCategory::GROUP_PAY_BILLS, 'name' => 'Pay Bills'],
        );

        return ServiceProduct::firstOrCreate(
            ['slug' => $productKey . '-product'],
            [
                'category_id' => $category->getKey(),
                'product_key' => $productKey,
                'name' => ucfirst($productKey),
                'status' => ServiceProduct::STATUS_ACTIVE,
                'availability' => ServiceProduct::AVAILABILITY_AVAILABLE,
                'published_at' => now(),
            ],
        );
    }

    private function successfulOrder(User $user, array $attributes = []): ServiceOrder
    {
        $transaction = Transactions::create(array_merge([
            'user_id' => $user->getKey(),
            'type' => 'debit',
            'service_type' => 'airtime',
            'description' => 'Airtime — MTN',
            'amount' => '1000.00',
            'service_fee' => '0.00',
            'total_amount' => '1000.00',
            'recipient' => '08031234567',
            'status' => 'success',
            'payment_status' => 'success',
        ], $attributes));

        $product = $this->product();

        return ServiceOrder::create([
            'user_id' => $user->getKey(),
            'transaction_id' => $transaction->getKey(),
            'service_product_id' => $product->getKey(),
            'product_key' => 'airtime',
            'recipient' => '08031234567',
            'quantity' => 1,
            'status' => ServiceOrder::STATUS_SUCCESS,
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);
    }

    /* =====================================================================
     | Saved bills — ownership
     |=================================================================== */

    public function test_a_customer_can_save_a_bill(): void
    {
        $user = $this->user();

        $bill = $this->savedBill($user);

        $this->assertSame($user->getKey(), $bill->user_id);
        $this->assertSame('45012345678', $bill->identifier);
        // The masked form is what the list renders.
        $this->assertSame('*******5678', $bill->masked_identifier);
    }

    public function test_the_same_account_saved_twice_updates_rather_than_duplicating(): void
    {
        $user = $this->user();

        $first = $this->savedBill($user, ['label' => 'Home meter']);
        $second = $this->savedBill($user, ['label' => 'Home meter (upstairs)']);

        // The unique index on (user, product, identifier) is what makes this safe.
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame('Home meter (upstairs)', $second->fresh()->label);
        $this->assertSame(1, SavedBill::where('user_id', $user->getKey())->count());
    }

    public function test_a_customer_cannot_read_another_customers_saved_bill(): void
    {
        $owner = $this->user();
        $attacker = $this->user();

        $bill = $this->savedBill($owner, ['identifier' => '99988877766']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        // Scoped on the query, so the attacker's id lookup finds nothing. A 404,
        // not a 403: a 403 would confirm the row exists.
        $this->retention->findForUser($attacker, $bill->getKey());
    }

    public function test_a_customer_cannot_update_another_customers_saved_bill(): void
    {
        $owner = $this->user();
        $attacker = $this->user();

        $bill = $this->savedBill($owner);

        try {
            $this->retention->update($attacker, $bill->getKey(), ['label' => 'Hijacked']);
            $this->fail('An update by a non-owner must be refused.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // expected
        }

        $this->assertSame('Home meter', $bill->fresh()->label);
    }

    public function test_a_customer_cannot_delete_another_customers_saved_bill(): void
    {
        $owner = $this->user();
        $attacker = $this->user();

        $bill = $this->savedBill($owner);

        try {
            $this->retention->delete($attacker, $bill->getKey());
            $this->fail('A delete by a non-owner must be refused.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // expected
        }

        $this->assertTrue($bill->fresh()->is_active);
    }

    public function test_a_customer_cannot_pay_another_customers_saved_bill(): void
    {
        $owner = $this->user();
        $attacker = $this->user();

        $bill = $this->savedBill($owner);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->retention->payAgainPayload($attacker, $bill->getKey());
    }

    public function test_deleting_a_saved_bill_deactivates_its_reminders(): void
    {
        $user = $this->user();
        $bill = $this->savedBill($user);

        $reminder = $this->retention->createReminder($user, [
            'saved_bill_id' => $bill->getKey(),
            'product_key' => 'electricity',
            'title' => 'Electricity',
            'frequency' => 'monthly',
            'starts_at' => now()->addDay(),
        ]);

        $this->retention->delete($user, $bill->getKey());

        // A reminder pointing at a removed account must not keep firing.
        $this->assertFalse($reminder->fresh()->is_active);
    }

    /* =====================================================================
     | Pay Again
     |=================================================================== */

    public function test_pay_again_returns_inputs_and_deliberately_no_price(): void
    {
        $user = $this->user();
        $order = $this->successfulOrder($user);

        $inputs = $this->retention->payAgainInputs($user, $order);

        $this->assertSame('airtime', $inputs['product_key']);
        $this->assertSame('08031234567', $inputs['recipient']);
        $this->assertSame($order->getKey(), $inputs['previous_order_id']);

        // The whole point: no price is carried over. The engine prices it again
        // at checkout, so a price change since the original order is visible
        // before the customer confirms.
        $this->assertNull($inputs['price_minor']);
        $this->assertTrue($inputs['requires_repricing']);
    }

    public function test_pay_again_does_not_replay_the_original_transaction(): void
    {
        $user = $this->user();
        $order = $this->successfulOrder($user);

        $transactionCountBefore = Transactions::count();

        $this->retention->payAgainInputs($user, $order);

        // Resolving the inputs must create nothing. A new order is a new purchase.
        $this->assertSame($transactionCountBefore, Transactions::count());
        $this->assertSame(1, Transactions::count());
    }

    public function test_pay_again_refuses_an_order_that_is_not_the_callers(): void
    {
        $owner = $this->user();
        $attacker = $this->user();

        $order = $this->successfulOrder($owner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not belong');

        $this->retention->payAgainInputs($attacker, $order);
    }

    public function test_pay_again_refuses_an_order_that_did_not_succeed(): void
    {
        $user = $this->user();
        $order = $this->successfulOrder($user);
        $order->forceFill(['status' => ServiceOrder::STATUS_FAILED])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('completed payment');

        $this->retention->payAgainInputs($user, $order);
    }

    public function test_pay_again_lists_only_repeatable_successful_orders(): void
    {
        $user = $this->user();

        $repeatable = $this->successfulOrder($user);
        $failed = $this->successfulOrder($user);
        $failed->forceFill(['status' => ServiceOrder::STATUS_FAILED])->save();

        $rows = $this->retention->payableAgain($user);

        $this->assertCount(1, $rows);
        $this->assertSame($repeatable->getKey(), $rows->first()->getKey());
    }

    /* =====================================================================
     | Reminders — no debits
     |=================================================================== */

    public function test_a_reminder_can_be_created_with_a_frequency(): void
    {
        $user = $this->user();

        $reminder = $this->retention->createReminder($user, [
            'product_key' => 'electricity',
            'title' => 'Electricity bill',
            'amount' => '15000.50',
            'frequency' => 'monthly',
            'starts_at' => now()->addDay(),
        ]);

        $this->assertSame(BillReminder::FREQUENCY_MONTHLY, $reminder->frequency);
        // Stored in kobo, exactly.
        $this->assertSame(1500050, (int) $reminder->amount_minor);
        $this->assertTrue($reminder->is_active);
    }

    public function test_a_reminder_cannot_be_scheduled_in_the_past(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->retention->createReminder($this->user(), [
            'product_key' => 'electricity',
            'title' => 'Yesterday',
            'frequency' => 'once',
            'starts_at' => now()->subDay(),
        ]);
    }

    public function test_a_due_reminder_emits_a_notification_and_moves_forward(): void
    {
        $user = $this->user();

        $reminder = $this->retention->createReminder($user, [
            'product_key' => 'electricity',
            'title' => 'Electricity bill',
            'amount' => '15000',
            'frequency' => 'monthly',
            'starts_at' => now()->addMinute(),
        ]);

        // Move time past the due date.
        $this->travel(2)->minutes();

        $result = $this->retention->emitDueReminders();

        $this->assertSame(1, $result['emitted']);

        $reminder->refresh();

        $this->assertSame(1, $reminder->notification_count);
        $this->assertNotNull($reminder->last_notified_at);
        // Monthly, so the next occurrence is a month out — not one minute.
        $this->assertTrue($reminder->next_due_at->isAfter(now()->addWeeks(3)));

        $alert = ProductAlert::where('bill_reminder_id', $reminder->getKey())->firstOrFail();
        $this->assertSame(ProductAlert::KIND_REMINDER, $alert->kind);
        $this->assertSame($user->getKey(), $alert->user_id);
        // The record states plainly that it is not an authorisation to pay.
        $this->assertFalse($alert->context['payment_required']);
        $this->assertFalse($alert->context['autopay']);
    }

    public function test_a_reminder_never_debits_a_wallet(): void
    {
        $user = $this->user();

        app(WalletService::class)->credit($user, '50000.00');
        $balanceBefore = WalletService::class ? app(WalletService::class)->balanceFor($user) : null;
        $ledgerBefore = WalletLedger::count();

        $this->retention->createReminder($user, [
            'product_key' => 'electricity',
            'title' => 'Electricity bill',
            'amount' => '15000',
            'frequency' => 'monthly',
            'starts_at' => now()->addMinute(),
        ]);

        $this->travel(2)->minutes();

        $this->retention->emitDueReminders();

        /*
         * The assertion the brief asks for. A reminder is a notification; the
         * customer decides whether to pay. If this ever fails, AutoPay has been
         * added by accident.
         */
        $this->assertSame(
            $balanceBefore->toDecimalString(),
            app(WalletService::class)->balanceFor($user)->toDecimalString(),
            'A reminder must not change the wallet balance.'
        );

        $this->assertSame($ledgerBefore, WalletLedger::count(), 'A reminder must not write a ledger entry.');
        $this->assertSame(0, Transactions::count());
    }

    public function test_a_reminder_does_not_fire_twice_for_the_same_due_date(): void
    {
        $user = $this->user();

        $this->retention->createReminder($user, [
            'product_key' => 'electricity',
            'title' => 'Electricity bill',
            'frequency' => 'once',
            'starts_at' => now()->addMinute(),
        ]);

        $this->travel(2)->minutes();

        // Two overlapping scheduler runs.
        $first = $this->retention->emitDueReminders();
        $second = $this->retention->emitDueReminders();

        $this->assertSame(1, $first['emitted']);
        $this->assertSame(0, $second['emitted'], 'A second run must not emit a duplicate.');
        $this->assertSame(1, ProductAlert::reminders()->count());
    }

    public function test_a_one_off_reminder_deactivates_after_firing(): void
    {
        $user = $this->user();

        $reminder = $this->retention->createReminder($user, [
            'product_key' => 'electricity',
            'title' => 'One-off payment',
            'frequency' => 'once',
            'starts_at' => now()->addMinute(),
        ]);

        $this->travel(2)->minutes();
        $this->retention->emitDueReminders();

        $this->assertFalse($reminder->fresh()->is_active);
    }

    public function test_a_reminder_before_its_time_does_not_fire(): void
    {
        $user = $this->user();

        $this->retention->createReminder($user, [
            'product_key' => 'electricity',
            'title' => 'Next week',
            'frequency' => 'monthly',
            'starts_at' => now()->addWeek(),
        ]);

        $result = $this->retention->emitDueReminders();

        $this->assertSame(0, $result['emitted']);
    }

    public function test_an_inactive_reminder_does_not_fire(): void
    {
        $user = $this->user();

        $reminder = $this->retention->createReminder($user, [
            'product_key' => 'electricity',
            'title' => 'Paused',
            'frequency' => 'monthly',
            'starts_at' => now()->addMinute(),
        ]);

        $this->retention->updateReminder($user, $reminder->getKey(), ['is_active' => false]);

        $this->travel(2)->minutes();

        $this->assertSame(0, $this->retention->emitDueReminders()['emitted']);
    }

    public function test_a_customer_cannot_read_or_change_another_customers_reminder(): void
    {
        $owner = $this->user();
        $attacker = $this->user();

        $reminder = $this->retention->createReminder($owner, [
            'product_key' => 'electricity',
            'title' => 'Private',
            'frequency' => 'monthly',
            'starts_at' => now()->addDay(),
        ]);

        foreach ([
            fn () => $this->retention->findReminder($attacker, $reminder->getKey()),
            fn () => $this->retention->updateReminder($attacker, $reminder->getKey(), ['title' => 'Hijacked']),
            fn () => $this->retention->deleteReminder($attacker, $reminder->getKey()),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A non-owner must not reach another customer\'s reminder.');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                // expected
            }
        }

        $this->assertSame('Private', $reminder->fresh()->title);
    }

    public function test_a_reminder_cannot_be_attached_to_another_customers_saved_bill(): void
    {
        $owner = $this->user();
        $attacker = $this->user();

        $bill = $this->savedBill($owner);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->retention->createReminder($attacker, [
            'saved_bill_id' => $bill->getKey(),
            'product_key' => 'electricity',
            'title' => 'Hijacked reminder',
            'frequency' => 'monthly',
            'starts_at' => now()->addDay(),
        ]);
    }

    public function test_month_end_reminders_do_not_skip_a_month(): void
    {
        /*
         * Tested on the model rather than through `createReminder`, because the
         * service refuses a start date in the past and this assertion is about
         * date arithmetic — pinning it to a future January would make the test
         * expire.
         *
         * PHP's plain `addMonth()` on 31 January produces 2 March, which would
         * silently skip February: a monthly reminder would miss a month entirely.
         */
        $reminder = new BillReminder([
            'frequency' => BillReminder::FREQUENCY_MONTHLY,
            'interval' => 1,
        ]);

        $next = $reminder->advanceFrom(\Illuminate\Support\Carbon::parse('2026-01-31 09:00:00'));

        // February has no 31st, so the occurrence clamps rather than rolling over.
        $this->assertSame('2026-02-28', $next->toDateString());

        // A 31st stepping into a 30-day month also clamps rather than rolling
        // into the next month.
        $this->assertSame(
            '2026-04-30',
            $reminder->advanceFrom(\Illuminate\Support\Carbon::parse('2026-03-31 09:00:00'))->toDateString()
        );

        // And a 30th stepping into a 31-day month advances normally.
        $this->assertSame(
            '2026-05-30',
            $reminder->advanceFrom(\Illuminate\Support\Carbon::parse('2026-04-30 09:00:00'))->toDateString()
        );

        // And the same guard applies to a yearly reminder set on 29 February:
        // the following year has no 29th, so the occurrence clamps to the 28th
        // rather than rolling into March.
        $yearly = new BillReminder(['frequency' => BillReminder::FREQUENCY_ANNUALLY, 'interval' => 1]);
        $this->assertSame(
            '2025-02-28',
            $yearly->advanceFrom(\Illuminate\Support\Carbon::parse('2024-02-29 09:00:00'))->toDateString()
        );
    }

    /* =====================================================================
     | The command
     | =================================================================== */

    public function test_the_dispatch_command_reports_and_writes_nothing_on_a_dry_run(): void
    {
        $user = $this->user();

        $this->retention->createReminder($user, [
            'product_key' => 'electricity',
            'title' => 'Due now',
            'frequency' => 'monthly',
            'starts_at' => now()->addMinute(),
        ]);

        $this->travel(2)->minutes();

        $this->artisan('reminders:dispatch', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, ProductAlert::reminders()->count());
    }

    public function test_the_dispatch_command_emits_due_reminders(): void
    {
        $user = $this->user();

        $this->retention->createReminder($user, [
            'product_key' => 'electricity',
            'title' => 'Due now',
            'frequency' => 'monthly',
            'starts_at' => now()->addMinute(),
        ]);

        $this->travel(2)->minutes();

        $this->artisan('reminders:dispatch')
            ->expectsOutput('Sent 1 reminder(s); 0 were already claimed.')
            ->assertExitCode(0);

        $this->assertSame(1, ProductAlert::reminders()->count());
    }

    public function test_the_notification_message_says_no_payment_was_taken(): void
    {
        $user = $this->user();

        $reminder = $this->retention->createReminder($user, [
            'product_key' => 'electricity',
            'title' => 'Electricity bill',
            'amount' => '15000',
            'frequency' => 'once',
            'starts_at' => now()->addMinute(),
        ]);

        $this->travel(2)->minutes();
        $this->retention->emitDueReminders();

        $alert = ProductAlert::where('bill_reminder_id', $reminder->getKey())->firstOrFail();

        /*
         * A customer must never be left wondering whether ReUp has already taken
         * the money, so the message says so explicitly.
         */
        $this->assertStringContainsString('no payment has been taken', $alert->message);
        $this->assertStringContainsString('15,000.00', $alert->message);
    }

    public function test_notification_history_is_scoped_to_the_customer(): void
    {
        $first = $this->user();
        $second = $this->user();

        foreach ([$first, $second] as $user) {
            $this->retention->createReminder($user, [
                'product_key' => 'electricity',
                'title' => 'Bill',
                'frequency' => 'once',
                'starts_at' => now()->addMinute(),
            ]);
        }

        $this->travel(2)->minutes();
        $this->retention->emitDueReminders();

        $this->assertCount(1, $this->retention->notifications($first));
        $this->assertSame($first->getKey(), $this->retention->notifications($first)->first()->user_id);
    }
}
